<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JournalEntryType;
use App\Exceptions\HeaderAccountPostingException;
use App\Exceptions\LedgerRuleException;
use App\Exceptions\MissingAccountException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\ProductionBatchMaterial;
use App\Services\AccountingService;
use App\Services\ProductionBatchService;
use App\Support\CompanyClock;
use App\Support\InventoryAccounts;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * INV-11: compare each lot account (111, 1131-1134) with the value of the
 * lots that sit in it, per operating unit, and optionally post the
 * correction.
 *
 *   expected = Σ quantity × unit_cost of the unit's live lots (quantity > 0,
 *              not deleted, available / quarantined / reserved for a sale),
 *              grouped by InventoryAccounts::forLot.
 *   actual   = posted GL balance (debit − credit, ACC-16) of the account on
 *              lines carrying that operating_unit_id.
 *
 * Lots whose value is not on a lot account yet are left out of expected:
 *  - a block attached to a cutter work order (any status): its value sits in
 *    1122 (WIP — cutter) until the cut consumes it;
 *  - a byproduct fill lot weighed in on a cutter order that is not completed:
 *    its value stays in 1122 until completion posts it;
 *  - blocks of a foam batch that is not closed: they may carry a hand-entered
 *    cost, but nothing reaches 1131 until the close apportions the batch cost
 *    and posts it (and the close overwrites that cost).
 * Counting any of them would book the value to 52 now and again when the
 * module posts it.
 *
 * Without --post nothing is written. With --post every unit with a
 * difference of at least 0.01 gets ONE adjustment entry moving each account
 * to its expected balance, the net going to 52 فروقات المخزون والتصنيع.
 * PeriodGuard (ACC-11) applies, and a second run finds nothing to post.
 *
 * Guards and warnings:
 *  - Foam consumption: since W4-05, chemicals issued to a batch draw their
 *    lots down AND post DR 1121 / CR 111 together, so issued batches leave no
 *    gap. A batch closed on a hand-typed material cost (no issued rows, as
 *    every batch was before W4-05) still credits 1121 with nothing posted
 *    out of 111 and no lot drawn down, so 111 and its lots both keep the
 *    consumed value and reconcile leaves them alone. The gap is measured
 *    directly (close credits on 1121 against 111 → 1121 consumption entries,
 *    not the 1121 balance, which overhead also feeds) and reported. --post
 *    refuses a unit only when that gap exists AND 111 runs above its lots,
 *    because then consumed lots were likely written down by hand and the
 *    surplus belongs to 1121, not 52 (remedy: DR 1121 / CR 111 for those
 *    hand-costed closes only; never for a batch whose chemicals were issued,
 *    which is already posted).
 *  - Blocks consumed through a cut-remnant decision with no remnant restocked
 *    before W4-06 (StockLotService::processCutRemnant) left their value on
 *    the lot account with no entry; --post expenses it to 52, and the run
 *    says so. The decision now posts its own entry and zeroes the block.
 *  - Lot-account lines with no operating unit, and lots or lines of units
 *    that are deleted, are shown on an "unattributed" row and warned about,
 *    never posted: no unit entry can correct them.
 *  - 52 and the lot accounts must be postable leaves, checked before
 *    anything is written.
 */
final class ReconcileInventoryAccounts extends Command
{
    public const DESCRIPTION = 'تسوية حسابات المخزون مع قيمة الدفعات';

    /** Account that absorbs the net of every difference. */
    public const VARIANCE_ACCOUNT = '52';

    private const THRESHOLD = 0.01;

    /** WIP accounts shown beside the lot accounts (not reconciled). */
    private const WIP_ACCOUNTS = ['1121', '1122', '1123'];

    /** Cutter order statuses whose outputs (pieces and fill) are posted. */
    private const POSTED_CUTTER_STATUSES = ['completed', 'invoiced'];

    /** Bucket for lines and lots that belong to no listed unit. */
    private const UNATTRIBUTED = '__unattributed';

    protected $signature = 'inventory:reconcile-accounts
        {--post : Post one adjustment entry per unit whose accounts drifted}
        {--date= : Entry date (Y-m-d), defaults to the company today}
        {--unit= : Only this operating unit (id)}';

    protected $description = 'Compare the inventory accounts with the value of the stock lots and optionally post the correction';

