<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AdvanceInstallment;
use App\Models\AssemblyOrder;
use App\Models\AssemblyOrderLine;
use App\Models\BankReconciliation;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientCreditLimitChange;
use App\Models\ClientGroup;
use App\Models\CutterWorkOrder;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\Entity;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\InternalRestockRequest;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\ItemStockLevel;
use App\Models\JournalEntry;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\OverheadExpense;
use App\Models\PayrollRun;
use App\Models\PosShift;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMaterial;
use App\Models\PurchaseOrderItem;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\TreasuryTransfer;
use App\Models\TreasuryVoucher;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferLine;
use App\Models\WorkShift;
use App\Observers\AuditObserver;
use App\Support\CurrentUnitContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentUnitContext::class, function () {
            return new CurrentUnitContext;
        });

        // Fallback autoloader for Database\Seeders and Database\Factories in case
        // an environment is running with an authoritative Composer classmap without
        // having re-dumped the autoloader after a git pull.
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Database\\Seeders\\')) {
                $relative = substr($class, strlen('Database\\Seeders\\'));
                $file = database_path('seeders/'.str_replace('\\', '/', $relative).'.php');
                if (file_exists($file)) {
                    require_once $file;
                }
            } elseif (str_starts_with($class, 'Database\\Factories\\')) {
                $relative = substr($class, strlen('Database\\Factories\\'));
                $file = database_path('factories/'.str_replace('\\', '/', $relative).'.php');
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(AuditObserver::class);
        OperatingUnit::observe(AuditObserver::class);
        Warehouse::observe(AuditObserver::class);
        Role::observe(AuditObserver::class);

        // Inventory and production. Phase 03 requires the audit log to capture every
        // inventory adjustment, and these carry the material and money movements.
        InventoryItem::observe(AuditObserver::class);
        ItemCategory::observe(AuditObserver::class);
        Bundle::observe(AuditObserver::class);
        StockLot::observe(AuditObserver::class);
        ItemStockLevel::observe(AuditObserver::class);
        WarehouseTransfer::observe(AuditObserver::class);
        ProductionBatch::observe(AuditObserver::class);
        ProductionBatchMaterial::observe(AuditObserver::class);

        // Phase 08: overhead and fixed assets carry money — every change is
        // audit-logged like the inventory value carriers above.
        OverheadExpense::observe(AuditObserver::class);
        FixedAsset::observe(AuditObserver::class);

        // Phase 09: payroll moves wages — same treatment.
        PayrollRun::observe(AuditObserver::class);

        // Phase 08 Wave 2 / TRS-02, TRS-03, TRS-04, TRS-05: vouchers, transfers, bank reconciliations, and cheques.
        TreasuryVoucher::observe(AuditObserver::class);
        TreasuryTransfer::observe(AuditObserver::class);
        BankReconciliation::observe(AuditObserver::class);
        Cheque::observe(AuditObserver::class);

        // Phase 07: a quotation is the price the client was promised.
        Quotation::observe(AuditObserver::class);

        // COA: every create / update / reparent / delete on an account is
        // audit-logged so the edit-detail page can show a full timeline.
        Account::observe(AuditObserver::class);

        // Parties, sales, the ledger and the cutter: these carry the Auditable
        // marker too, and every one of them moves money, stock or a promise.
        Employee::observe(AuditObserver::class);
        EmployeeCompensation::observe(AuditObserver::class);
        EmployeeAdvance::observe(AuditObserver::class);
        AdvanceInstallment::observe(AuditObserver::class);
        EmployeePayItem::observe(AuditObserver::class);
        EmployeeAdjustment::observe(AuditObserver::class);
        WorkShift::observe(AuditObserver::class);
        Client::observe(AuditObserver::class);
        ClientAddress::observe(AuditObserver::class);
        ClientGroup::observe(AuditObserver::class);
        ClientCreditLimitChange::observe(AuditObserver::class);
        Entity::observe(AuditObserver::class);
        Supplier::observe(AuditObserver::class);
        SalesOrder::observe(AuditObserver::class);
        SalesReturn::observe(AuditObserver::class);
        SalesReturnLine::observe(AuditObserver::class);
        PosShift::observe(AuditObserver::class);
        StockCount::observe(AuditObserver::class);
        StockCountLine::observe(AuditObserver::class);
        JournalEntry::observe(AuditObserver::class);
        CutterWorkOrder::observe(AuditObserver::class);
        AssemblyOrder::observe(AuditObserver::class);
        AssemblyOrderLine::observe(AuditObserver::class);

        // Lines and requests under documents already audited above.
        InternalRestockRequest::observe(AuditObserver::class);
        MaterialRequest::observe(AuditObserver::class);
        BundleItem::observe(AuditObserver::class);
        WarehouseTransferLine::observe(AuditObserver::class);
        PurchaseOrderItem::observe(AuditObserver::class);
        FiscalYear::observe(AuditObserver::class);
        FiscalPeriod::observe(AuditObserver::class);
    }
}
