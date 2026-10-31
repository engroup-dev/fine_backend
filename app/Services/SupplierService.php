<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Entity;
use App\Models\EntityRole;
use App\Models\PurchaseOrder;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\Supplier;
use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W5-07: suppliers as entities — the vendor entity behind a supplier, the
 * duplicate check and the merge of two supplier records.
 */
final class SupplierService
{
    /** Advances to Suppliers: where a purchase order of a supplier without its own account posts. */
    private const ADVANCES_FALLBACK_CODE = '15';

    public function __construct(private readonly EntityService $entityService) {}

    /**
     * Lower-cased, Arabic letter variants unified (أ إ آ → ا, ة → ه, ى → ي),
     * diacritics, tatweel and punctuation dropped, spaces collapsed.
     */
    public static function normalizeName(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name) ?? $name;
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * Upper-cased with spaces, dashes, dots and slashes removed; null when empty.
     */
    public static function normalizeTaxNumber(?string $taxNumber): ?string
    {
        $normalized = mb_strtoupper(preg_replace('/[\s\-.\/]+/u', '', (string) $taxNumber) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Live suppliers of the unit that look like the same party: the same
     * normalized name, the same tax number, or the same linked entity.
     *
     * @return list<array{id: string, name: string, tax_number: string|null, match: string}>
     */
    public function findDuplicates(string $operatingUnitId, ?string $name, ?string $taxNumber, ?string $entityId = null, ?string $exceptId = null): array
    {
        $normalizedName = self::normalizeName($name);
        $normalizedTax = self::normalizeTaxNumber($taxNumber);

        return Supplier::query()
            ->withoutGlobalScope(OperatingUnitScope::class)
            ->with('entity:id,tax_number')
            ->where('operating_unit_id', $operatingUnitId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('name')
            ->get(['id', 'name', 'entity_id'])
            ->map(function (Supplier $supplier) use ($normalizedName, $normalizedTax, $entityId): ?array {
                $supplierTax = $supplier->entity?->tax_number;

                $match = match (true) {
                    $entityId !== null && $supplier->entity_id === $entityId => 'entity',
                    $normalizedTax !== null && self::normalizeTaxNumber($supplierTax) === $normalizedTax => 'tax_number',
                    $normalizedName !== '' && self::normalizeName($supplier->name) === $normalizedName => 'name',
                    default => null,
                };

                return $match === null ? null : [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'tax_number' => $supplierTax,
                    'match' => $match,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The vendor entity of a new supplier: the chosen existing entity (a
     * client's, for example) gains the vendor role, otherwise a new
     * organization entity is created with a primary contact from the
     * supplier's contact and address.
     */
    public function resolveEntityForNewSupplier(?string $entityId, string $name, ?string $taxNumber, ?string $contact, ?string $address, string $operatingUnitId): Entity
    {
        if ($entityId !== null) {
            $entity = Entity::query()->lockForUpdate()->findOrFail($entityId);
            $this->entityService->ensureEntityRole($entity, EntityRoleType::Vendor, $operatingUnitId);

            // A linked entity belongs to someone else too (a client, an
            // employee, another unit's supplier): its tax number is filled in
            // when missing but never overwritten.
            $this->assertTaxNumberFits($entity, $taxNumber);
            if (self::normalizeTaxNumber($taxNumber) !== null && self::normalizeTaxNumber($entity->tax_number) === null) {
                $entity->update(['tax_number' => $taxNumber]);
            }

            return $entity;
        }

        $entity = $this->entityService->createEntityForDomainModel(
            roleType: EntityRoleType::Vendor,
            attributes: [
                'name' => $name,
                'entity_type' => EntityType::Organization,
                'tax_number' => $taxNumber !== '' ? $taxNumber : null,
            ],
            operatingUnitId: $operatingUnitId,
        );

        $contactAttributes = self::contactAttributes($contact, $address);
        if ($contactAttributes !== null) {
            $entity->contacts()->create($contactAttributes);
        }

        return $entity;
    }

    /**
     * The entity of an existing supplier, created on first need for rows that
     * predate the backfill (seeders, direct model creates).
     */
    public function ensureEntity(Supplier $supplier): Entity
    {
        if ($supplier->entity_id !== null && ($entity = Entity::query()->find($supplier->entity_id)) !== null) {
            return $entity;
        }

        $entity = $this->resolveEntityForNewSupplier(null, $supplier->name, null, $supplier->contact, $supplier->address, $supplier->operating_unit_id);
        $supplier->update(['entity_id' => $entity->id]);

        return $entity;
    }

    /**
     * Refuses a tax number that differs from the one already on the entity
     * (SUPPLIER_TAX_NUMBER_CONFLICT). An empty tax number on either side fits.
     */
    public function assertTaxNumberFits(Entity $entity, ?string $taxNumber): void
    {
        $given = self::normalizeTaxNumber($taxNumber);
        $current = self::normalizeTaxNumber($entity->tax_number);

        if ($given !== null && $current !== null && $given !== $current) {
            throw new LedgerRuleException(
                "This party already has tax number {$entity->tax_number}; change it on the party itself, not through the supplier.",
                'SUPPLIER_TAX_NUMBER_CONFLICT',
                ['entity_id' => $entity->id, 'entity_tax_number' => $entity->tax_number],
            );
        }
    }

    /**
     * Whether the entity belongs to anyone besides this supplier: a role
     * other than vendor (a client, an employee), a vendor role in another
     * operating unit, or another live supplier of any unit linked to it.
     * Such an entity keeps its own name and tax number; only the supplier's
     * own private entity follows the supplier's edits.
     */
    public static function entityIsShared(Entity $entity, Supplier $supplier): bool
    {
        $otherRoles = $entity->roles()
            ->where(function ($query) use ($supplier): void {
                $query->where('role_type', '!=', EntityRoleType::Vendor->value)
                    ->orWhereNull('operating_unit_id')
                    ->orWhere('operating_unit_id', '!=', $supplier->operating_unit_id);
            })
            ->exists();

        return $otherRoles || Supplier::query()
            ->withoutGlobalScope(OperatingUnitScope::class)
            ->where('entity_id', $entity->id)
            ->whereKeyNot($supplier->id)
            ->exists();
    }

    /**
     * Drops the entity's vendor role in the unit unless another live supplier
     * of that unit still uses the entity.
     */
    public function releaseVendorRole(?string $entityId, ?string $operatingUnitId, string $exceptSupplierId): void
    {
        if ($entityId === null || $operatingUnitId === null) {
            return;
        }

        $stillVendor = Supplier::query()
            ->withoutGlobalScope(OperatingUnitScope::class)
            ->where('entity_id', $entityId)
            ->where('operating_unit_id', $operatingUnitId)
            ->whereKeyNot($exceptSupplierId)
            ->exists();

        if (! $stillVendor) {
            EntityRole::query()
                ->where('entity_id', $entityId)
                ->where('role_type', EntityRoleType::Vendor->value)
                ->where('operating_unit_id', $operatingUnitId)
                ->delete();
        }
    }

    /**
     * Primary contact attributes from the supplier's free-text contact: an
     * email when it looks like one, a phone when it holds only digits and
     * phone punctuation, the contact name otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function contactAttributes(?string $contact, ?string $address): ?array
    {
        $contact = trim((string) $contact);
        $address = trim((string) $address);

        if ($contact === '' && $address === '') {
            return null;
        }

        $isEmail = $contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = ! $isEmail && $contact !== '' && mb_strlen($contact) <= 50
            && preg_match('/^\+?[0-9\s\-().\/]{5,}$/', $contact) === 1;

        return [
            'contact_name' => ($contact !== '' && ! $isEmail && ! $isPhone) ? $contact : null,
            'email' => $isEmail ? $contact : null,
            'phone' => $isPhone ? $contact : null,
            'address' => $address !== '' ? $address : null,
            'country' => 'LY',
            'is_primary' => true,
        ];
    }

    /**
     * Merges the source supplier into the target and soft-deletes the source.
     *
     * Purchase orders and issued cheques move to the target; payment requests
     * follow their purchase orders. Posted journal lines are never touched:
     * the ledger account that holds the source's postings becomes the
     * target's account, so the lines, the payable settlements (keyed by the
     * account code) and later postings of the moved orders all land on the
     * surviving supplier. When both suppliers have their own account with
     * postings, the merge is refused (SUPPLIER_MERGE_ACCOUNTS): two ledgers
     * cannot be joined without editing posted entries. It is refused too
     * (SUPPLIER_MERGE_OPEN_ORDERS) when an order that is part-way through
     * would clear on a different account than the one it opened on.
     *
     * @return array{supplier: Supplier, moved: array{purchase_orders: int, cheques: int, payment_requests: int, payable_settlements: int}, account_id: string|null}
     */
    public function merge(string $sourceId, string $targetId): array
    {
        if ($sourceId === $targetId) {
            throw new LedgerRuleException('A supplier cannot be merged into itself.', 'SUPPLIER_MERGE_SAME');
        }

        return DB::transaction(function () use ($sourceId, $targetId): array {
            // Both rows are locked in a fixed order so two crossing merges
            // cannot deadlock.
            $locked = Supplier::query()
                ->whereIn('id', [$sourceId, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($sourceId) ?? throw (new ModelNotFoundException)->setModel(Supplier::class, [$sourceId]);
            $target = $locked->get($targetId) ?? throw (new ModelNotFoundException)->setModel(Supplier::class, [$targetId]);

            if ($source->operating_unit_id !== $target->operating_unit_id) {
                throw new LedgerRuleException('Only suppliers of the same operating unit can be merged.', 'SUPPLIER_MERGE_UNIT_MISMATCH');
            }

            $accounts = Account::query()
                ->whereIn('id', array_values(array_filter([$source->account_id, $target->account_id])))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $sourceAccount = $source->account_id !== null ? $accounts->get($source->account_id) : null;
            $targetAccount = $target->account_id !== null ? $accounts->get($target->account_id) : null;
            $sameAccount = $sourceAccount !== null && $sourceAccount->id === $targetAccount?->id;

            $sourceHasPostings = $sourceAccount !== null && $this->accountHasPostings($sourceAccount);
            $targetHasPostings = $targetAccount !== null && $this->accountHasPostings($targetAccount);

            if (! $sameAccount && $sourceHasPostings && $targetHasPostings) {
                throw new LedgerRuleException(
                    'Both suppliers have their own ledger account with postings; settle and close one account before merging.',
                    'SUPPLIER_MERGE_ACCOUNTS',
                    [
                        'source_account_code' => $sourceAccount->account_code,
                        'target_account_code' => $targetAccount->account_code,
                    ],
                );
            }

            // The source's account survives when it carries postings, or when
            // the target has no account of its own.
            $moveAccount = ! $sameAccount && $sourceAccount !== null && ($sourceHasPostings || $targetAccount === null);

            $purchaseOrderIds = PurchaseOrder::query()
                ->withoutGlobalScopes()
                ->where('supplier_id', $source->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            // The orders post to their supplier's account, or to the shared
            // advances account when it has none, and look the account up at
            // every posting. An order with an open balance (paid but not
            // received, or received but not paid) must clear on the account
            // it opened on, so the merge is refused when an affected order
            // holds a balance on any supplier-side account other than the
            // one it will post to after the merge. The order's own entries
            // are read, not its supplier's current account: the account may
            // have been changed while the order was part-way through.
            $accountAfter = self::postingCode($moveAccount ? $sourceAccount : $targetAccount);
            $openOrders = [];

            if (self::postingCode($sourceAccount) !== $accountAfter) {
                $openOrders = $this->ordersOpenOffAccount($purchaseOrderIds, $accountAfter);
            }

            if (self::postingCode($targetAccount) !== $accountAfter) {
                $targetOrderIds = PurchaseOrder::query()
                    ->withoutGlobalScopes()
                    ->where('supplier_id', $target->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');
                $openOrders = [...$openOrders, ...$this->ordersOpenOffAccount($targetOrderIds, $accountAfter)];
            }

            if ($openOrders !== []) {
                throw new LedgerRuleException(
                    'Some purchase orders are part-way through (paid but not received, or received but not paid) and would clear on a different account after the merge. Finish them first.',
                    'SUPPLIER_MERGE_OPEN_ORDERS',
                    ['purchase_order_ids' => $openOrders],
                );
            }

            $now = now();

            DB::table('purchase_orders')
                ->whereIn('id', $purchaseOrderIds)
                ->update([
                    'supplier_id' => $target->id,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);

            // Cheques are audited: each one moves through its model so the
            // payee change lands in the audit log.
            $cheques = Cheque::query()
                ->withoutGlobalScopes()
                ->where('supplier_id', $source->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($cheques as $cheque) {
                $cheque->update(['supplier_id' => $target->id]);
            }
            $chequeIds = $cheques->pluck('id');

            $paymentRequests = $purchaseOrderIds->isEmpty()
                ? 0
                : DB::table('payment_requests')->whereIn('purchase_order_id', $purchaseOrderIds)->count();

            $settlements = $moveAccount
                ? DB::table('payable_settlements')->where('account_code', $sourceAccount->account_code)->count()
                : 0;

            if ($moveAccount) {
                // One owner per account: the source lets go before the target
                // takes it.
                $source->update(['account_id' => null]);
                $target->update(['account_id' => $sourceAccount->id]);
            }

            // The source's entity stops listing as a vendor of this unit unless
            // another live supplier of the unit still uses it.
            if ($source->entity_id !== null && $source->entity_id !== $target->entity_id) {
                $this->releaseVendorRole($source->entity_id, $source->operating_unit_id, $source->id);
            }

            // One audit row on the surviving supplier records what moved, so
            // the purchase orders (not audited themselves) can be traced.
            AuditLog::create([
                'id' => (string) Str::uuid(),
                'user_id' => auth()->id(),
                'operating_unit_id' => app(CurrentUnitContext::class)->id() ?? $target->operating_unit_id,
                'table_name' => $target->getTable(),
                'record_id' => $target->id,
                'action' => 'merged',
                'old_values' => [
                    'merged_supplier_id' => $source->id,
                    'merged_supplier_name' => $source->name,
                    'account_id' => $targetAccount?->id,
                ],
                'new_values' => [
                    'purchase_order_ids' => $purchaseOrderIds->values()->all(),
                    'cheque_ids' => $chequeIds->values()->all(),
                    'account_id' => $moveAccount ? $sourceAccount->id : $targetAccount?->id,
                ],
                'ip_address' => request()->ip(),
            ]);

            $source->delete();

            return [
                'supplier' => $target->fresh(['operatingUnit', 'account', 'entity']),
                'moved' => [
                    'purchase_orders' => $purchaseOrderIds->count(),
                    'cheques' => $chequeIds->count(),
                    'payment_requests' => $paymentRequests,
                    'payable_settlements' => $settlements,
                ],
                'account_id' => $target->account_id,
            ];
        });
    }

    /**
     * The code a purchase order of a supplier with this account posts to:
     * the account itself, or the shared Advances to Suppliers account
     * (PurchaseOrderStateService's fallback) when there is none.
     */
    private static function postingCode(?Account $account): string
    {
        return $account?->account_code ?? self::ADVANCES_FALLBACK_CODE;
    }

    /**
     * The orders whose own journals leave a non-zero balance on a
     * supplier-side account other than $accountCodeAfter.
     *
     * @param  Collection<int, string>  $orderIds
     * @return list<string>
     */
    private function ordersOpenOffAccount(Collection $orderIds, string $accountCodeAfter): array
    {
        $open = array_map('strval', array_keys(self::supplierSideBalancesByOrder($orderIds, $accountCodeAfter)));
        sort($open);

        return $open;
    }

    /**
     * Each order's net balance (debits minus credits) per supplier-side
     * account, read from the order's own journals: the entries whose source
     * is the order or one of its payment requests, reversals included (a
     * reversal keeps its source). The supplier-side accounts are the shared
     * advances account and every account linked to a supplier (any unit,
     * deleted suppliers included): the only accounts PurchaseOrderStateService
     * posts an order's supplier leg to. Zero balances are left out, and so
     * are orders with none.
     *
     * @param  Collection<int, string>  $orderIds
     * @return array<string, array<string, float>> order id => [account code => net]
     */
    public static function supplierSideBalancesByOrder(Collection $orderIds, ?string $exceptAccountCode = null): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        $supplierSideCodes = Account::query()
            ->when($exceptAccountCode !== null, fn ($query) => $query->where('account_code', '!=', $exceptAccountCode))
            ->where(function ($query): void {
                $query->where('account_code', self::ADVANCES_FALLBACK_CODE)
                    ->orWhereIn('id', Supplier::query()->withoutGlobalScopes()->whereNotNull('account_id')->select('account_id'));
            })
            ->pluck('account_code', 'id');

        if ($supplierSideCodes->isEmpty()) {
            return [];
        }

        $balances = [];

        foreach ($orderIds->chunk(500) as $chunk) {
            $requestOrders = DB::table('payment_requests')
                ->whereIn('purchase_order_id', $chunk->all())
                ->pluck('purchase_order_id', 'id');

            $entries = DB::table('journal_entries')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($chunk, $requestOrders): void {
                    $query->where(fn ($q) => $q->where('source_document_type', 'PurchaseOrder')->whereIn('source_document_id', $chunk->all()));
                    if ($requestOrders->isNotEmpty()) {
                        $query->orWhere(fn ($q) => $q->where('source_document_type', 'PaymentRequest')->whereIn('source_document_id', $requestOrders->keys()->all()));
                    }
                })
                ->get(['id', 'source_document_type', 'source_document_id']);

            if ($entries->isEmpty()) {
                continue;
            }

            $orderOfEntry = $entries->mapWithKeys(fn ($entry) => [
                $entry->id => $entry->source_document_type === 'PaymentRequest'
                    ? $requestOrders[$entry->source_document_id]
                    : $entry->source_document_id,
            ]);

            $netByEntryAndAccount = DB::table('journal_lines')
                ->whereIn('account_id', $supplierSideCodes->keys()->all())
                ->whereIn('journal_entry_id', $orderOfEntry->keys()->all())
                ->groupBy('journal_entry_id', 'account_id')
                ->selectRaw('journal_entry_id, account_id, SUM(debit) - SUM(credit) as net')
                ->get();

            foreach ($netByEntryAndAccount as $row) {
                $orderId = (string) $orderOfEntry[$row->journal_entry_id];
                $code = (string) $supplierSideCodes[$row->account_id];
                $balances[$orderId][$code] = round(($balances[$orderId][$code] ?? 0.0) + (float) $row->net, 4);
            }
        }

        foreach ($balances as $orderId => $byAccount) {
            $balances[$orderId] = array_filter($byAccount, fn (float $net): bool => abs($net) >= 0.0001);
        }

        return array_filter($balances);
    }

    /**
     * Any journal line or payable settlement on the account.
     */
    private function accountHasPostings(Account $account): bool
    {
        return DB::table('journal_lines')->where('account_id', $account->id)->exists()
            || DB::table('payable_settlements')->where('account_code', $account->account_code)->exists();
    }
}