    public function handle(AccountingService $accountingService): int
    {
        $unitId = $this->option('unit');
        $date = $this->option('date');

        if ($date !== null && ! $this->isValidDate((string) $date)) {
            $this->error("Invalid --date \"{$date}\"; use Y-m-d.");

            return self::FAILURE;
        }

        $units = OperatingUnit::query()
            ->when($unitId !== null, fn ($query) => $query->whereKey($unitId))
            ->orderBy('name')
            ->get();

        if ($units->isEmpty()) {
            $this->error($unitId !== null ? "No operating unit {$unitId}." : 'No operating units.');

            return self::FAILURE;
        }

        $accountIds = $this->accountIds(InventoryAccounts::LOT_ACCOUNTS);

        $missing = array_diff(InventoryAccounts::LOT_ACCOUNTS, $accountIds->keys()->all());
        if ($missing !== []) {
            $this->error('The chart of accounts lacks '.implode(', ', $missing).'. Seed the chart of accounts first.');

            return self::FAILURE;
        }

        $unitIds = $units->pluck('id')->all();
        // Company-wide runs also gather what belongs to no listed unit.
        $withUnattributed = $unitId === null;
        $expected = $this->expectedBalances($unitIds, $withUnattributed);
        $actual = $this->glBalances($unitIds, $accountIds, $withUnattributed);

        $rows = [];
        $differences = [];
        $buckets = $units->pluck('name', 'id')->all();
        if ($withUnattributed) {
            $buckets[self::UNATTRIBUTED] = '(no unit / deleted unit)';
        }

        foreach ($buckets as $bucket => $label) {
            foreach (InventoryAccounts::LOT_ACCOUNTS as $code) {
                $expectedValue = round($expected[$bucket][$code] ?? 0.0, 4);
                $actualValue = round($actual[$bucket][$code] ?? 0.0, 4);
                $difference = round($expectedValue - $actualValue, 4);

                if ($expectedValue === 0.0 && $actualValue === 0.0) {
                    continue;
                }

                $rows[] = [$label, $code, $this->money($expectedValue), $this->money($actualValue), $this->money($difference)];

                if ($difference !== 0.0) {
                    $differences[$bucket][$code] = ['expected' => $expectedValue, 'actual' => $actualValue, 'difference' => $difference];
                }
            }
        }

        if ($rows === []) {
            $this->info('No inventory value and no inventory balances.');
        } else {
            $this->table(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], $rows);
        }

        $this->reportWip($units, $unitIds);
        $unposted = $this->reportUnpostedFoamConsumption($units, $unitIds);
        $this->reportCutRemnantConsumption($units, $unitIds);

        $unattributed = $differences[self::UNATTRIBUTED] ?? [];
        unset($differences[self::UNATTRIBUTED]);
        if (collect($unattributed)->contains(fn (array $row): bool => abs($row['difference']) >= self::THRESHOLD)) {
            $this->warn('Some inventory value or lot-account lines belong to no unit (or to a deleted unit). They are left out of every unit entry and need a manual correction; the company total of these accounts stays off until then.');
        }

        $drifted = array_filter(
            $differences,
            fn (array $byAccount): bool => collect($byAccount)->contains(fn (array $row): bool => abs($row['difference']) >= self::THRESHOLD),
        );

        if ($drifted === []) {
            $this->info('Inventory accounts match the lots; nothing to post.');

            return self::SUCCESS;
        }

        if (! $this->option('post')) {
            $this->warn(count($drifted).' unit(s) drifted. Nothing was written; run again with --post to post the correction.');

            return self::SUCCESS;
        }

