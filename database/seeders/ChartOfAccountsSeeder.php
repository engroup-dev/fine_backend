<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified company Chart of Accounts seeder.
 *
 * Implements Option A:
 * - Master chart structure across Assets, Liabilities, Equity, Revenues, Expenses.
 * - Operational revenue, expense, cash, and manufacturing items subdivided by unit:
 *     ...01: مصنع الإسفنج (Foam Factory)
 *     ...02: مقص فاين (Cutter Factory)
 * - Excludes individual customer/vendor sub-accounts (control accounts only).
 *
 * This catalog is the single source of truth: the account codes hardcoded in
 * the domain services (SalesAccounts, InventoryAccounts, PayrollService, …)
 * mean exactly what is defined here. The old database/data/chart_of_accounts.json
 * overlay was retired on 2026-10-17 because it gave the same codes different
 * meanings. A file is read only when one is passed explicitly (coa:post --db).
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed the catalog below. An explicit $importPath (used by `coa:post --db`)
     * overlays a chart exported from another server on top of it; the default
     * seed reads no file.
     */
    public function run(?string $importPath = null): void
    {
        $company = Company::first() ?? Company::firstOrCreate(
            ['name' => 'Fine'],
            ['default_currency' => 'LYD'],
        );

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        if ($coa->name !== 'دليل الحسابات الرئيسي') {
            $coa->update(['name' => 'دليل الحسابات الرئيسي']);
        }

        // [code, name, type, parent_code]
        $catalog = [
            // ==================== 1. الأصول (Assets) ====================
            ['1', 'الأصول', 'asset', null],
            ['11', 'الأصول الثابتة', 'asset', '1'],
            ['1101', 'أراضي', 'asset', '11'],
            ['1102', 'عدد وأدوات', 'asset', '11'],
            ['1103', 'أثاث سكن العمال والمكاتب', 'asset', '11'],
            ['1104', 'عقارات ومباني', 'asset', '11'],
            ['1107', 'آلات ومعدات', 'asset', '11'],
            ['1108', 'شبكة توصيل الكهرباء', 'asset', '11'],
            ['1109', 'منظومة الإطفاء والسلامة', 'asset', '11'],
            ['1113', 'السيارات وآلات النقل', 'asset', '11'],
            ['14', 'أصول ثابتة أخرى', 'asset', '11'],
            ['145', 'مجمع الإهلاك المتراكم', 'asset', '11'],

            ['12', 'الأصول المتداولة', 'asset', '1'],
            ['121', 'الأموال الجاهزة والنقدية', 'asset', '12'],
            ['1211', 'الخزائن النقدية', 'asset', '121'],
            ['121101', 'خزينة مصنع الإسفنج', 'asset', '1211'],
            ['121102', 'خزينة مقص فاين', 'asset', '1211'],
            ['121103', 'خزينة صالة العرض', 'asset', '1211'],
            ['121104', 'الخزينة الرئيسية', 'asset', '1211'],
            ['121105', 'خزينة مقاصة العملات', 'asset', '1211'],
            ['1212', 'حسابات المصارف', 'asset', '121'],
            ['1214', 'العهد المالية', 'asset', '121'],
            ['1215', 'سلف الموظفين', 'asset', '121'],
            ['122', 'الزبائن والعملاء', 'asset', '12'],
            ['123', 'مدفوعات ومصروفات مقدمة', 'asset', '12'],
            ['124', 'أوراق القبض', 'asset', '12'],
            ['125', 'شيكات برسم التحصيل', 'asset', '12'],
            ['15', 'دفعات مقدمة للموردين', 'asset', '12'],

            ['13', 'المخزون وبضاعة آخر المدة', 'asset', '1'],
            ['401', 'بضاعة أول المدة', 'asset', '13'],
            ['111', 'مخزون المواد الخام والكيماويات', 'asset', '13'],
            ['112', 'إنتاج تحت التشغيل', 'asset', '13'],
            ['1121', 'إنتاج تحت التشغيل — مصنع الإسفنج', 'asset', '112'],
            ['1122', 'إنتاج تحت التشغيل — قسم القص', 'asset', '112'],
            ['1123', 'إنتاج تحت التشغيل — قسم الأثاث', 'asset', '112'],
            ['113', 'مخزون الإنتاج التام', 'asset', '13'],
            ['1131', 'إنتاج تام — قوالب الإسفنج', 'asset', '113'],
            ['1132', 'إنتاج تام — القطع المقصوصة', 'asset', '113'],
            ['1133', 'إنتاج تام — حشوات وبقايا الإنتاج', 'asset', '113'],
            ['1134', 'إنتاج تام — الأثاث والمفروشات', 'asset', '113'],

            // ==================== 2. الالتزامات (Liabilities) ====================
            ['2', 'الالتزامات', 'liability', null],
            ['21', 'الدائنون والموردون', 'liability', '2'],
            ['22', 'المطاليب والالتزامات المتداولة', 'liability', '2'],
            ['221', 'الموردين', 'liability', '22'],
            ['222', 'الأمانات والودائع', 'liability', '22'],
            ['223', 'مصروفات مستحقة الدفع', 'liability', '22'],
            ['224', 'مخصصات الإهلاك', 'liability', '22'],
            ['225', 'استقطاعات رواتب مستحقة', 'liability', '22'],
            ['226', 'رواتب وأجور مستحقة', 'liability', '22'],
            ['227', 'أوراق الدفع', 'liability', '22'],
            ['23', 'وسيط تكاليف الاستيراد', 'liability', '22'],

            // ==================== 3. حقوق الملكية (Equity) ====================
            ['3', 'حقوق الملكية', 'equity', null],
            ['31', 'رأس المال والأرباح المحتجزة', 'equity', '3'],
            ['32', 'جاري الشركاء', 'equity', '3'],
            ['33', 'أرباح وخسائر مرحلة', 'equity', '3'],

            // ==================== 4. الإيرادات (Revenue) ====================
            ['4', 'الإيرادات', 'revenue', null],
            ['41', 'إيرادات المبيعات', 'revenue', '4'],
            ['40201', 'مبيعات — مصنع الإسفنج', 'revenue', '41'],
            ['40202', 'مبيعات — مقص فاين', 'revenue', '41'],
            ['40203', 'مبيعات — قسم الأثاث', 'revenue', '41'],
            ['40204', 'مبيعات — صالة العرض', 'revenue', '41'],
            ['404', 'مردودات المبيعات', 'revenue', '4'],
            ['40401', 'مردودات مبيعات — مصنع الإسفنج', 'revenue', '404'],
            ['40402', 'مردودات مبيعات — مقص فاين', 'revenue', '404'],
            ['40403', 'مردودات مبيعات — قسم الأثاث', 'revenue', '404'],
            ['40404', 'مردودات مبيعات — صالة العرض', 'revenue', '404'],
            ['407', 'خصم مسموح به', 'revenue', '4'],
            ['40701', 'خصم مسموح به — مصنع الإسفنج', 'revenue', '407'],
            ['40702', 'خصم مسموح به — مقص فاين', 'revenue', '407'],
            ['40703', 'خصم مسموح به — قسم الأثاث', 'revenue', '407'],
            ['40704', 'خصم مسموح به — صالة العرض', 'revenue', '407'],
            ['42', 'أرباح فروق أسعار الصرف', 'revenue', '4'],
            ['43', 'أرباح بيع واستبعاد أصول ثابتة', 'revenue', '4'],
            ['44', 'زيادة الخزائن', 'revenue', '4'],
            ['45', 'أرباح فروقات الجرد', 'revenue', '4'],

            // ==================== 5. المصروفات والتكاليف (Expenses) ====================
            ['5', 'المصروفات والتكاليف', 'expense', null],
            ['51', 'تكلفة البضاعة المباعة', 'expense', '5'],
            ['52', 'فروقات المخزون والتصنيع', 'expense', '5'],
            ['53', 'خسائر فروق أسعار الصرف', 'expense', '5'],
            ['54', 'المصروفات العامة والصناعية', 'expense', '5'],
            ['55', 'مصروف الإهلاك', 'expense', '5'],
            ['56', 'خسائر استبعاد أصول ثابتة', 'expense', '5'],
            ['57', 'مصروف الرواتب والأجور', 'expense', '5'],
            ['403', 'المشتريات', 'expense', '5'],
            ['40301', 'مشتريات — مصنع الإسفنج', 'expense', '403'],
            ['40302', 'مشتريات — مقص فاين', 'expense', '403'],
            ['405', 'مردودات المشتريات', 'expense', '5'],
            ['40501', 'مردودات مشتريات — مصنع الإسفنج', 'expense', '405'],
            ['40502', 'مردودات مشتريات — مقص فاين', 'expense', '405'],
            ['406', 'مصاريف الشراء والنقل', 'expense', '5'],

            ['6', 'المصاريف التشغيلية والإدارية', 'expense', null],
            ['601', 'مرتبات ومكافآت', 'expense', '6'],
            ['60101', 'مرتبات ومكافآت — مصنع الإسفنج', 'expense', '601'],
            ['60102', 'مرتبات ومكافآت — مقص فاين', 'expense', '601'],
            ['602', 'إهلاكات الأصول', 'expense', '6'],
            ['603', 'نثريات', 'expense', '6'],
            ['60301', 'نثريات — مصنع الإسفنج', 'expense', '603'],
            ['60302', 'نثريات — مقص فاين', 'expense', '603'],
            ['606', 'ضريبة دمغة', 'expense', '6'],
            ['607', 'ضريبة المرتبات والأجور', 'expense', '6'],
            ['608', 'الاشتراكات الضمانية', 'expense', '6'],
            ['609', 'مصاريف إدارية وعمومية', 'expense', '6'],
            ['60901', 'مصاريف إدارية — مصنع الإسفنج', 'expense', '609'],
            ['60902', 'مصاريف إدارية — مقص فاين', 'expense', '609'],
            ['610', 'عمولات ومصاريف بنكية', 'expense', '6'],
            ['611', 'كهرباء', 'expense', '6'],
            ['61101', 'كهرباء — مصنع الإسفنج', 'expense', '611'],
            ['61102', 'كهرباء — مقص فاين', 'expense', '611'],
            ['612', 'مياه', 'expense', '6'],
            ['61201', 'مياه — مصنع الإسفنج', 'expense', '612'],
            ['61202', 'مياه — مقص فاين', 'expense', '612'],
            ['613', 'هاتف وإنترنت', 'expense', '6'],
            ['61301', 'هاتف وإنترنت — مصنع الإسفنج', 'expense', '613'],
            ['61302', 'هاتف وإنترنت — مقص فاين', 'expense', '613'],
            ['616', 'قرطاسية ومطبوعات', 'expense', '6'],
            ['617', 'مصاريف تخليص جمركي', 'expense', '6'],
            ['618', 'أجرة نقل مواد خام', 'expense', '6'],
            ['61801', 'نقل مواد خام — مصنع الإسفنج', 'expense', '618'],
            ['61802', 'نقل مواد خام — مقص فاين', 'expense', '618'],
            ['619', 'أجرة نقل وتوزيع منتجات', 'expense', '6'],
            ['61901', 'نقل وتوزيع — مصنع الإسفنج', 'expense', '619'],
            ['61902', 'نقل وتوزيع — مقص فاين', 'expense', '619'],
            ['621', 'محروقات ووقود', 'expense', '6'],
            ['62101', 'محروقات — مصنع الإسفنج', 'expense', '621'],
            ['62102', 'محروقات — مقص فاين', 'expense', '621'],
            ['622', 'صيانة وتشغيل عام', 'expense', '6'],
            ['62201', 'صيانة وتشغيل — مصنع الإسفنج', 'expense', '622'],
            ['62202', 'صيانة وتشغيل — مقص فاين', 'expense', '622'],
            ['624', 'دعاية وإعلان وتسويق', 'expense', '6'],
            ['625', 'عجز الخزائن', 'expense', '6'],

            ['7', 'العمليات الصناعية', 'expense', null],
            ['701', 'العمليات الصناعية — مصنع الإسفنج', 'expense', '7'],
            ['702', 'العمليات الصناعية — مقص فاين', 'expense', '7'],
        ];

        $catalogByCode = [];
        foreach ($catalog as $row) {
            $catalogByCode[(string) $row[0]] = [$row[0], $row[1], $row[2], $row[3], $row[4] ?? 'LYD', null];
        }

        if ($importPath !== null && file_exists($importPath)) {
            $raw = json_decode((string) file_get_contents($importPath), true);

            foreach (is_array($raw) ? $raw : [] as $item) {
                $code = (string) ($item['account_code'] ?? $item[0]);
                $parentCode = array_key_exists('parent_code', $item)
                    ? ($item['parent_code'] !== null ? (string) $item['parent_code'] : null)
                    : ($item[3] ?? null);

                $catalogByCode[$code] = [
                    $code,
                    (string) ($item['name'] ?? $item[1]),
                    (string) ($item['type'] ?? $item[2]),
                    in_array($code, ['6', '7'], true) ? null : $parentCode,
                    (string) ($item['currency'] ?? $item[4] ?? $company->default_currency ?? 'LYD'),
                    isset($item['section']) ? (string) $item['section'] : null,
                ];
            }
        }

        $catalog = array_values($catalogByCode);

        $targetCodes = array_column($catalog, 0);

        // Remove legacy unlisted accounts that nothing uses: no journal lines and
        // no client, supplier, treasury, item, unit or asset pointing at them.
        // Auto-created sub-accounts (a client's own receivable, say) are linked,
        // so they survive a reseed.
        $unused = fn () => $this->withoutReferences(
            Account::whereNotIn('account_code', $targetCodes)->whereDoesntHave('journalLines')
        );

        $unused()->update(['parent_account_id' => null]);
        $deletedCount = $unused()->delete();

        if ($deletedCount > 0) {
            $this->command?->info("Removed {$deletedCount} unused legacy sub-accounts.");
        }

        // Map of created accounts by code
        $created = [];
        foreach (Account::all() as $acc) {
            $created[$acc->account_code] = $acc;
        }

        foreach ($catalog as $row) {
            $code = $row[0];
            $name = $row[1];
            $type = $row[2];
            $parentCode = $row[3] ?? null;
            $currency = $row[4] ?? $company->default_currency ?? 'LYD';
            $section = $row[5] ?? null;

            $parentId = $parentCode !== null && isset($created[$parentCode])
                ? $created[$parentCode]->id
                : null;

            $payload = [
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
                'parent_account_id' => $parentId,
            ];
            if ($section !== null) {
                $payload['section'] = $section;
            }

            $account = Account::updateOrCreate(
                [
                    'chart_of_accounts_id' => $coa->id,
                    'account_code' => $code,
                ],
                $payload,
            );

            $created[$code] = $account;
        }

        // Final pass: ensure all parent references are linked properly
        foreach ($catalog as $row) {
            $code = $row[0];
            $parentCode = $row[3] ?? null;

            $expectedParentId = $parentCode !== null && isset($created[$parentCode])
                ? $created[$parentCode]->id
                : null;

            $account = $created[$code];
            if ($account->parent_account_id !== $expectedParentId) {
                $account->update(['parent_account_id' => $expectedParentId]);
            }
        }

        $count = count($catalog);
        $this->command?->info("Unified chart of accounts seeded successfully with {$count} master and operational accounts.");
    }

    /**
     * Exclude accounts that any other table still references.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    private function withoutReferences(Builder $query): Builder
    {
        $references = [
            'clients' => 'account_id',
            'suppliers' => 'account_id',
            'cash_accounts' => 'account_id',
            'inventory_item_accounts' => 'account_id',
            'operating_unit_accounts' => 'account_id',
            'fixed_assets' => 'account_id',
            'operating_units' => 'revenue_account_id',
            'purchase_orders' => 'payment_source_account_id',
        ];

        foreach ($references as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $query->whereNotIn('id', DB::table($table)->whereNotNull($column)->select($column));
            }
        }

        return $query;
    }
}
