<?php

declare(strict_types=1);

use App\Enums\TreasuryTransferStatus;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\TreasuryTransfer;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\TreasuryBalanceService;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $makeUser = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        RolePermissions::grantDefaults($role);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');
    $this->treasuryOfficer = $makeUser('treasury-officer', $this->unit->id);
    $this->treasuryOfficer2 = $makeUser('treasury-officer', $this->unit->id);
    $this->cashier = $makeUser('pos-cashier', $this->unit->id);
    $this->storeManager = $makeUser('store-manager', $this->unit->id);

    $this->treasuryA = linkedTreasury($this->unit, '121101');
    $this->treasuryB = linkedTreasury($this->unit, '121102');

    $this->accounting = app(AccountingService::class);
    $this->balanceService = app(TreasuryBalanceService::class);

    $this->actAs = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('create records a draft transfer with TRF-YYYY-NNNNN number and posts nothing', function (): void {
    $payload = [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 150.0,
        'transfer_date' => '2026-10-15',
        'notes' => 'Float replenishment',
        'operating_unit_id' => $this->unit->id,
    ];

    $response = ($this->actAs)($this->treasuryOfficer)
        ->postJson('/api/v1/treasury-transfers', $payload)
        ->assertStatus(201);

    $transferId = $response->json('data.id');
    $transfer = TreasuryTransfer::findOrFail($transferId);

    expect($transfer->status)->toBe(TreasuryTransferStatus::Draft)
        ->and($transfer->transfer_number)->toStartWith('TRF-2026-')
        ->and((float) $transfer->amount)->toBe(150.0)
        ->and($transfer->currency)->toBe('LYD')
        ->and($transfer->amount_lyd)->toBeNull()
        ->and($transfer->transfer_date->toDateString())->toBe('2026-10-15')
        ->and($transfer->notes)->toBe('Float replenishment')
        ->and($transfer->journal_entry_id)->toBeNull();

    $response->assertJsonPath('data.transfer_number', $transfer->transfer_number)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.status_label', 'بانتظار الاعتماد')
        ->assertJsonPath('data.from_cash_account.id', $this->treasuryA->id)
        ->assertJsonPath('data.from_cash_account.name', $this->treasuryA->name)
        ->assertJsonPath('data.to_cash_account.id', $this->treasuryB->id)
        ->assertJsonPath('data.created_by.id', $this->treasuryOfficer->id)
        ->assertJsonPath('data.decided_by', null)
        ->assertJsonPath('data.journal_entry', null);

    // Verify nothing posted
    expect(JournalEntry::where('source_document_type', 'TreasuryTransfer')->count())->toBe(0);
});

