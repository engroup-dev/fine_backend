<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AccountController;
use App\Http\Controllers\Api\v1\Admin\ProvisionUserController;
use App\Http\Controllers\Api\v1\AssemblyOrderController;
use App\Http\Controllers\Api\v1\AttendanceController;
use App\Http\Controllers\Api\v1\AuditLogController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\BankHoldController;
use App\Http\Controllers\Api\v1\BankReconciliationController;
use App\Http\Controllers\Api\v1\BundleController;
use App\Http\Controllers\Api\v1\CashAccountController;
use App\Http\Controllers\Api\v1\ChequeController;
use App\Http\Controllers\Api\v1\ClientAddressController;
use App\Http\Controllers\Api\v1\ClientController;
use App\Http\Controllers\Api\v1\ClientGroupController;
use App\Http\Controllers\Api\v1\CreditApprovalController;
use App\Http\Controllers\Api\v1\CutterWorkOrderController;
use App\Http\Controllers\Api\v1\DashboardController;
use App\Http\Controllers\Api\v1\DebtorStatementController;
use App\Http\Controllers\Api\v1\EmployeeAdjustmentController;
use App\Http\Controllers\Api\v1\EmployeeAdvanceController;
use App\Http\Controllers\Api\v1\EmployeeController;
use App\Http\Controllers\Api\v1\EmployeePayItemController;
use App\Http\Controllers\Api\v1\EntityLookupController;
use App\Http\Controllers\Api\v1\FinancialReportController;
use App\Http\Controllers\Api\v1\FiscalPeriodController;
use App\Http\Controllers\Api\v1\FiscalYearController;
use App\Http\Controllers\Api\v1\FixedAssetController;
use App\Http\Controllers\Api\v1\FxRateController;
use App\Http\Controllers\Api\v1\GoodsReceiptController;
use App\Http\Controllers\Api\v1\HeldCartController;
use App\Http\Controllers\Api\v1\HrSettingsController;
use App\Http\Controllers\Api\v1\InternalRestockController;
use App\Http\Controllers\Api\v1\InventoryItemController;
use App\Http\Controllers\Api\v1\InventoryMovementController;
use App\Http\Controllers\Api\v1\InventoryValuationController;
use App\Http\Controllers\Api\v1\ItemCategoryController;
use App\Http\Controllers\Api\v1\ItemStockLevelController;
use App\Http\Controllers\Api\v1\JournalEntryController;
use App\Http\Controllers\Api\v1\LaborRoleRateController;
use App\Http\Controllers\Api\v1\LandedCostLineController;
use App\Http\Controllers\Api\v1\LeaveRequestController;
use App\Http\Controllers\Api\v1\MaterialRequestController;
use App\Http\Controllers\Api\v1\OperatingUnitAccountController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\OverheadAllocationController;
use App\Http\Controllers\Api\v1\OverheadExpenseController;
use App\Http\Controllers\Api\v1\PartnerReportController;
use App\Http\Controllers\Api\v1\PayableSettlementController;
use App\Http\Controllers\Api\v1\PaymentRequestController;
use App\Http\Controllers\Api\v1\PayrollRunController;
use App\Http\Controllers\Api\v1\PermissionController;
use App\Http\Controllers\Api\v1\PosController;
use App\Http\Controllers\Api\v1\PosShiftController;
use App\Http\Controllers\Api\v1\ProductionBatchController;
use App\Http\Controllers\Api\v1\PurchaseOrderController;
use App\Http\Controllers\Api\v1\QuotationController;
use App\Http\Controllers\Api\v1\ReferenceLookupController;
use App\Http\Controllers\Api\v1\RoleController;
use App\Http\Controllers\Api\v1\SaleBundleController;
use App\Http\Controllers\Api\v1\SaleController;
use App\Http\Controllers\Api\v1\SalesMarginReportController;
use App\Http\Controllers\Api\v1\SalesReturnController;
use App\Http\Controllers\Api\v1\StagnantStockController;
use App\Http\Controllers\Api\v1\StockCountController;
use App\Http\Controllers\Api\v1\StockLotController;
use App\Http\Controllers\Api\v1\StockMovementCardController;
use App\Http\Controllers\Api\v1\SupplierController;
use App\Http\Controllers\Api\v1\SystemVersionController;
use App\Http\Controllers\Api\v1\TreasuryTransferController;
use App\Http\Controllers\Api\v1\TreasuryVoucherController;
use App\Http\Controllers\Api\v1\UnitBlueprintController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\WarehouseController;
use App\Http\Controllers\Api\v1\WarehouseTransferController;
use App\Http\Controllers\Api\v1\WorkOrderController;
use App\Http\Controllers\Api\v1\WorkShiftController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public guest routes
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/system/version', [SystemVersionController::class, 'record']);
    Route::get('/system/version', [SystemVersionController::class, 'current']);

    // Routes requiring authentication
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        // Routes requiring an updated password and operating unit scope
        Route::middleware(['ensure.password.updated', 'scope.unit'])->group(function () {
            // Entity lookups (used by the domain "select existing entity" picker).
            Route::get('/entities', [EntityLookupController::class, 'index']);

            // Treasury picker for every cash operation (payroll, intake, overhead…):
            // names only, no balances, so any signed-in role may read it.
            Route::get('/cash-accounts/options', [CashAccountController::class, 'options']);

            // Reference Lookups (Data Settings)
            Route::get('/reference-lookups/{category}', [ReferenceLookupController::class, 'index']);
            Route::get('/reference-lookups/{category}/{id}', [ReferenceLookupController::class, 'show']);
            Route::middleware(['require.role:owner,admin', 'require.module:manage-settings,manage-settings'])->group(function () {
                Route::post('/reference-lookups/{category}', [ReferenceLookupController::class, 'store']);
                Route::put('/reference-lookups/{category}/{id}', [ReferenceLookupController::class, 'update']);
                Route::delete('/reference-lookups/{category}/{id}', [ReferenceLookupController::class, 'destroy']);
                Route::patch('/reference-lookups/{category}/{id}/toggle-active', [ReferenceLookupController::class, 'toggleActive']);
            });

            // Operating Units index (accessible to any authenticated user to view accessible units)
            Route::get('/operating-units', [OperatingUnitController::class, 'index'])->name('operating-units.index');

            // Admin: Owner and Global Admins only
            Route::middleware('require.role:owner')->group(function () {
                // ACC-11, ACC-12: only the owner reopens a locked month or closed year, or changes the fiscal calendar.
                Route::post('/fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopen']);
                Route::post('/fiscal-years/{id}/reopen', [FiscalYearController::class, 'reopen']);
                Route::put('/fiscal-settings', [FiscalPeriodController::class, 'updateSettings']);
                Route::apiResource('operating-units', OperatingUnitController::class)->except(['index']);
                Route::post('operating-units/{id}/restore', [OperatingUnitController::class, 'restore']);
                Route::get('operating-units/{id}/warehouses', [WarehouseController::class, 'forOperatingUnit']);
                Route::post('operating-units/{id}/warehouses', [WarehouseController::class, 'storeForOperatingUnit']);
                Route::get('/operating-units/{id}/accounts', [OperatingUnitAccountController::class, 'index']);
                Route::post('/operating-units/{id}/accounts', [OperatingUnitAccountController::class, 'upsert']);
                Route::delete('/operating-units/{id}/accounts/{rowId}', [OperatingUnitAccountController::class, 'destroy']);
                Route::get('/unit-blueprints', [UnitBlueprintController::class, 'index']);
                Route::post('/unit-blueprints', [UnitBlueprintController::class, 'store']);
                Route::get('/unit-blueprints/{id}', [UnitBlueprintController::class, 'show']);
                Route::put('/unit-blueprints/{id}', [UnitBlueprintController::class, 'update']);
                Route::delete('/unit-blueprints/{id}', [UnitBlueprintController::class, 'destroy']);
                Route::post('unit-blueprints/{id}/restore', [UnitBlueprintController::class, 'restore']);
                Route::get('/roles', [RoleController::class, 'index']);
                Route::post('/roles', [RoleController::class, 'store']);
                Route::get('/roles/{id}', [RoleController::class, 'show']);
                Route::put('/roles/{id}', [RoleController::class, 'update']);
                Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
                Route::post('roles/{id}/restore', [RoleController::class, 'restore']);
                Route::get('/permissions', [PermissionController::class, 'index']);
                Route::apiResource('users', UserController::class);
                Route::post('users/{id}/restore', [UserController::class, 'restore']);
                Route::get('/users/{id}/roles', [UserController::class, 'roles']);
                Route::post('/users/{id}/roles/assign', [UserController::class, 'assignRole']);
                Route::delete('/users/{id}/roles/{roleId}', [UserController::class, 'removeRole']);
                Route::get('/audit-logs', [AuditLogController::class, 'index']);
                Route::get('/audit-logs/{tableName}/{recordId}', [AuditLogController::class, 'show']);
                Route::get('/system/versions', [SystemVersionController::class, 'index']);
                Route::post('/admin/entities/{id}/provision-user', [ProvisionUserController::class]);
            });

            // Finance: Mutations (Strictly Accounting Manager & Owner)
            Route::middleware(['require.role:owner,accounting-manager', 'require.module:manage-accounting'])->group(function () {
                Route::post('/overhead-expenses', [OverheadExpenseController::class, 'store']);
                Route::post('/overhead-expenses/{id}/allocate', [OverheadExpenseController::class, 'allocate']);
                Route::get('/overhead-allocation-rules', [OverheadExpenseController::class, 'rules']);
                Route::post('/overhead-allocation-rules', [OverheadExpenseController::class, 'storeRule']);
                Route::post('/fixed-assets', [FixedAssetController::class, 'store']);
                Route::post('/fixed-assets/{id}/depreciate', [FixedAssetController::class, 'depreciate']);
                Route::post('/fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose']);
                Route::post('/fixed-assets/{id}/transition', [FixedAssetController::class, 'transition']);
                Route::post('/accounts', [AccountController::class, 'store']);
                Route::post('/accounts/wipe', [AccountController::class, 'wipe']);
                Route::put('/accounts/{id}', [AccountController::class, 'update']);
                Route::patch('/accounts/{id}/parent', [AccountController::class, 'reparent']);
                Route::patch('/accounts/{id}/status', [AccountController::class, 'updateStatus']);
                Route::delete('/accounts/{id}', [AccountController::class, 'destroy']);
                // ACC-04: manual entries (the controller keeps its own role check as a second layer).
                Route::post('/journal-entries', [JournalEntryController::class, 'store']);
                Route::post('/journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse']);
                // ACC-11, ACC-12: the accounting manager locks ended months and closes fiscal years.
                Route::post('/fiscal-years', [FiscalPeriodController::class, 'storeYear']);
                Route::post('/fiscal-years/{id}/close', [FiscalYearController::class, 'close']);
                Route::post('/fiscal-periods/{id}/lock', [FiscalPeriodController::class, 'lock']);
                Route::post('/treasury-vouchers/{id}/cancel', [TreasuryVoucherController::class, 'cancel']);
            });

            // Allocation & landed cost approvals are guarded at the domain level by AllocationPaymentService (asserts manager of unit)
            Route::post('/overhead-allocations/{id}/approve', [OverheadAllocationController::class, 'approve']);
            Route::post('/overhead-allocations/{id}/mark-paid', [OverheadAllocationController::class, 'markPaid']);
            Route::post('/purchase-orders/{id}/landed-cost-lines/{lineId}/approve', [LandedCostLineController::class, 'approve']);
            Route::post('/purchase-orders/{id}/landed-cost-lines/{lineId}/mark-paid', [LandedCostLineController::class, 'markPaid']);

            // Financial Ledger & Reports: Ledger queries, statements and reports (scoped by unit)
            Route::middleware(['require.role:owner,accounting-manager,unit_manager,manager,inventory-manager,foam-manager,foam-operator,cutter-manager,cutter-operator,furniture-manager,assembler,store-manager,pos-cashier,procurement-manager,treasury-officer', 'require.module:view-accounting'])->group(function () {
                Route::get('/accounts', [AccountController::class, 'index']);
                Route::get('/accounts/{id}', [AccountController::class, 'show']);
                Route::get('/accounts/{id}/ledger', [AccountController::class, 'ledger']);
                Route::get('/fiscal-years', [FiscalPeriodController::class, 'index']);
                Route::get('/fiscal-years/{id}/closing-preview', [FiscalYearController::class, 'closingPreview']);
                Route::get('/journal-entries', [JournalEntryController::class, 'index']);
                Route::get('/journal-entries/for-document/{type}/{documentId}', [JournalEntryController::class, 'forDocument']);
                Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
                Route::get('/reports/trial-balance', [JournalEntryController::class, 'trialBalance']);
                Route::get('/reports/income-statement', [FinancialReportController::class, 'incomeStatement']);
                Route::get('/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
                Route::get('/reports/unit-profitability', [FinancialReportController::class, 'unitProfitability']);
                Route::get('/reports/bundle-sales', [BundleController::class, 'salesReport']);
                Route::get('/reports/partner-balances', [PartnerReportController::class, 'balances']);
                Route::get('/reports/partner-statement', [PartnerReportController::class, 'statement']);
                Route::get('/reports/sales-margin', [SalesMarginReportController::class, 'index']);
                Route::get('/overhead-expenses', [OverheadExpenseController::class, 'index']);
                Route::get('/fixed-assets', [FixedAssetController::class, 'index']);
                Route::get('/fixed-assets/{id}', [FixedAssetController::class, 'show']);
                Route::get('/fixed-assets/{id}/depreciation-schedule', [FixedAssetController::class, 'depreciationSchedule']);
            });

            // Debtor statements (كشف حساب المدينين)
            Route::middleware(['require.role:owner,accounting-manager,manager,unit_manager,treasury-officer', 'require.module:view-debtor-statements,settle-payables'])->group(function () {
                Route::get('/reports/debtor-statements', [DebtorStatementController::class, 'index']);
            });

            // Payable settlement is guarded inside PayableSettlementController (returns SETTLEMENT_FORBIDDEN)
            Route::post('/payable-settlements', [PayableSettlementController::class, 'store']);

            // Treasury & Payables
            Route::middleware(['require.role:owner,treasury-officer,accounting-manager,procurement-manager,hr-manager,inventory-manager,foam-manager,cutter-manager,furniture-manager,store-manager,unit_manager,manager', 'require.module:view-treasury,create-treasury'])->group(function () {
                Route::get('/payment-requests', [PaymentRequestController::class, 'all']);
                Route::get('/purchase-orders/{id}/payment-requests', [PaymentRequestController::class, 'index']);
                Route::post('/purchase-orders/{id}/payment-requests/{requestId}/process', [PaymentRequestController::class, 'process']);
                Route::post('/payment-requests/{id}/execute', [PaymentRequestController::class, 'execute']);
                Route::get('/bank-holds', [BankHoldController::class, 'index']);
                Route::get('/purchase-orders/{id}/bank-holds', [BankHoldController::class, 'forOrder']);
                Route::get('/fx-rates', [FxRateController::class, 'index']);
                Route::post('/fx-rates', [FxRateController::class, 'store']);
                Route::get('/payable-settlements', [PayableSettlementController::class, 'index']);
                Route::get('/payable-settlements/outstanding', [PayableSettlementController::class, 'outstanding']);
                Route::get('/cash-accounts', [CashAccountController::class, 'index']);
                Route::post('/cash-accounts', [CashAccountController::class, 'store']);
                Route::put('/cash-accounts/{id}', [CashAccountController::class, 'update']);
                Route::get('/cash-accounts/{id}/statement', [CashAccountController::class, 'statement']);
                Route::get('/treasury-vouchers', [TreasuryVoucherController::class, 'index']);
                Route::get('/treasury-vouchers/{id}', [TreasuryVoucherController::class, 'show']);
                Route::post('/treasury-vouchers', [TreasuryVoucherController::class, 'store']);
                Route::get('/treasury-transfers', [TreasuryTransferController::class, 'index']);
                Route::get('/treasury-transfers/{id}', [TreasuryTransferController::class, 'show']);
                Route::post('/treasury-transfers', [TreasuryTransferController::class, 'store']);
                Route::post('/treasury-transfers/{id}/approve', [TreasuryTransferController::class, 'approve']);
                Route::post('/treasury-transfers/{id}/reject', [TreasuryTransferController::class, 'reject']);
                Route::post('/treasury-transfers/{id}/cancel', [TreasuryTransferController::class, 'cancel']);
                Route::get('/bank-reconciliations', [BankReconciliationController::class, 'index']);
                Route::post('/bank-reconciliations', [BankReconciliationController::class, 'store']);
                Route::get('/bank-reconciliations/{id}', [BankReconciliationController::class, 'show']);
                Route::post('/bank-reconciliations/{id}/lines', [BankReconciliationController::class, 'toggleLines']);
                Route::post('/bank-reconciliations/{id}/finish', [BankReconciliationController::class, 'finish']);
                Route::delete('/bank-reconciliations/{id}', [BankReconciliationController::class, 'destroy']);
                Route::get('/cheques/summary', [ChequeController::class, 'summary']);
                Route::get('/cheques', [ChequeController::class, 'index']);
                Route::get('/cheques/{id}', [ChequeController::class, 'show'])->whereUuid('id');
                Route::post('/cheques', [ChequeController::class, 'store']);
                Route::post('/cheques/issue', [ChequeController::class, 'issue']);
                Route::post('/cheques/{id}/deposit', [ChequeController::class, 'deposit'])->whereUuid('id');
                Route::post('/cheques/{id}/clear', [ChequeController::class, 'clear'])->whereUuid('id');
                Route::post('/cheques/{id}/bounce', [ChequeController::class, 'bounce'])->whereUuid('id');
                Route::post('/cheques/{id}/return', [ChequeController::class, 'returnToDrawer'])->whereUuid('id');
                Route::post('/cheques/{id}/cancel', [ChequeController::class, 'cancel'])->whereUuid('id');
            });

            // Procurement
            Route::middleware(['require.role:owner,procurement-manager,treasury-officer,accounting-manager,hr-manager,inventory-manager,foam-manager,cutter-manager,furniture-manager,store-manager,unit_manager,manager', 'require.module:view-procurement,edit-procurement'])->group(function () {
                // W5-07: merge a duplicate supplier into another (the source is soft-deleted).
                Route::post('/suppliers/{id}/merge', [SupplierController::class, 'merge'])->whereUuid('id');
                Route::apiResource('suppliers', SupplierController::class);
                Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show', 'update']);
                Route::post('/purchase-orders/{id}/transition', [PurchaseOrderController::class, 'transition']);
                // Wave 5 (local flow): atomic per-line batch receive and
                // local-payment endpoints. Available to procurement-manager
                // because they own the local procurement lifecycle.
                Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
                Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);
                Route::post('/purchase-orders/{id}/pay-local', [PurchaseOrderController::class, 'payLocal']);
                Route::get('/purchase-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'index']);
                Route::post('/purchase-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'store']);
                Route::get('/purchase-orders/{id}/goods-receipts', [GoodsReceiptController::class, 'index']);
            });

            // HR & Payroll & Employees
            Route::middleware(['require.role:owner,hr-manager,accounting-manager,unit_manager,manager,foam-manager,cutter-manager,furniture-manager,store-manager,procurement-manager', 'require.module:view-hr,manage-hr'])->group(function () {
                Route::get('/employees/{id}/attendance', [EmployeeController::class, 'attendance']);
                Route::get('/employees/{id}/payslips', [EmployeeController::class, 'payslips']);
                Route::get('/employees/{id}/compensations', [EmployeeController::class, 'compensations']);
                Route::post('/employees/{id}/compensations', [EmployeeController::class, 'storeCompensation']);
                Route::delete('/employees/{id}/compensations/{compensationId}', [EmployeeController::class, 'destroyCompensation']);
                // W5-03 (HR-13): standing allowances and deductions (HR, accounting, owner write).
                Route::get('/employees/{id}/pay-items', [EmployeePayItemController::class, 'index']);
                Route::post('/employees/{id}/pay-items', [EmployeePayItemController::class, 'store']);
                Route::put('/employees/{id}/pay-items/{itemId}', [EmployeePayItemController::class, 'update'])->whereUuid('itemId');
                Route::delete('/employees/{id}/pay-items/{itemId}', [EmployeePayItemController::class, 'destroy'])->whereUuid('itemId');
                Route::get('/hr-settings', [HrSettingsController::class, 'show']);
                Route::put('/hr-settings', [HrSettingsController::class, 'update']);
                // W5-04 (HR-14): work shifts (overtime multiplier, standard hours); pay roles write.
                Route::get('/work-shifts', [WorkShiftController::class, 'index']);
                Route::post('/work-shifts', [WorkShiftController::class, 'store']);
                Route::put('/work-shifts/{id}', [WorkShiftController::class, 'update'])->whereUuid('id');
                Route::delete('/work-shifts/{id}', [WorkShiftController::class, 'destroy'])->whereUuid('id');
                Route::apiResource('employees', EmployeeController::class);
                Route::get('/attendance', [AttendanceController::class, 'index']);
                Route::post('/attendance', [AttendanceController::class, 'store']);
                Route::post('/attendance/bulk', [AttendanceController::class, 'bulk']);
                Route::put('/attendance/{id}', [AttendanceController::class, 'update']);
                Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);
                Route::get('/labor-role-rates', [LaborRoleRateController::class, 'index']);
                Route::get('/labor-role-rates/current', [LaborRoleRateController::class, 'current']);
                Route::post('/labor-role-rates', [LaborRoleRateController::class, 'store']);
                Route::get('/payroll-runs', [PayrollRunController::class, 'index']);
                Route::post('/payroll-runs', [PayrollRunController::class, 'store']);
                Route::post('/payroll-runs/{id}/calculate', [PayrollRunController::class, 'calculate']);
                Route::post('/payroll-runs/{id}/submit', [PayrollRunController::class, 'submit']);
                Route::post('/payroll-runs/{id}/approve', [PayrollRunController::class, 'approve']);
                Route::post('/payroll-runs/{id}/mark-paid', [PayrollRunController::class, 'markPaid']);
                Route::post('/payroll-runs/{id}/post', [PayrollRunController::class, 'post']);
                Route::get('/payroll-runs/{id}/payslips', [PayrollRunController::class, 'payslips']);
                Route::get('/payslips/{id}', [PayrollRunController::class, 'showPayslip']);
                Route::put('/payslips/{id}/deductions', [PayrollRunController::class, 'setDeductions']);
                Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
                Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
                Route::put('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
                Route::put('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);
                // W5-02 (HR-12): advances and loans; HR requests and cancels.
                Route::get('/employee-advances', [EmployeeAdvanceController::class, 'index']);
                Route::get('/employee-advances/{id}', [EmployeeAdvanceController::class, 'show'])->whereUuid('id');
                Route::post('/employee-advances', [EmployeeAdvanceController::class, 'store']);
                Route::post('/employee-advances/{id}/cancel', [EmployeeAdvanceController::class, 'cancel'])->whereUuid('id');
                // W5-03 (HR-13): one-off rewards and deductions; HR enters drafts.
                Route::get('/employee-adjustments', [EmployeeAdjustmentController::class, 'index']);
                Route::post('/employee-adjustments', [EmployeeAdjustmentController::class, 'store']);
                Route::put('/employee-adjustments/{id}', [EmployeeAdjustmentController::class, 'update'])->whereUuid('id');
                Route::delete('/employee-adjustments/{id}', [EmployeeAdjustmentController::class, 'destroy'])->whereUuid('id');
            });

            // W5-03 (HR-13): approving a one-off reward or deduction.
            Route::middleware(['require.role:owner,accounting-manager,hr-manager', 'require.module:approve-pay-adjustments'])->group(function () {
                Route::post('/employee-adjustments/{id}/approve', [EmployeeAdjustmentController::class, 'approve'])->whereUuid('id');
            });

            // W5-02 (decision #14): approving, paying out and taking a cash repayment.
            Route::middleware(['require.role:owner,accounting-manager,hr-manager', 'require.module:approve-advances'])->group(function () {
                Route::post('/employee-advances/{id}/approve', [EmployeeAdvanceController::class, 'approve'])->whereUuid('id');
                Route::post('/employee-advances/{id}/pay', [EmployeeAdvanceController::class, 'pay'])->whereUuid('id');
                Route::post('/employee-advances/{id}/repay', [EmployeeAdvanceController::class, 'repay'])->whereUuid('id');
            });

            // Foam Manufacturing
            Route::middleware(['require.role:owner,foam-manager,foam-operator,unit_manager,manager', 'require.module:view-foam,edit-foam'])->group(function () {
                Route::post('/production-batches/{id}/blocks', [ProductionBatchController::class, 'registerBlocks']);
                Route::post('/production-batches/{id}/transition', [ProductionBatchController::class, 'transition']);
                Route::post('/production-batches/{id}/materials', [ProductionBatchController::class, 'issueMaterials']);
                Route::delete('/production-batches/{id}/materials/{line}', [ProductionBatchController::class, 'removeMaterial']);
                Route::apiResource('production-batches', ProductionBatchController::class);
            });

            // Cutter Manufacturing
            Route::middleware(['require.role:owner,cutter-manager,cutter-operator,unit_manager,manager', 'require.module:view-cutting,edit-cutting'])->group(function () {
                Route::get('/cutter-work-orders', [CutterWorkOrderController::class, 'index']);
                Route::post('/cutter-work-orders', [CutterWorkOrderController::class, 'store']);
                // Registered before /{id}: the literal path must not be read as an order id.
                Route::get('/cutter-work-orders/available-foam-blocks', [CutterWorkOrderController::class, 'availableFoamBlocks']);
                Route::get('/cutter-work-orders/{id}', [CutterWorkOrderController::class, 'show'])->whereUuid('id');
                Route::get('/cutter-work-orders/{id}/job-sheet', [CutterWorkOrderController::class, 'jobSheet'])->whereUuid('id');
                Route::post('/cutter-work-orders/{id}/transition', [CutterWorkOrderController::class, 'transition']);
                Route::post('/cutter-work-orders/{id}/lines', [CutterWorkOrderController::class, 'storeLine']);
                Route::post('/cutter-work-orders/{id}/weigh-in', [CutterWorkOrderController::class, 'recordWeighIn']);
                Route::get('/cutter-work-orders/{id}/byproduct-yields', [CutterWorkOrderController::class, 'byproductYields']);
                Route::post('/cutter-work-orders/{id}/attach-block', [CutterWorkOrderController::class, 'attachBlock']);
                Route::delete('/cutter-work-orders/{id}/detach-block', [CutterWorkOrderController::class, 'detachBlock']);
                Route::put('/cutter-work-order-lines/{lineId}/assign-template', [CutterWorkOrderController::class, 'assignTemplate']);
                Route::get('/cutter-work-order-lines/{lineId}/available-blocks', [CutterWorkOrderController::class, 'availableBlocks']);
                Route::post('/cutter-work-order-lines/{lineId}/select-block', [CutterWorkOrderController::class, 'selectBlock']);
            });

            // Furniture assembly orders (W4-07, decision #12): reads with view-furniture,
            // opening an order with create-furniture, completing or cancelling with edit-furniture.
            Route::middleware(['require.role:owner,furniture-manager,assembler', 'require.module:view-furniture'])->group(function () {
                Route::get('/assembly-orders', [AssemblyOrderController::class, 'index']);
                Route::get('/assembly-orders/{id}', [AssemblyOrderController::class, 'show'])->whereUuid('id');
            });

            Route::middleware(['require.role:owner,furniture-manager,assembler', 'require.module:view-furniture,create-furniture'])->group(function () {
                Route::post('/assembly-orders', [AssemblyOrderController::class, 'store']);
            });

            Route::middleware(['require.role:owner,furniture-manager,assembler', 'require.module:view-furniture,edit-furniture'])->group(function () {
                Route::post('/assembly-orders/{id}/complete', [AssemblyOrderController::class, 'complete'])->whereUuid('id');
                Route::post('/assembly-orders/{id}/cancel', [AssemblyOrderController::class, 'cancel'])->whereUuid('id');
            });

            // Cross-module material requests — visible to furniture/cutter/foam
            // teams so they can see what's blocking assembly.
            Route::middleware(['require.role:owner,admin,furniture-manager,cutter-manager,foam-manager,unit_manager,manager', 'require.module:view-material-requests,manage-material-requests'])->group(function () {
                Route::get('/material-requests', [MaterialRequestController::class, 'index']);
                Route::get('/material-requests/{id}', [MaterialRequestController::class, 'show']);
                Route::post('/material-requests/{id}/start', [MaterialRequestController::class, 'start']);
                Route::post('/material-requests/{id}/fulfill', [MaterialRequestController::class, 'fulfill']);
                Route::post('/material-requests/{id}/cancel', [MaterialRequestController::class, 'cancel']);
            });

            // Sales & POS — the POS is the one place every sale happens.
            Route::middleware(['require.role:owner,store-manager,pos-cashier,cutter-manager,unit_manager,manager', 'require.module:view-sales,create-sales'])->group(function () {
                Route::get('/sales', [SaleController::class, 'index']);
                Route::post('/sales', [SaleController::class, 'store']);
                Route::get('/sales/payment-accounts', [SaleController::class, 'paymentAccounts']);
                Route::get('/sales/{id}', [SaleController::class, 'show'])->whereUuid('id');
                Route::post('/sales/{id}/payments', [SaleController::class, 'collectPayment'])->whereUuid('id');
                Route::get('/sales/{id}/invoice', [SaleController::class, 'invoice'])->whereUuid('id');
                Route::get('/sales/{id}/delivery-note', [SaleBundleController::class, 'deliveryNote'])->whereUuid('id');
                Route::post('/sales/{id}/deliver', [SaleBundleController::class, 'deliver'])->whereUuid('id');
                Route::post('/sales/{id}/send-to-cutter', [SaleBundleController::class, 'sendToCutter'])->whereUuid('id');
                Route::get('/sales/open-cutter-orders', [SaleBundleController::class, 'openCutterOrders']);
                Route::put('/sales/{id}/lines/{lineId}/components', [SaleBundleController::class, 'define'])->whereUuid(['id', 'lineId']);
                Route::get('/sale-components/{componentId}/matching-stock', [SaleBundleController::class, 'matchingStock'])->whereUuid('componentId');
                Route::post('/sale-components/{componentId}/reserve', [SaleBundleController::class, 'reserve'])->whereUuid('componentId');
                Route::post('/sale-components/{componentId}/release', [SaleBundleController::class, 'release'])->whereUuid('componentId');
                Route::get('/quotations', [QuotationController::class, 'index']);
                Route::post('/quotations', [QuotationController::class, 'store']);
                Route::get('/quotations/{id}', [QuotationController::class, 'show'])->whereUuid('id');
                Route::post('/quotations/{id}/cancel', [QuotationController::class, 'cancel'])->whereUuid('id');
                Route::post('/quotations/{id}/convert', [QuotationController::class, 'convert'])->whereUuid('id');
                Route::get('/pos/daily-report', [PosController::class, 'dailyReport']);
                Route::get('/pos/daily-close', [PosController::class, 'showDailyClose']);
                Route::post('/pos/daily-close', [PosController::class, 'dailyClose']);
                // FR-SLREP-008: cashier shifts — opening float, X-report, Z-report close.
                Route::get('/pos/shifts', [PosShiftController::class, 'index']);
                Route::get('/pos/shifts/current', [PosShiftController::class, 'current']);
                Route::post('/pos/shifts', [PosShiftController::class, 'store']);
                Route::get('/pos/shifts/{id}', [PosShiftController::class, 'show'])->whereUuid('id');
                Route::get('/pos/shifts/{id}/x-report', [PosShiftController::class, 'xReport'])->whereUuid('id');
                Route::post('/pos/shifts/{id}/close', [PosShiftController::class, 'close'])->whereUuid('id');
                Route::get('/held-carts', [HeldCartController::class, 'index']);
                Route::post('/held-carts', [HeldCartController::class, 'store']);
                Route::delete('/held-carts/{id}', [HeldCartController::class, 'destroy'])->whereUuid('id');
            });

            // FR-SLREP-008: owner and unit managers switch cashier shifts on for a unit.
            Route::middleware('require.role:owner,store-manager,unit_manager,manager')->group(function () {
                Route::put('/operating-units/{id}/pos-settings', [PosShiftController::class, 'updateSettings'])->whereUuid('id');
            });

            // Sales returns, reads (BR-INV-013): whoever sees sales or raises returns —
            // cashiers view, accounting managers (no view-sales) still reach what they raise.
            Route::middleware(['require.role:owner,store-manager,pos-cashier,cutter-manager,unit_manager,manager,accounting-manager', 'require.module:view-sales|create-sales-returns'])->group(function () {
                Route::get('/sales/{id}/returnable', [SalesReturnController::class, 'returnable'])->whereUuid('id');
                Route::get('/sales-returns', [SalesReturnController::class, 'index']);
                Route::get('/sales-returns/{id}', [SalesReturnController::class, 'show'])->whereUuid('id');
            });

            // Sales returns (BR-INV-013) — managers only; a cashier can see returns but not raise one.
            Route::middleware(['require.role:owner,store-manager,unit_manager,manager,accounting-manager', 'require.module:create-sales-returns'])->group(function () {
                Route::post('/sales/{id}/returns', [SalesReturnController::class, 'store'])->whereUuid('id');
            });

            // Credit decisions — managers only; a cashier cannot approve their own over-limit sale.
            Route::middleware(['require.role:owner,store-manager,unit_manager,manager,accounting-manager', 'require.module:approve-credit'])->group(function () {
                Route::get('/credit-approval-requests', [CreditApprovalController::class, 'index']);
                Route::put('/credit-approval-requests/{id}/approve', [CreditApprovalController::class, 'approve']);
                Route::put('/credit-approval-requests/{id}/reject', [CreditApprovalController::class, 'reject']);
            });

            // Inventory, Stock Lots, Tanks & Work Orders
            Route::middleware(['require.role:owner,foam-manager,foam-operator,cutter-manager,cutter-operator,furniture-manager,assembler,store-manager,pos-cashier,procurement-manager,treasury-officer,accounting-manager,unit_manager,manager,inventory-manager', 'require.module:view-inventory,edit-inventory'])->group(function () {
                Route::apiResource('item-categories', ItemCategoryController::class);
                Route::apiResource('bundles', BundleController::class);
                Route::apiResource('inventory-items', InventoryItemController::class);
                // W4-03: scan or type a barcode, item code or lot number.
                Route::get('/inventory/lookup', [InventoryItemController::class, 'lookup']);
                Route::get('/stock-lots/available-for-cutting', [StockLotController::class, 'availableForCutting']);
                Route::get('/stock-lots/available-foam-blocks', [StockLotController::class, 'availableFoamBlocks']);
                Route::post('/stock-lots/intake', [StockLotController::class, 'intake']);
                Route::post('/stock-lots/{id}/process-cut-remnant', [StockLotController::class, 'processCutRemnant']);
                // No bare store: stock enters only via /stock-lots/intake, which records the INV-06 movement.
                Route::apiResource('stock-lots', StockLotController::class)->except(['store', 'destroy']);
                Route::get('/inventory/valuation', [InventoryValuationController::class, 'index']);
                Route::get('/inventory/valuation/rollup', [InventoryValuationController::class, 'rollup']);
                Route::get('/warehouses/{id}/stock-summary', [WarehouseController::class, 'stockSummary']);
                // W4-02: reorder levels and bins per item per warehouse, and the reorder report.
                Route::apiResource('item-stock-levels', ItemStockLevelController::class);
                Route::get('/inventory/reorder-report', [ItemStockLevelController::class, 'reorderReport']);
                // W4-04: lots idle longer than ?days (default 90), valued at qty × unit cost.
                Route::get('/inventory/stagnant-stock', [StagnantStockController::class, 'index']);
                Route::apiResource('warehouses', WarehouseController::class);
                Route::apiResource('warehouse-transfers', WarehouseTransferController::class)->except(['destroy']);
                Route::post('/warehouse-transfers/{id}/complete', [WarehouseTransferController::class, 'complete']);
                Route::post('/warehouse-transfers/{id}/cancel', [WarehouseTransferController::class, 'cancel']);
                // No work-order complete and no bare movement store (W4-01): both wrote lot-less
                // movements with no journal. Stock moves only through the flows that record both.
                Route::apiResource('work-orders', WorkOrderController::class);
                Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
                Route::get('/inventory-movements/for-document/{type}/{documentId}', [InventoryMovementController::class, 'forDocument']);
                Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
                Route::get('/inventory/stock/{sku}', [InventoryMovementController::class, 'stock']);
                Route::get('/inventory/ledger/{warehouseId}', [InventoryMovementController::class, 'ledger']);
                Route::get('/inventory/items/{id}/movement-card', [StockMovementCardController::class, 'show']);
                Route::get('/internal-restock-requests', [InternalRestockController::class, 'index']);
                Route::post('/internal-restock-requests', [InternalRestockController::class, 'store']);
                Route::put('/internal-restock-requests/{id}/approve', [InternalRestockController::class, 'approve']);
                Route::put('/internal-restock-requests/{id}/reject', [InternalRestockController::class, 'reject']);
                Route::post('/internal-restock-requests/{id}/fulfill', [InternalRestockController::class, 'fulfill']);
            });

            // Stock counts (W4-01, decision #13), reads: the counters and the approvers.
            // A unit's own manager role (foam, cutter, furniture, store, procurement) counts its warehouses.
            Route::middleware(['require.role:owner,inventory-manager,unit_manager,manager,foam-manager,cutter-manager,furniture-manager,store-manager,procurement-manager,accounting-manager', 'require.module:view-inventory'])->group(function () {
                Route::get('/stock-counts', [StockCountController::class, 'index']);
                Route::get('/stock-counts/{id}', [StockCountController::class, 'show'])->whereUuid('id');
                Route::get('/stock-counts/{id}/sheet', [StockCountController::class, 'sheet'])->whereUuid('id');
            });

            // Stock counts: an inventory manager or unit manager opens and enters a count.
            Route::middleware(['require.role:owner,inventory-manager,unit_manager,manager,foam-manager,cutter-manager,furniture-manager,store-manager,procurement-manager', 'require.module:view-inventory,edit-inventory'])->group(function () {
                Route::post('/stock-counts', [StockCountController::class, 'store']);
                Route::put('/stock-counts/{id}', [StockCountController::class, 'update'])->whereUuid('id');
                Route::delete('/stock-counts/{id}', [StockCountController::class, 'destroy'])->whereUuid('id');
                Route::put('/stock-counts/{id}/lines', [StockCountController::class, 'updateLines'])->whereUuid('id');
                Route::post('/stock-counts/{id}/found', [StockCountController::class, 'addFound'])->whereUuid('id');
                Route::post('/stock-counts/{id}/submit', [StockCountController::class, 'submit'])->whereUuid('id');
            });

            // Stock counts: the counters or an approver may cancel one.
            Route::middleware(['require.role:owner,inventory-manager,unit_manager,manager,foam-manager,cutter-manager,furniture-manager,store-manager,procurement-manager,accounting-manager', 'require.module:view-inventory,edit-inventory|approve-stock-counts'])->group(function () {
                Route::post('/stock-counts/{id}/cancel', [StockCountController::class, 'cancel'])->whereUuid('id');
            });

            // Stock counts: the owner or an accounting manager approves (never the counter).
            Route::middleware(['require.role:owner,accounting-manager', 'require.module:approve-stock-counts'])->group(function () {
                Route::post('/stock-counts/{id}/approve', [StockCountController::class, 'approve'])->whereUuid('id');
            });

            // Clients
            Route::middleware('require.role:owner,store-manager,pos-cashier,procurement-manager,accounting-manager,unit_manager,manager')->group(function () {
                Route::apiResource('clients', ClientController::class)->middleware('require.module:view-clients,edit-clients');

                // Client card: delivery addresses, credit limit history and the money summary.
                Route::middleware('require.module:view-clients,edit-clients')->group(function () {
                    Route::get('/clients/{id}/summary', [ClientController::class, 'summary'])->whereUuid('id');
                    Route::get('/clients/{id}/credit-limit-changes', [ClientController::class, 'creditLimitChanges'])->whereUuid('id');
                    Route::get('/clients/{clientId}/addresses', [ClientAddressController::class, 'index'])->whereUuid('clientId');
                    Route::post('/clients/{clientId}/addresses', [ClientAddressController::class, 'store'])->whereUuid('clientId');
                    Route::put('/clients/{clientId}/addresses/{addressId}', [ClientAddressController::class, 'update'])->whereUuid(['clientId', 'addressId']);
                    Route::delete('/clients/{clientId}/addresses/{addressId}', [ClientAddressController::class, 'destroy'])->whereUuid(['clientId', 'addressId']);
                });

                // W5-06: client groups (company-wide); everyone here reads, managers write
                // (and only from a company-wide assignment, checked in the controller).
                Route::get('/client-groups', [ClientGroupController::class, 'index'])->middleware('require.module:view-clients,edit-clients');
                Route::middleware(['require.role:'.implode(',', ClientGroupController::WRITE_ROLES), 'require.module:view-clients,edit-clients'])->group(function () {
                    Route::post('/client-groups', [ClientGroupController::class, 'store']);
                    Route::put('/client-groups/{id}', [ClientGroupController::class, 'update'])->whereUuid('id');
                    Route::delete('/client-groups/{id}', [ClientGroupController::class, 'destroy'])->whereUuid('id');
                });
            });

            // Owner Dashboard (guarded internally by ResolvesReportScope)
            Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
            Route::get('/dashboard/unit-comparison', [DashboardController::class, 'unitComparison']);
            Route::get('/dashboard/inventory-rollup', [DashboardController::class, 'inventoryRollup']);
            Route::get('/dashboard/operational-pipeline', [DashboardController::class, 'operationalPipeline']);
            Route::get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals']);
            Route::get('/dashboard/my-allocation-approvals', [DashboardController::class, 'myAllocationApprovals']);
        });
    });
});
