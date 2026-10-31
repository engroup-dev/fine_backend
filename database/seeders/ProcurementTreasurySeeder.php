<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\BankHold;
use App\Models\CashAccount;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\SupplierService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class ProcurementTreasurySeeder extends Seeder
{
    public function run(): void
    {
        $unit = OperatingUnit::first();

        if (! $unit) {
            return;
        }

        // 1. Seed FX Rates
        FxRate::create([
            'id' => (string) Str::uuid(),
            'from_currency' => 'USD',
            'to_currency' => 'LYD',
            'rate' => 5.200000,
            'captured_at' => now(),
        ]);

        FxRate::create([
            'id' => (string) Str::uuid(),
            'from_currency' => 'EUR',
            'to_currency' => 'LYD',
            'rate' => 5.650000,
            'captured_at' => now(),
        ]);

        // 2. Seed Cash Accounts
        CashAccount::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Main Operating Cash Treasury',
            'account_id' => Account::where('account_code', '121104')->value('id'),
            'currency' => 'LYD',
            'balance' => 1500000.00,
        ]);

        CashAccount::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Foreign Exchange Clearing Safe',
            'account_id' => Account::where('account_code', '121105')->value('id'),
            'currency' => 'USD',
            'balance' => 250000.00,
        ]);

        // GL cash mirrors the cash accounts above (1.5M LYD + 250k USD at
        // the seeded 5.20 rate). Without this, the payment advances below
        // would drive 1200 negative on a fresh seed.
        app(AccountingService::class)->postJournal(
            'Opening treasury cash (seed)',
            [
                ['account_code' => '121104', 'debit' => 1500000.00, 'operating_unit_id' => $unit->id, 'memo' => 'LYD treasury'],
                ['account_code' => '121105', 'debit' => 1300000.00, 'operating_unit_id' => $unit->id, 'memo' => 'USD clearing safe at 5.20', 'currency' => 'USD', 'original_amount' => 250000.00, 'fx_rate' => 5.20],
                ['account_code' => '31', 'credit' => 2800000.00, 'operating_unit_id' => $unit->id],
            ],
        );

        // 3. Seed Supplier
        $supplier = Supplier::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Global Chemical & Polymer Corp',
            'contact' => 'orders@globalchem.com',
            'default_currency' => 'USD',
            'address' => 'Istanbul Industrial Park, Turkey',
        ]);
        // W5-07: every supplier stands on a vendor entity of its unit.
        app(SupplierService::class)->ensureEntity($supplier);

        // 4. Seed Import Order 1 (In Transit with Bank Hold)
        $order1 = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 125.00,
            'quantity' => 1000,
            'status' => PurchaseOrderStatus::InTransit,
        ]);

        $paymentReq = PaymentRequest::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'purchase_order_id' => $order1->id,
            'route' => PaymentRoute::Bank,
            'invoice_ref' => 'INV-CHEM-2026-001',
            'amount_requested' => 125000.00,
            'status' => PaymentRequestStatus::Paid,
            'fx_rate_used' => 5.200000,
        ]);

        BankHold::create([
            'id' => (string) Str::uuid(),
            'payment_request_id' => $paymentReq->id,
            'held_amount_lyd' => 675000.00,
            'exact_amount_used' => 650000.00,
            'released_amount' => 25000.00,
            'bank_reference' => 'BNK-REF-TRIPOLI-901',
        ]);

        // The payment above is seeded already-Paid, so post what
        // executePayment would have: the advance at the settled amount
        // (bank exact-used). Completion later credits 1500 with the same
        // figure, netting the advance to exactly zero.
        app(AccountingService::class)->postJournal(
            "Import payment executed — {$supplier->name} (seed)",
            [
                ['account_code' => '15', 'debit' => 650000.00, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                ['account_code' => '121104', 'credit' => 650000.00, 'operating_unit_id' => $unit->id],
            ],
            'PaymentRequest',
            $paymentReq->id,
        );

        LandedCostLine::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $order1->id,
            'type' => LandedCostType::Freight,
            'amount' => 18500.00,
            'currency' => 'LYD',
            'is_confirmed' => true,
        ]);

        LandedCostLine::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $order1->id,
            'type' => LandedCostType::Customs,
            'amount' => 12000.00,
            'currency' => 'LYD',
            'is_confirmed' => true,
        ]);

        // 5. Seed Import Order 2 (Received at Warehouse)
        // Booked at 5.05, settled at 5.15 — completing this order in a demo
        // shows the landed cost allocation and a realized FX loss.
        $order2 = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 85.00,
            'quantity' => 500,
            'booked_fx_rate' => 5.050000,
            'status' => PurchaseOrderStatus::Received,
        ]);

        // An order cannot reach Received without an executed payment, and
        // completion refuses to post without one — the trail must exist.
        $paymentReq2 = PaymentRequest::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'purchase_order_id' => $order2->id,
            'route' => PaymentRoute::Market,
            'invoice_ref' => 'INV-CHEM-2026-002',
            'amount_requested' => 42500.00,
            'status' => PaymentRequestStatus::Paid,
            'fx_rate_used' => 5.150000,
        ]);

        // Same rule as order 1: the seeded Paid payment posts its advance
        // (42,500 USD × 5.15 realized = 218,875 LYD), so completing this
        // order in a demo clears 1500 instead of driving it negative.
        app(AccountingService::class)->postJournal(
            "Import payment executed — {$supplier->name} (seed)",
            [
                ['account_code' => '15', 'debit' => 218875.00, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                ['account_code' => '121104', 'credit' => 218875.00, 'operating_unit_id' => $unit->id],
            ],
            'PaymentRequest',
            $paymentReq2->id,
        );

        $warehouse = Warehouse::where('operating_unit_id', $unit->id)->first() ?? Warehouse::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Central Import Depot',
            'is_internal_unit' => true,
        ]);

        GoodsReceipt::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $order2->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 500,
            'condition_notes' => 'Received 500 chemical drums sealed in top grade quality.',
        ]);

        // 6. Seed a LOCAL purchase order sample (Wave 5 — local flow).
        // A domestic vendor, LYD-only, approved with partial receipts so
        // the UI's receive-tab has realistic content. Set kind = 'local'
        // so the foreign flow ignores it; the local flow auto-closes
        // once paid + fully received.
        $localSupplier = Supplier::firstOrCreate(
            ['name' => 'ليبيا للتوريدات المحلية', 'operating_unit_id' => $unit->id],
            [
                'id' => (string) Str::uuid(),
                'contact' => 'sales@libya-local-supply.ly',
                'default_currency' => 'LYD',
                'address' => 'Tripoli Industrial Zone, Libya',
            ]
        );
        app(SupplierService::class)->ensureEntity($localSupplier);

        $localOrder = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $localSupplier->id,
            'currency' => 'LYD',
            'kind' => PurchaseOrderKind::Local,
            'negotiated_price' => 3500.00,
            'quantity' => 5,
            'status' => PurchaseOrderStatus::Approved,
        ]);

        PurchaseOrderItem::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $localOrder->id,
            'inventory_item_id' => $this->getLocalInventoryItem($unit),
            'quantity' => 3,
            'unit_price' => 700.00,
            'currency' => 'LYD',
        ]);
        PurchaseOrderItem::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $localOrder->id,
            'inventory_item_id' => $this->getLocalInventoryItem($unit),
            'quantity' => 2,
            'unit_price' => 700.00,
            'currency' => 'LYD',
        ]);
    }

    /**
     * Local flow procurement needs at least one local-currency inventory
     * item to source from. Creates a "Local Material" sample if absent.
     */
    private function getLocalInventoryItem($unit): string
    {
        $category = ItemCategory::firstOrCreate(
            ['name' => 'Local Supplies', 'item_type' => 'raw_material'],
            ['id' => (string) Str::uuid(), 'code' => 'LOC'],
        );
        // inventory_items no longer carries operating_unit_id directly;
        // the per-unit stock lives on inventory_unit_stocks. The seeder
        // creates a global item keyed by (code) — production seed.
        $item = InventoryItem::firstOrCreate(
            ['code' => 'LOC-MTL-001'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Local Supply Material',
                'item_type' => 'raw_material',
                'unit_of_measure' => 'piece',
                'category_id' => $category->id,
            ]
        );

        return $item->id;
    }
}