test('approve by a second user posts DR to / CR from on transfer_date and both live balances move', function (): void {
    // 1. Fund Treasury A with 500 LYD
    $this->accounting->postJournal('Cash deposit into Treasury A', [
        ['account_code' => '121101', 'debit' => 500.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    expect($this->balanceService->balanceFor($this->treasuryA)['ledger_balance'])->toBe(500.0)
        ->and($this->balanceService->balanceFor($this->treasuryB)['ledger_balance'])->toBe(0.0);

    // 2. Creator creates draft transfer
    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 200.0,
        'transfer_date' => '2026-10-18',
        'notes' => 'Transfer to cutter till',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $transferId = $response->json('data.id');

    // 3. Second user (accountant) approves
    $approveResponse = ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve", [
            'note' => 'Approved by accounting manager',
        ])
        ->assertOk();

    $transfer = TreasuryTransfer::findOrFail($transferId);

    expect($transfer->status)->toBe(TreasuryTransferStatus::Approved)
        ->and($transfer->decided_by_user_id)->toBe($this->accountant->id)
        ->and($transfer->decision_note)->toBe('Approved by accounting manager')
        ->and($transfer->decided_at)->not->toBeNull()
        ->and((float) $transfer->amount_lyd)->toBe(200.0)
        ->and($transfer->journal_entry_id)->not->toBeNull();

    $approveResponse->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.status_label', 'معتمد')
        ->assertJsonPath('data.decided_by.id', $this->accountant->id)
        ->assertJsonPath('data.decision_note', 'Approved by accounting manager');

    // 4. Verify Journal Entry
    $entry = JournalEntry::where('source_document_type', 'TreasuryTransfer')
        ->where('source_document_id', $transfer->id)
        ->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-10-18')
        ->and($entry->description)->toBe("تحويل {$transfer->transfer_number} من {$this->treasuryA->name} إلى {$this->treasuryB->name}");

    $lines = $entry->lines()->orderBy('id')->get();
    expect($lines)->toHaveCount(2);

    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    // DR to-treasury (121102) / CR from-treasury (121101)
    expect($debitLine->account->account_code)->toBe('121102')
        ->and((float) $debitLine->debit)->toBe(200.0)
        ->and($debitLine->operating_unit_id)->toBe($this->unit->id)
        ->and($creditLine->account->account_code)->toBe('121101')
        ->and((float) $creditLine->credit)->toBe(200.0)
        ->and($creditLine->operating_unit_id)->toBe($this->unit->id);

    // 5. Live balances moved
    expect($this->balanceService->balanceFor($this->treasuryA)['ledger_balance'])->toBe(300.0)
        ->and($this->balanceService->balanceFor($this->treasuryB)['ledger_balance'])->toBe(200.0);
});

test('self-approval is refused unless the user is owner', function (): void {
    $this->accounting->postJournal('Seed balance', [
        ['account_code' => '121101', 'debit' => 500.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    // Treasury officer creates transfer
    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 100.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $transferId = $response->json('data.id');

    // Treasury officer tries to approve their own transfer -> 422 TRANSFER_SELF_APPROVAL
    ($this->actAs)($this->treasuryOfficer)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'TRANSFER_SELF_APPROVAL');

    // Owner creates transfer
    $ownerResponse = ($this->actAs)($this->owner)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 50.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $ownerTransferId = $ownerResponse->json('data.id');

    // Owner approves their own transfer -> 200 OK
    ($this->actAs)($this->owner)
        ->postJson("/api/v1/treasury-transfers/{$ownerTransferId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

test('insufficient balance is refused with available context', function (): void {
    // Treasury A has only 100 LYD
    $this->accounting->postJournal('Seed balance', [
        ['account_code' => '121101', 'debit' => 100.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 100.0, 'operating_unit_id' => $this->unit->id],
    ]);

    // Create transfer for 250 LYD
    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 250.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $transferId = $response->json('data.id');

    // Second user attempts to approve
    $approveResponse = ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertStatus(422);

    $approveResponse->assertJsonPath('code', 'TRANSFER_INSUFFICIENT_BALANCE');
    expect((float) $approveResponse->json('available'))->toBe(100.0);

    // Verify transfer remains draft and no entry posted
    expect(TreasuryTransfer::find($transferId)->status)->toBe(TreasuryTransferStatus::Draft)
        ->and(JournalEntry::where('source_document_type', 'TreasuryTransfer')->count())->toBe(0);
});

test('same treasury and currency mismatch are refused at creation', function (): void {
    // Same treasury
    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryA->id,
        'amount' => 100.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'TRANSFER_SAME_TREASURY');

    // Currency mismatch (LYD to USD)
    $usdTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'USD Safe',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121105')->sole()->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $usdTreasury->id,
        'amount' => 100.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'TRANSFER_CURRENCY_MISMATCH');
    expect($response->json('message'))->toContain('currency exchange is done through procurement FX');
});

test('USD to USD transfer stamps currency on both lines and needs fx_rate', function (): void {
    $usdTreasuryA = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'USD Treasury A',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121104')->sole()->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    $usdTreasuryB = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'USD Treasury B',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121105')->sole()->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    // Fund USD Treasury A: 100 USD @ 4.8 = 480 LYD
    $this->accounting->postJournal('USD Deposit', [
        [
            'account_code' => '121104',
            'debit' => 480.0,
            'credit' => 0.0,
            'currency' => 'USD',
            'original_amount' => 100.0,
            'fx_rate' => 4.8,
            'operating_unit_id' => $this->unit->id,
        ],
        [
            'account_code' => '40201',
            'debit' => 0.0,
            'credit' => 480.0,
            'operating_unit_id' => $this->unit->id,
        ],
    ]);

    // Create draft USD transfer without fx_rate
    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $usdTreasuryA->id,
        'to_cash_account_id' => $usdTreasuryB->id,
        'amount' => 40.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $transferId = $response->json('data.id');

    // Attempt approve without fx_rate -> 422 FX_RATE_REQUIRED
    ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'FX_RATE_REQUIRED');

    // Approve providing fx_rate = 5.0
    $approveResponse = ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve", [
            'fx_rate' => 5.0,
        ])
        ->assertOk();

    $approveResponse->assertJsonPath('data.amount_lyd', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('data.fx_rate', fn ($v): bool => (float) $v === 5.0)
        ->assertJsonPath('data.currency', 'USD');

    $entry = JournalEntry::where('source_document_type', 'TreasuryTransfer')
        ->where('source_document_id', $transferId)
        ->sole();

    $lines = $entry->lines()->orderBy('id')->get();
    expect($lines)->toHaveCount(2);

    $toLine = $lines->firstWhere('account_id', Account::where('account_code', '121105')->value('id'));
    $fromLine = $lines->firstWhere('account_id', Account::where('account_code', '121104')->value('id'));

    expect((float) $toLine->debit)->toBe(200.0)
        ->and($toLine->currency)->toBe('USD')
        ->and((float) $toLine->original_amount)->toBe(40.0)
        ->and((float) $toLine->fx_rate)->toBe(5.0)
        ->and((float) $fromLine->credit)->toBe(200.0)
        ->and($fromLine->currency)->toBe('USD')
        ->and((float) $fromLine->original_amount)->toBe(40.0)
        ->and((float) $fromLine->fx_rate)->toBe(5.0);

    // Verify USD live balances
    $balanceA = $this->balanceService->balanceFor($usdTreasuryA);
    $balanceB = $this->balanceService->balanceFor($usdTreasuryB);

    expect((float) $balanceA['currency_balances'][0]['original_balance'])->toBe(60.0)
        ->and((float) $balanceB['currency_balances'][0]['original_balance'])->toBe(40.0);
});

test('reject and cancel post nothing and are terminal', function (): void {
    // 1. Rejection
    $res1 = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 100.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);
    $transfer1Id = $res1->json('data.id');

    // Rejecting requires a note
    ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transfer1Id}/reject", [])
        ->assertStatus(422);

    ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transfer1Id}/reject", ['note' => 'Not needed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.status_label', 'مرفوض')
        ->assertJsonPath('data.decision_note', 'Not needed');

    expect(TreasuryTransfer::find($transfer1Id)->status)->toBe(TreasuryTransferStatus::Rejected)
        ->and(JournalEntry::where('source_document_id', $transfer1Id)->count())->toBe(0);

    // Terminal: cannot approve, reject, or cancel rejected transfer
    ($this->actAs)($this->accountant)->postJson("/api/v1/treasury-transfers/{$transfer1Id}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');
    ($this->actAs)($this->accountant)->postJson("/api/v1/treasury-transfers/{$transfer1Id}/reject", ['note' => 'again'])
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');
    ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/treasury-transfers/{$transfer1Id}/cancel")
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');

    // 2. Cancellation
    $res2 = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 80.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);
    $transfer2Id = $res2->json('data.id');

    // Another non-owner user cannot cancel -> 403
    ($this->actAs)($this->treasuryOfficer2)
        ->postJson("/api/v1/treasury-transfers/{$transfer2Id}/cancel")
        ->assertStatus(403)
        ->assertJsonPath('code', 'TRANSFER_CANCEL_FORBIDDEN');

    // Creator cancels -> 200 OK
    ($this->actAs)($this->treasuryOfficer)
        ->postJson("/api/v1/treasury-transfers/{$transfer2Id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.status_label', 'ملغى');

    expect(TreasuryTransfer::find($transfer2Id)->status)->toBe(TreasuryTransferStatus::Cancelled)
        ->and(JournalEntry::where('source_document_id', $transfer2Id)->count())->toBe(0);

    // Terminal: cannot approve, reject, or cancel cancelled transfer
    ($this->actAs)($this->accountant)->postJson("/api/v1/treasury-transfers/{$transfer2Id}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');
    ($this->actAs)($this->accountant)->postJson("/api/v1/treasury-transfers/{$transfer2Id}/reject", ['note' => 'again'])
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');
    ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/treasury-transfers/{$transfer2Id}/cancel")
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_NOT_PENDING');
});