        $blockingAccounts = $this->unpostableAccounts();
        if ($blockingAccounts !== []) {
            $this->error('Nothing was posted: '.implode(' ', $blockingAccounts));

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($units->whereIn('id', array_keys($drifted)) as $unit) {
            $surplus = -($drifted[$unit->id]['111']['difference'] ?? 0.0);
            if ($surplus >= self::THRESHOLD && ($unposted[$unit->id] ?? 0.0) >= self::THRESHOLD) {
                $failed++;
                $this->error(sprintf(
                    '%s: refused — 111 carries %s more than its lots while %s of material consumed by closed foam batches was never posted out of 111. That gap comes only from batches closed on a hand-typed material cost (chemicals issued to a batch already post DR 1121 / CR 111 and draw their lots down), so a surplus here usually means those consumed lots were written down by hand with no entry: that value belongs to 1121, not 52. Post DR 1121 / CR 111 for those hand-costed closes only (the lots are already down, so an entry alone is right; never for a batch whose chemicals were issued, which would post it twice), then run again.',
                    $unit->name,
                    $this->money($surplus),
                    $this->money($unposted[$unit->id]),
                ));

                continue;
            }

            try {
                $entry = DB::transaction(fn () => $this->postAdjustment(
                    $accountingService,
                    $unit,
                    $drifted[$unit->id],
                    $date !== null ? (string) $date : CompanyClock::today(),
                ));
                $this->info("{$unit->name}: posted {$entry->reference}.");
            } catch (LedgerRuleException|HeaderAccountPostingException|MissingAccountException $e) {
                $failed++;
                $this->error("{$unit->name}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print the units' WIP balances beside the lot accounts, for review only.
     *
     * @param  Collection<int, OperatingUnit>  $units
     * @param  list<string>  $unitIds
     */
    private function reportWip(Collection $units, array $unitIds): void
    {
        $wipIds = $this->accountIds(self::WIP_ACCOUNTS);

        if ($wipIds->isEmpty()) {
            return;
        }

        $balances = $this->glBalances($unitIds, $wipIds, false);
        $rows = [];

        foreach ($units as $unit) {
            foreach (self::WIP_ACCOUNTS as $code) {
                $balance = round($balances[$unit->id][$code] ?? 0.0, 4);

                if ($balance !== 0.0) {
                    $rows[] = [$unit->name, $code, $this->money($balance)];
                }
            }
        }

        if ($rows !== []) {
            $this->line('Work in process (not reconciled, shown for review):');
            $this->table(['Unit', 'Account', 'Balance'], $rows);
        }
    }

    /**
     * Material cost that foam closes moved out of 1121 with no matching
     * consumption posted out of 111, per unit (unit id => amount).
     *
     * Measured directly, not from the 1121 balance, which overhead
     * absorption (DR 1121 / CR 54) also feeds:
     *
     *   closed   = net credit on 1121 of entries sourced from a production
     *              batch (the close; a reversal keeps the source and nets out),
     *              leaving out batches that have issued chemicals
     *   consumed = net credit on 111 of entries that also carry a 1121 line
     *              for the same unit (a consumption entry DR 1121 / CR 111),
     *              leaving out the W4-05 issue entries
     *
     * Batches with issued chemicals balance themselves (their issues post
     * DR 1121 / CR 111 and draw the lots down; their close moves the same
     * value on), and their issues may belong to batches still open, so they
     * are left out of both sides: the gap is the hand-costed closes alone.
     * Those never drew lots down, so in the normal case 111 and its lots still
     * agree and reconcile leaves them alone; the gap only matters when 111
     * also runs above its lots (see handle()).
     *
     * @param  Collection<int, OperatingUnit>  $units
     * @param  list<string>  $unitIds
     * @return array<string, float>
     */
    private function reportUnpostedFoamConsumption(Collection $units, array $unitIds): array
    {
        $ids = $this->accountIds(['111', '1121']);

        if (! $ids->has('111') || ! $ids->has('1121')) {
            return [];
        }

        $closed = JournalLine::query()
            ->posted()
            ->where('account_id', $ids['1121'])
            ->whereIn('operating_unit_id', $unitIds)
            ->whereIn('journal_entry_id', JournalEntry::query()
                ->where('source_document_type', 'ProductionBatch')
                ->whereNotIn('source_document_id', ProductionBatchMaterial::query()->select('production_batch_id'))
                ->select('id'))
            ->groupBy('operating_unit_id')
            ->selectRaw('operating_unit_id, SUM(credit) - SUM(debit) AS amount')
            ->toBase()
            ->pluck('amount', 'operating_unit_id');

        $consumed = JournalLine::query()
            ->posted()
            ->where('account_id', $ids['111'])
            ->whereIn('operating_unit_id', $unitIds)
            ->whereNotIn('journal_entry_id', JournalEntry::query()
                ->where('source_document_type', ProductionBatchService::MATERIAL_SOURCE_TYPE)
                ->select('id'))
            ->whereExists(fn (Builder $wip) => $wip
                ->selectRaw('1')
                ->from('journal_lines as wip')
                ->whereColumn('wip.journal_entry_id', 'journal_lines.journal_entry_id')
                ->whereColumn('wip.operating_unit_id', 'journal_lines.operating_unit_id')
                ->where('wip.account_id', $ids['1121']))
            ->groupBy('operating_unit_id')
            ->selectRaw('operating_unit_id, SUM(credit) - SUM(debit) AS amount')
            ->toBase()
            ->pluck('amount', 'operating_unit_id');

        $unposted = [];

        foreach ($units as $unit) {
            $gap = round((float) ($closed[$unit->id] ?? 0) - (float) ($consumed[$unit->id] ?? 0), 4);

            if ($gap >= self::THRESHOLD) {
                $unposted[$unit->id] = $gap;
                $this->warn(sprintf(
                    '%s: %s of material consumed by closed foam batches was never posted out of 111 (batches closed on a hand-typed material cost; issue the chemicals to a batch instead, which draws the lots down and posts DR 1121 / CR 111), so 111 and its lots both still carry it and 1121 carries the credit. Reconcile leaves that alone; --post refuses the unit only if 111 also runs above its lots.',
                    $unit->name,
                    $this->money($gap),
                ));
            }
        }

        return $unposted;
    }

    /**
     * Blocks consumed through a cut-remnant decision with no remnant restocked
     * (StockLotService::processCutRemnant) leave their value on the lot
     * account with no entry. The consumed lot no longer counts as stock, so
     * --post books that value to 52 as a variance; say so before it does.
     *
     * @param  Collection<int, OperatingUnit>  $units
     * @param  list<string>  $unitIds
     */
    private function reportCutRemnantConsumption(Collection $units, array $unitIds): void
    {
        $rows = DB::table('stock_lots')
            ->join('warehouses', 'warehouses.id', '=', 'stock_lots.warehouse_id')
            ->whereNull('stock_lots.deleted_at')
            ->whereIn('warehouses.operating_unit_id', $unitIds)
            ->where('stock_lots.status', 'consumed')
            ->where('stock_lots.quantity', '>', 0)
            ->whereExists(fn (Builder $movements) => $movements
                ->selectRaw('1')
                ->from('inventory_movements')
                ->whereColumn('inventory_movements.stock_lot_id', 'stock_lots.id')
                ->where('inventory_movements.reason', 'cutter_consumption')
                ->where('inventory_movements.reference_document_type', 'StockLot'))
            ->whereNotExists(fn (Builder $remnants) => $remnants
                ->selectRaw('1')
                ->from('stock_lots as remnants')
                ->whereColumn('remnants.remnant_of_lot_id', 'stock_lots.id'))
            ->groupBy('warehouses.operating_unit_id')
            ->selectRaw('warehouses.operating_unit_id, COUNT(*) AS blocks, SUM(stock_lots.quantity * stock_lots.unit_cost) AS value')
            ->get()
            ->keyBy('operating_unit_id');

        foreach ($units as $unit) {
            $row = $rows[$unit->id] ?? null;

            if ($row !== null && (float) $row->value >= self::THRESHOLD) {
                $this->warn(sprintf(
                    '%s: %d block(s) consumed by a cut-remnant decision with no remnant restocked still carry %s on their account with no entry; --post books that value to 52 as a variance.',
                    $unit->name,
                    (int) $row->blocks,
                    $this->money((float) $row->value),
                ));
            }
        }
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<string, string> account_code => id
     */
    private function accountIds(array $codes): Collection
    {
        return Account::query()
            ->whereIn('account_code', $codes)
            ->pluck('id', 'account_code')
            ->mapWithKeys(fn (string $id, string|int $code): array => [(string) $code => $id]);
    }

    /**
     * 52 and every lot account must exist and be a leaf, or postJournal would
     * stop part-way through the units.
     *
     * @return list<string>
     */
    private function unpostableAccounts(): array
    {
        $problems = [];

        foreach ([self::VARIANCE_ACCOUNT, ...InventoryAccounts::LOT_ACCOUNTS] as $code) {
            $account = Account::query()->where('account_code', $code)->first();

            if ($account === null) {
                $problems[] = "Account {$code} is missing from the chart of accounts.";
            } elseif (Account::query()->where('parent_account_id', $account->id)->exists()) {
                $problems[] = "Account {$code} has sub-accounts and takes no postings.";
            }
        }

        return $problems;
    }

    /**
     * One balanced entry: each account moves by its difference, the net of
     * all differences lands on 52 (debit when the ledger carried more than
     * the lots, credit otherwise).
     *
     * @param  array<string, array{expected: float, actual: float, difference: float}>  $byAccount
     */
    private function postAdjustment(AccountingService $accountingService, OperatingUnit $unit, array $byAccount, string $date): JournalEntry
    {
        $lines = [];
        $net = 0.0;

        foreach ($byAccount as $code => $row) {
            $difference = $row['difference'];
            $net = round($net + $difference, 4);

            $lines[] = [
                'account_code' => (string) $code,
                $difference > 0 ? 'debit' : 'credit' => abs($difference),
                'operating_unit_id' => $unit->id,
                'memo' => "expected {$row['expected']}, ledger {$row['actual']}",
            ];
        }

        if ($net !== 0.0) {
            $lines[] = [
                'account_code' => self::VARIANCE_ACCOUNT,
                $net > 0 ? 'credit' : 'debit' => abs($net),
                'operating_unit_id' => $unit->id,
            ];
        }

        return $accountingService->postJournal(
            self::DESCRIPTION,
            $lines,
            'OperatingUnit',
            $unit->id,
            $unit->company_id,
            entryDate: $date,
            entryType: JournalEntryType::Adjustment,
        );
    }

    /**
     * Value of the live lots per unit and lot account.
     *
     * Lots outside the listed units (a warehouse with no unit, or a deleted
     * unit) land in the unattributed bucket when $withUnattributed is set.
     *
     * @param  list<string>  $unitIds
     * @return array<string, array<string, float>>
     */
    private function expectedBalances(array $unitIds, bool $withUnattributed): array
    {
        $lots = DB::table('stock_lots')
            ->join('warehouses', 'warehouses.id', '=', 'stock_lots.warehouse_id')
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'stock_lots.inventory_item_id')
            ->whereNull('stock_lots.deleted_at')
            ->when(! $withUnattributed, fn (Builder $query) => $query->whereIn('warehouses.operating_unit_id', $unitIds))
            ->where('stock_lots.quantity', '>', 0)
            // Blocks of a foam batch that is not closed: no value posted yet.
            ->whereNotExists(fn (Builder $batches) => $batches
                ->selectRaw('1')
                ->from('production_batches')
                ->whereColumn('production_batches.id', 'stock_lots.production_batch_id')
                ->where('production_batches.status', '!=', 'closed'))
            ->whereIn('stock_lots.status', ['available', 'quarantined', 'reserved'])
            // A block attached to a cutter work order already moved to 1122,
            // whatever its status says (PATCH /stock-lots can flip it back to
            // available). Release deletes the row, and the cut zeroes the lot.
            ->whereNotExists(fn (Builder $blocks) => $blocks
                ->selectRaw('1')
                ->from('cutter_work_order_blocks')
                ->whereColumn('cutter_work_order_blocks.stock_lot_id', 'stock_lots.id'))
            // A byproduct fill lot weighed in on a cutter order that is not
            // completed yet: its value is still in 1122 until completion
            // posts it to the lot's account.
            ->whereNotExists(fn (Builder $yields) => $yields
                ->selectRaw('1')
                ->from('byproduct_yields')
                ->join('cutter_work_orders', 'cutter_work_orders.id', '=', 'byproduct_yields.cutter_work_order_id')
                ->whereColumn('byproduct_yields.stock_lot_id', 'stock_lots.id')
                ->whereNotIn('cutter_work_orders.status', self::POSTED_CUTTER_STATUSES))
            ->get(['warehouses.operating_unit_id', 'inventory_items.item_type', 'stock_lots.quantity', 'stock_lots.unit_cost']);

        $balances = [];

        foreach ($lots as $lot) {
            $bucket = in_array($lot->operating_unit_id, $unitIds, true) ? $lot->operating_unit_id : self::UNATTRIBUTED;
            $code = InventoryAccounts::forItemType($lot->item_type);
            $value = round((float) $lot->quantity * (float) $lot->unit_cost, 4);
            $balances[$bucket][$code] = round(($balances[$bucket][$code] ?? 0.0) + $value, 4);
        }

        return $balances;
    }

    /**
     * Posted GL balance (debit − credit) per unit and account. Lines with no
     * operating unit, or of a unit not listed (deleted), land in the
     * unattributed bucket when $withUnattributed is set.
     *
     * @param  list<string>  $unitIds
     * @param  Collection<string, string>  $accountIds  account_code => id
     * @return array<string, array<string, float>>
     */
    private function glBalances(array $unitIds, Collection $accountIds, bool $withUnattributed): array
    {
        $codeById = $accountIds->flip();

        $rows = JournalLine::query()
            ->posted()
            ->whereIn('account_id', $accountIds->values()->all())
            ->when(! $withUnattributed, fn ($query) => $query->whereIn('operating_unit_id', $unitIds))
            ->groupBy('operating_unit_id', 'account_id')
            ->selectRaw('operating_unit_id, account_id, SUM(debit) - SUM(credit) AS balance')
            ->toBase()
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $bucket = in_array($row->operating_unit_id, $unitIds, true) ? $row->operating_unit_id : self::UNATTRIBUTED;
            $code = (string) $codeById[$row->account_id];
            $balances[$bucket][$code] = round(($balances[$bucket][$code] ?? 0.0) + (float) $row->balance, 4);
        }

        return $balances;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
