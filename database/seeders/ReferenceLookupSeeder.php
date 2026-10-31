<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ReferenceLookup;
use Illuminate\Database\Seeder;

final class ReferenceLookupSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = [
            'measurement-units' => [
                ['code' => 'KG', 'name' => 'كيلوغرام', 'fields' => ['symbol' => 'كجم', 'dimension' => 'weight'], 'notes' => 'وزن المواد الكيميائية والخامات'],
                ['code' => 'G', 'name' => 'غرام', 'fields' => ['symbol' => 'غم', 'dimension' => 'weight']],
                ['code' => 'TON', 'name' => 'طن', 'fields' => ['symbol' => 'طن', 'dimension' => 'weight'], 'notes' => 'تستخدم في أوامر الاستيراد'],
                ['code' => 'M', 'name' => 'متر', 'fields' => ['symbol' => 'م', 'dimension' => 'length']],
                ['code' => 'CM', 'name' => 'سنتيمتر', 'fields' => ['symbol' => 'سم', 'dimension' => 'length'], 'notes' => 'أبعاد القص والشرائح'],
                ['code' => 'M2', 'name' => 'متر مربع', 'fields' => ['symbol' => 'م²', 'dimension' => 'area']],
                ['code' => 'M3', 'name' => 'متر مكعب', 'fields' => ['symbol' => 'م³', 'dimension' => 'volume'], 'notes' => 'حجم قوالب الإسفنج'],
                ['code' => 'L', 'name' => 'لتر', 'fields' => ['symbol' => 'ل', 'dimension' => 'volume']],
                ['code' => 'PCS', 'name' => 'قطعة', 'fields' => ['symbol' => 'قطعة', 'dimension' => 'count']],
                ['code' => 'BLK', 'name' => 'قالب', 'fields' => ['symbol' => 'قالب', 'dimension' => 'count'], 'notes' => 'قالب إسفنج كامل قبل التقطيع'],
                ['code' => 'PLT', 'name' => 'منصة نقالة', 'fields' => ['symbol' => 'منصة', 'dimension' => 'count']],
                ['code' => 'DRM', 'name' => 'برميل', 'fields' => ['symbol' => 'برميل', 'dimension' => 'count'], 'is_active' => false, 'notes' => 'موقوفة لحين توحيد أحجام البراميل'],
                ['code' => 'HR', 'name' => 'ساعة عمل', 'fields' => ['symbol' => 'س', 'dimension' => 'time']],
            ],
            'currencies' => [
                ['code' => 'LYD', 'name' => 'الدينار الليبي', 'fields' => ['symbol' => 'د.ل'], 'notes' => 'العملة الأساسية للقيود المحاسبية'],
                ['code' => 'USD', 'name' => 'الدولار الأمريكي', 'fields' => ['symbol' => '$'], 'notes' => 'أوامر الاستيراد والاعتمادات'],
                ['code' => 'EUR', 'name' => 'اليورو', 'fields' => ['symbol' => '€'], 'notes' => 'توريدات الآلات والمواد الأوروبية'],
            ],
            'banks' => [
                ['code' => 'JUM', 'name' => 'مصرف الجمهورية', 'fields' => ['branch' => 'فرع طبرق'], 'notes' => 'الحساب التشغيلي الرئيسي'],
                ['code' => 'WAH', 'name' => 'مصرف الوحدة', 'fields' => ['branch' => 'فرع طبرق المركزي']],
                ['code' => 'NCB', 'name' => 'المصرف التجاري الوطني', 'fields' => ['branch' => 'فرع بنغازي']],
                ['code' => 'BCD', 'name' => 'مصرف التجارة والتنمية', 'fields' => ['branch' => 'فرع طرابلس'], 'notes' => 'يستخدم لفتح الاعتمادات المستندية'],
                ['code' => 'SAH', 'name' => 'مصرف الصحاري', 'fields' => ['branch' => 'فرع درنة'], 'is_active' => false],
            ],
            'nationalities' => [
                ['code' => 'LY', 'name' => 'ليبية', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'EG', 'name' => 'مصرية', 'fields' => ['country' => 'مصر']],
                ['code' => 'TN', 'name' => 'تونسية', 'fields' => ['country' => 'تونس']],
                ['code' => 'SD', 'name' => 'سودانية', 'fields' => ['country' => 'السودان']],
                ['code' => 'BD', 'name' => 'بنغلاديشية', 'fields' => ['country' => 'بنغلاديش'], 'notes' => 'عمالة متعاقدة عبر وكالات توظيف'],
                ['code' => 'PH', 'name' => 'فلبينية', 'fields' => ['country' => 'الفلبين']],
                ['code' => 'IN', 'name' => 'هندية', 'fields' => ['country' => 'الهند']],
                ['code' => 'NG', 'name' => 'نيجيرية', 'fields' => ['country' => 'نيجيريا']],
            ],
            'cities' => [
                ['code' => 'TOB', 'name' => 'طبرق', 'fields' => ['country' => 'ليبيا'], 'notes' => 'مقر الشركة والمصنع'],
                ['code' => 'BNG', 'name' => 'بنغازي', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'TIP', 'name' => 'طرابلس', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'MSR', 'name' => 'مصراتة', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'DRN', 'name' => 'درنة', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'BDA', 'name' => 'البيضاء', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'AJD', 'name' => 'أجدابيا', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'SRT', 'name' => 'سرت', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'SBH', 'name' => 'سبها', 'fields' => ['country' => 'ليبيا']],
                ['code' => 'ZAW', 'name' => 'الزاوية', 'fields' => ['country' => 'ليبيا']],
            ],
            'employee-categories' => [
                ['code' => 'PROD', 'name' => 'عامل إنتاج', 'notes' => 'خطوط الفوم والتقطيع والتجميع'],
                ['code' => 'TECH', 'name' => 'فني تشغيل وصيانة'],
                ['code' => 'SUPV', 'name' => 'مشرف وردية'],
                ['code' => 'ADMIN', 'name' => 'موظف إداري'],
                ['code' => 'ACC', 'name' => 'محاسب'],
                ['code' => 'SALES', 'name' => 'مندوب مبيعات'],
                ['code' => 'DRV', 'name' => 'سائق'],
                ['code' => 'SEC', 'name' => 'حارس أمن'],
            ],
            'expense-types' => [
                ['code' => 'EXP-RENT', 'name' => 'إيجارات'],
                ['code' => 'EXP-UTIL', 'name' => 'كهرباء وماء'],
                ['code' => 'EXP-FUEL', 'name' => 'وقود وزيوت', 'notes' => 'مولدات المصنع وأسطول النقل'],
                ['code' => 'EXP-MAINT', 'name' => 'صيانة آلات ومعدات'],
                ['code' => 'EXP-FRGT', 'name' => 'نقل وشحن'],
                ['code' => 'EXP-STAT', 'name' => 'قرطاسية ومطبوعات'],
                ['code' => 'EXP-COMM', 'name' => 'اتصالات وإنترنت'],
                ['code' => 'EXP-HOST', 'name' => 'ضيافة ونظافة'],
            ],
            'fixed-asset-categories' => [
                ['code' => 'FA-BLD', 'name' => 'مباني ومنشآت'],
                ['code' => 'FA-MACH', 'name' => 'آلات ومعدات إنتاج', 'notes' => 'خطوط الفوم وماكينات التقطيع'],
                ['code' => 'FA-VEH', 'name' => 'سيارات ومركبات'],
                ['code' => 'FA-FURN', 'name' => 'أثاث ومفروشات'],
                ['code' => 'FA-IT', 'name' => 'أجهزة حاسوب وشبكات'],
                ['code' => 'FA-TOOL', 'name' => 'عدد وأدوات'],
            ],
            'allowance-types' => [
                ['code' => 'ALW-PROD', 'name' => 'علاوة إنتاج', 'fields' => ['calculation' => 'percentage', 'value' => '10'], 'notes' => 'تُحتسب عند تجاوز المستهدف الشهري'],
                ['code' => 'ALW-TRN', 'name' => 'بدل نقل', 'fields' => ['calculation' => 'fixed', 'value' => '150']],
                ['code' => 'ALW-HOU', 'name' => 'بدل سكن', 'fields' => ['calculation' => 'fixed', 'value' => '300']],
                ['code' => 'ALW-RISK', 'name' => 'علاوة طبيعة عمل', 'fields' => ['calculation' => 'percentage', 'value' => '7.5'], 'notes' => 'لعمال خطوط الكيماويات'],
                ['code' => 'ALW-OT', 'name' => 'ساعات إضافية', 'fields' => ['calculation' => 'fixed', 'value' => '12'], 'notes' => 'القيمة عن كل ساعة إضافية'],
                ['code' => 'ALW-PERF', 'name' => 'مكافأة أداء', 'fields' => ['calculation' => 'fixed', 'value' => '0']],
            ],
            // W5-03 (HR-13): account_role routes a payroll deduction: liability
            // credits 225, expense_reduction credits 57. DED-ADV has none (CR 1215
            // from the advance schedule).
            'deduction-types' => [
                ['code' => 'DED-SSF', 'name' => 'تأمين اجتماعي', 'fields' => ['calculation' => 'percentage', 'value' => '3.75', 'account_role' => 'liability'], 'notes' => 'حصة الموظف من الضمان الاجتماعي'],
                ['code' => 'DED-TAX', 'name' => 'ضريبة دخل', 'fields' => ['calculation' => 'percentage', 'value' => '5', 'account_role' => 'liability']],
                ['code' => 'DED-ADV', 'name' => 'سلفة موظف', 'fields' => ['calculation' => 'fixed', 'value' => '0'], 'notes' => 'تُقسَّط حسب اتفاق السلفة'],
                ['code' => 'DED-ABS', 'name' => 'خصم غياب', 'fields' => ['calculation' => 'fixed', 'value' => '0', 'account_role' => 'expense_reduction']],
                ['code' => 'DED-LATE', 'name' => 'خصم تأخير', 'fields' => ['calculation' => 'fixed', 'value' => '0', 'account_role' => 'expense_reduction']],
                ['code' => 'DED-PEN', 'name' => 'جزاء إداري', 'fields' => ['calculation' => 'fixed', 'value' => '0', 'account_role' => 'expense_reduction'], 'is_active' => false],
            ],
            // Warehouses and their sub-locations are real `warehouses` rows
            // (self-referencing parent_id) now, not reference-lookup seed data.
            'location-types' => [
                ['code' => 'SHELF', 'name' => 'رف'],
                ['code' => 'FLOOR', 'name' => 'منطقة أرضية'],
                ['code' => 'CONTAINER', 'name' => 'حاوية/برميل'],
            ],
        ];

        foreach ($catalogs as $category => $items) {
            foreach ($items as $item) {
                ReferenceLookup::updateOrCreate(
                    [
                        'category' => $category,
                        'code' => $item['code'],
                    ],
                    [
                        'name' => $item['name'],
                        'is_active' => $item['is_active'] ?? true,
                        'notes' => $item['notes'] ?? null,
                        'fields' => $item['fields'] ?? [],
                    ]
                );
            }
        }
    }
}
