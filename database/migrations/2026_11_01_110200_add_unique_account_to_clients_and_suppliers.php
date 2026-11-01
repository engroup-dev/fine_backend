<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-06: one client per receivable account and one live supplier per
     * payable account. A unique index on each table's account_id backs the
     * validation rule.
     *
     * Suppliers are soft-deleted, and nothing releases a deleted supplier's
     * account (delete keeps it; a merge of two suppliers on one account
     * leaves it on the merged-away source). So only live suppliers count: a
     * partial unique index (WHERE deleted_at IS NULL, valid on SQLite and
     * PostgreSQL) lets a deleted supplier keep its account_id for history
     * while its account can be relinked to a replacement supplier. Clients
     * are never soft-deleted, so a plain unique index covers them.
     *
     * Existing duplicates are refused, not fixed: two parties posting to one
     * ledger account cannot be split apart automatically (the account's
     * history belongs to both), and picking an owner would silently move one
     * party's balance onto the other. The migration therefore checks first
     * and, if any account is shared, stops before changing the schema with a
     * message naming every shared account and the parties on it. Give each
     * party its own account (edit the client or supplier and link another
     * account), or merge suppliers of the same operating unit
     * (POST /suppliers/{id}/merge), then run the migration again.
     */
    public function up(): void
    {
        $conflicts = [...$this->clientConflicts(), ...$this->supplierConflicts()];

        if ($conflicts !== []) {
            throw new RuntimeException(
                "W6-06: cannot add the one-party-per-account unique indexes, these ledger accounts are shared:\n"
                .implode("\n", $conflicts)
                ."\nGive each client/supplier its own account (edit it and link another account), or merge suppliers of the same operating unit (POST /suppliers/{id}/merge), then run the migration again.",
            );
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique('account_id', 'clients_account_id_unique');
        });

        DB::statement('CREATE UNIQUE INDEX suppliers_account_id_unique ON suppliers (account_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_account_id_unique');
        });

        DB::statement('DROP INDEX IF EXISTS suppliers_account_id_unique');
    }

    /**
     * One line per receivable account shared by more than one client.
     *
     * @return list<string>
     */
    private function clientConflicts(): array
    {
        $sharedAccountIds = DB::table('clients')
            ->whereNotNull('account_id')
            ->groupBy('account_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('account_id');

        $lines = [];

        foreach ($sharedAccountIds as $accountId) {
            $clients = DB::table('clients')
                ->leftJoin('entities', 'entities.id', '=', 'clients.entity_id')
                ->where('clients.account_id', $accountId)
                ->orderBy('clients.created_at')
                ->orderBy('clients.id')
                ->get(['clients.id', 'entities.name'])
                ->map(fn (object $client): string => ($client->name ?? '(no name)')." [{$client->id}]")
                ->implode(', ');

            $lines[] = '- account '.$this->accountLabel($accountId).": clients {$clients}";
        }

        return $lines;
    }

    /**
     * One line per payable account shared by more than one live supplier.
     *
     * @return list<string>
     */
    private function supplierConflicts(): array
    {
        $sharedAccountIds = DB::table('suppliers')
            ->whereNotNull('account_id')
            ->whereNull('deleted_at')
            ->groupBy('account_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('account_id');

        $lines = [];

        foreach ($sharedAccountIds as $accountId) {
            $suppliers = DB::table('suppliers')
                ->leftJoin('operating_units', 'operating_units.id', '=', 'suppliers.operating_unit_id')
                ->where('suppliers.account_id', $accountId)
                ->whereNull('suppliers.deleted_at')
                ->orderBy('suppliers.created_at')
                ->orderBy('suppliers.id')
                ->get(['suppliers.id', 'suppliers.name', 'operating_units.name as unit_name'])
                ->map(fn (object $supplier): string => $supplier->name." [{$supplier->id}]".($supplier->unit_name !== null ? " ({$supplier->unit_name})" : ''))
                ->implode(', ');

            $lines[] = '- account '.$this->accountLabel($accountId).": suppliers {$suppliers}";
        }

        return $lines;
    }

    private function accountLabel(string $accountId): string
    {
        $account = DB::table('accounts')->where('id', $accountId)->first(['account_code', 'name']);

        return $account !== null ? "{$account->account_code} {$account->name}" : $accountId;
    }
};