test('approving into a locked month -> PERIOD_LOCKED and the transfer stays draft', function (): void {
    // Post an entry dated in September to provision FY2026 and period 2026-09
    $this->accounting->postJournal('Seed September', [
        ['account_code' => '121101', 'debit' => 500.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-09-15');

    // Create a draft transfer dated in September
    $res = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 100.0,
        'transfer_date' => '2026-09-20',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $transferId = $res->json('data.id');

    // Lock September
    $period = FiscalPeriod::query()
        ->where('calendar_year', 2026)
        ->where('calendar_month', 9)
        ->sole();

    ($this->actAs)($this->accountant)
        ->postJson("/api/v1/fiscal-periods/{$period->id}/lock", ['include_earlier' => true])
        ->assertOk();

    // Now attempt to approve the backdated transfer
    $response = ($this->actAs)($this->accountant)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertStatus(422);

    $response->assertJsonPath('code', 'PERIOD_LOCKED');

    // The transfer row remains draft and no journal entry was posted
    expect(TreasuryTransfer::find($transferId)->status)->toBe(TreasuryTransferStatus::Draft)
        ->and(JournalEntry::where('source_document_type', 'TreasuryTransfer')->count())->toBe(0);
});

test('permissions: pos-cashier cannot create, treasury-officer can approve another user transfer, store-manager cannot approve', function (): void {
    // 1. pos-cashier cannot create transfer
    ($this->actAs)($this->cashier)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 50.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(403);

    // 2. Fund Treasury A and accountant creates transfer
    $this->accounting->postJournal('Seed balance', [
        ['account_code' => '121101', 'debit' => 500.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $res = ($this->actAs)($this->accountant)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 50.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);
    $transferId = $res->json('data.id');

    // 3. store-manager cannot approve -> 403 TRANSFER_APPROVAL_FORBIDDEN
    ($this->actAs)($this->storeManager)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertStatus(403)
        ->assertJsonPath('code', 'TRANSFER_APPROVAL_FORBIDDEN');

    // 4. treasury-officer CAN approve another user's transfer (has approve-treasury permission)
    ($this->actAs)($this->treasuryOfficer)
        ->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

test('index filters by status, cash_account_id, date range half-open, and search', function (): void {
    $t1 = TreasuryTransfer::create([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'TRF-2026-00001',
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 100.0,
        'currency' => 'LYD',
        'transfer_date' => '2026-10-10',
        'notes' => 'First branch note',
        'status' => TreasuryTransferStatus::Draft,
        'created_by_user_id' => $this->treasuryOfficer->id,
        'record_version' => 1,
    ]);

    $t2 = TreasuryTransfer::create([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'TRF-2026-00002',
        'from_cash_account_id' => $this->treasuryB->id,
        'to_cash_account_id' => $this->treasuryA->id,
        'amount' => 200.0,
        'currency' => 'LYD',
        'transfer_date' => '2026-10-15',
        'notes' => 'Second central note',
        'status' => TreasuryTransferStatus::Approved,
        'created_by_user_id' => $this->treasuryOfficer->id,
        'record_version' => 1,
    ]);

    // Status filter
    $resStatus = ($this->actAs)($this->owner)->getJson('/api/v1/treasury-transfers?status=approved')->assertOk();
    expect($resStatus->json('data'))->toHaveCount(1)
        ->and($resStatus->json('data.0.id'))->toBe($t2->id);

    // Cash account filter (either side matches)
    $resAccount = ($this->actAs)($this->owner)->getJson("/api/v1/treasury-transfers?cash_account_id={$this->treasuryA->id}")->assertOk();
    expect($resAccount->json('data'))->toHaveCount(2);

    // Date range half-open: from 2026-10-10 to 2026-10-14 should only return t1 (2026-10-10)
    $resDate = ($this->actAs)($this->owner)->getJson('/api/v1/treasury-transfers?from=2026-10-10&to=2026-10-14')->assertOk();
    expect($resDate->json('data'))->toHaveCount(1)
        ->and($resDate->json('data.0.id'))->toBe($t1->id);

    // Search filter
    $resSearch = ($this->actAs)($this->owner)->getJson('/api/v1/treasury-transfers?search=central')->assertOk();
    expect($resSearch->json('data'))->toHaveCount(1)
        ->and($resSearch->json('data.0.id'))->toBe($t2->id);

    // Show endpoint
    ($this->actAs)($this->owner)->getJson("/api/v1/treasury-transfers/{$t1->id}")
        ->assertOk()
        ->assertJsonPath('data.transfer_number', 'TRF-2026-00001');
});

test('a company-wide approver pinned to another unit sees and approves the transfer', function (): void {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Showroom',
        'code' => 'SHOW-01',
        'unit_type' => 'retail',
        'status' => 'active',
    ]);
    $from = linkedTreasury($otherUnit, '121103');
    $to = linkedTreasury($otherUnit, '121104');

    $this->accounting->postJournal('Seed balance', [
        ['account_code' => '121103', 'debit' => 500.0, 'operating_unit_id' => $otherUnit->id],
        ['account_code' => '40201', 'credit' => 500.0, 'operating_unit_id' => $otherUnit->id],
    ]);

    $transferId = $this->actingAs($this->owner)->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id])
        ->postJson('/api/v1/treasury-transfers', [
            'from_cash_account_id' => $from->id,
            'to_cash_account_id' => $to->id,
            'amount' => 120.0,
            'operating_unit_id' => $otherUnit->id,
        ])->assertStatus(201)->json('data.id');

    // The accountant's top bar is pinned to the first unit.
    $pinned = fn () => $this->actingAs($this->accountant)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    expect(collect($pinned()->getJson('/api/v1/treasury-transfers')->assertOk()->json('data'))->pluck('id'))
        ->not->toContain($transferId)
        ->and(collect($pinned()->getJson('/api/v1/treasury-transfers?company_wide=1')->assertOk()->json('data'))->pluck('id'))
        ->toContain($transferId);

    $pinned()->getJson("/api/v1/treasury-transfers/{$transferId}")->assertOk();
    $pinned()->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // A unit-scoped officer of the first unit still cannot reach it.
    ($this->actAs)($this->treasuryOfficer2)->getJson("/api/v1/treasury-transfers/{$transferId}")->assertNotFound();
    expect(collect(($this->actAs)($this->treasuryOfficer2)->getJson('/api/v1/treasury-transfers?company_wide=1')->json('data'))->pluck('id'))
        ->not->toContain($transferId);
});

test('the admin role is treated like the owner, as everywhere else in the API', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'admin']);
    RolePermissions::grantDefaults($role);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->accounting->postJournal('Seed balance', [
        ['account_code' => '121101', 'debit' => 500.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $transferId = ($this->actAs)($admin)->postJson('/api/v1/treasury-transfers', [
        'from_cash_account_id' => $this->treasuryA->id,
        'to_cash_account_id' => $this->treasuryB->id,
        'amount' => 40.0,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data.id');

    ($this->actAs)($admin)->postJson("/api/v1/treasury-transfers/{$transferId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});
