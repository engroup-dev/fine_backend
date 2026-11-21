<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\LedgerRuleException;
use App\Models\CashAccount;
use App\Models\JournalLine;
use App\Services\TreasuryOpeningBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TRS-01: before v1.14 the opening balance typed on a new treasury was stored
 * but never posted, so those treasuries show 0 in the ledger. This posts the
 * missing openings once. A treasury is skipped, and listed, whenever posting
 * could double-count: its ledger account already has postings (someone may
 * have entered the opening by hand) or is shared with another treasury.
 */
final class PostTreasuryOpenings extends Command
{
    protected $signature = 'ledger:post-treasury-openings
        {--date= : Entry date of the opening entries (YYYY-MM-DD), normally the go-live date}
        {--fx=* : Exchange rate per foreign currency, e.g. --fx=USD=4.85}
        {--dry-run : List what would be posted without writing}';

    protected $description = 'Post the stored opening balances of existing treasuries to the ledger';

    public function handle(TreasuryOpeningBalanceService $openings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $date = $this->option('date');

        if (! $dryRun && ! is_string($date)) {
            $this->error('Pass --date=YYYY-MM-DD (the date the opening balances are as of), or use --dry-run first.');

            return self::FAILURE;
        }

        $rates = $this->rates();
        if ($rates === null) {
            return self::FAILURE;
        }

        $treasuries = CashAccount::with('account')->where('balance', '!=', 0)->orderBy('name')->get();
        $sharedAccountIds = CashAccount::query()
            ->whereNotNull('account_id')
            ->groupBy('account_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('account_id')
            ->all();

        $rows = [];
        $posted = 0;
        $skipped = 0;

        foreach ($treasuries as $treasury) {
            $currency = strtoupper((string) $treasury->currency);
            $reason = $this->skipReason($treasury, $openings, $sharedAccountIds, $currency, $rates);

            if ($reason !== null) {
                $skipped++;
                $rows[] = [$treasury->name, $currency, (float) $treasury->balance, 'skipped', $reason];

                continue;
            }

            if ($dryRun) {
                $rows[] = [$treasury->name, $currency, (float) $treasury->balance, 'would post', ''];

                continue;
            }

            try {
                $entry = DB::transaction(fn () => $openings->post($treasury, $rates[$currency] ?? null, $date));
                $posted++;
                $rows[] = [$treasury->name, $currency, (float) $treasury->balance, 'posted', (string) $entry?->reference];
            } catch (LedgerRuleException $e) {
                $skipped++;
                $rows[] = [$treasury->name, $currency, (float) $treasury->balance, 'failed', "{$e->errorCode}: {$e->getMessage()}"];
            } catch (Throwable $e) {
                $skipped++;
                $rows[] = [$treasury->name, $currency, (float) $treasury->balance, 'failed', $e->getMessage()];
            }
        }

        if ($rows === []) {
            $this->info('No treasury has an opening balance to post.');

            return self::SUCCESS;
        }

        $this->table(['Treasury', 'Currency', 'Opening balance', 'Result', 'Detail'], $rows);
        $this->line($dryRun ? 'Dry run: nothing was written.' : "Posted {$posted}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $sharedAccountIds
     * @param  array<string, float>  $rates
     */
    private function skipReason(CashAccount $treasury, TreasuryOpeningBalanceService $openings, array $sharedAccountIds, string $currency, array $rates): ?string
    {
        if ($treasury->account_id === null) {
            return 'not linked to a ledger account';
        }

        if ($openings->hasOpening($treasury)) {
            return 'opening already posted';
        }

        if (in_array($treasury->account_id, $sharedAccountIds, true)) {
            return 'ledger account shared with another treasury; post the opening by hand';
        }

        if (JournalLine::query()->where('account_id', $treasury->account_id)->exists()) {
            return 'ledger account already has postings; check whether the opening was entered by hand';
        }

        if ($currency !== 'LYD' && ! isset($rates[$currency])) {
            return "no rate given; pass --fx={$currency}=<rate>";
        }

        return null;
    }

    /**
     * @return array<string, float>|null
     */
    private function rates(): ?array
    {
        $rates = [];

        foreach ((array) $this->option('fx') as $pair) {
            if (preg_match('/^([A-Za-z]{3})=(\d+(?:\.\d+)?)$/', (string) $pair, $m) !== 1 || (float) $m[2] <= 0) {
                $this->error("Invalid --fx value '{$pair}'. Use --fx=USD=4.85.");

                return null;
            }

            $rates[strtoupper($m[1])] = (float) $m[2];
        }

        return $rates;
    }
}
