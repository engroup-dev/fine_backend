<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permissions and role defaults (W0-10)
|--------------------------------------------------------------------------
|
| Single source for RoleSeeder, the permissions migration and the Role
| model's `created` hook. Each route group in routes/api.php keeps its
| require.role list and adds require.module:<view>,<write>; every role a
| group allows starts with both of that group's permissions, so access is
| unchanged on day one and admins can narrow a role from the Roles screen.
|
| `roles` are created by the seeder; `role_permissions` gives each role
| (and any role later created under the same slug) its defaults.
| owner bypasses every permission check; admin holds every permission.
*/

return [
    'permissions' => [
        ['slug' => 'manage-users', 'name' => 'إدارة المستخدمين', 'module' => 'users', 'action' => 'manage'],
        ['slug' => 'view-users', 'name' => 'عرض المستخدمين', 'module' => 'users', 'action' => 'view'],
        ['slug' => 'create-users', 'name' => 'إنشاء مستخدمين', 'module' => 'users', 'action' => 'create'],
        ['slug' => 'edit-users', 'name' => 'تعديل المستخدمين', 'module' => 'users', 'action' => 'edit'],
        ['slug' => 'delete-users', 'name' => 'حذف المستخدمين', 'module' => 'users', 'action' => 'delete'],
        ['slug' => 'create-procurement', 'name' => 'إنشاء مشتريات', 'module' => 'procurement', 'action' => 'create'],
        ['slug' => 'view-procurement', 'name' => 'عرض المشتريات', 'module' => 'procurement', 'action' => 'view'],
        ['slug' => 'edit-procurement', 'name' => 'تعديل المشتريات', 'module' => 'procurement', 'action' => 'edit'],
        ['slug' => 'create-treasury', 'name' => 'إنشاء عمليات خزانة', 'module' => 'treasury', 'action' => 'create'],
        ['slug' => 'view-treasury', 'name' => 'عرض الخزانة', 'module' => 'treasury', 'action' => 'view'],
        ['slug' => 'approve-treasury', 'name' => 'اعتماد عمليات الخزانة', 'module' => 'treasury', 'action' => 'approve'],
        ['slug' => 'settle-payables', 'name' => 'تسوية الذمم الدائنة', 'module' => 'treasury', 'action' => 'settle'],
        ['slug' => 'view-inventory', 'name' => 'عرض المخزون', 'module' => 'inventory', 'action' => 'view'],
        ['slug' => 'create-foam', 'name' => 'إنشاء إنتاج إسفنج', 'module' => 'foam', 'action' => 'create'],
        ['slug' => 'view-foam', 'name' => 'عرض الإسفنج', 'module' => 'foam', 'action' => 'view'],
        ['slug' => 'edit-foam', 'name' => 'تعديل الإسفنج', 'module' => 'foam', 'action' => 'edit'],
        ['slug' => 'grade-foam', 'name' => 'تصنيف الإسفنج', 'module' => 'foam', 'action' => 'grade'],
        ['slug' => 'create-cutting', 'name' => 'إنشاء عمليات قص', 'module' => 'cutting', 'action' => 'create'],
        ['slug' => 'view-cutting', 'name' => 'عرض القص', 'module' => 'cutting', 'action' => 'view'],
        ['slug' => 'edit-cutting', 'name' => 'تعديل القص', 'module' => 'cutting', 'action' => 'edit'],
        ['slug' => 'create-furniture', 'name' => 'إنشاء أثاث', 'module' => 'furniture', 'action' => 'create'],
        ['slug' => 'view-furniture', 'name' => 'عرض الأثاث', 'module' => 'furniture', 'action' => 'view'],
        ['slug' => 'edit-furniture', 'name' => 'تعديل الأثاث', 'module' => 'furniture', 'action' => 'edit'],
        ['slug' => 'create-sales', 'name' => 'إنشاء مبيعات', 'module' => 'sales', 'action' => 'create'],
        ['slug' => 'create-sales-returns', 'name' => 'إنشاء مرتجعات المبيعات', 'module' => 'sales', 'action' => 'create'],
        ['slug' => 'view-sales', 'name' => 'عرض المبيعات', 'module' => 'sales', 'action' => 'view'],
        ['slug' => 'edit-sales', 'name' => 'تعديل المبيعات', 'module' => 'sales', 'action' => 'edit'],
        ['slug' => 'edit-inventory', 'name' => 'تعديل المخزون', 'module' => 'inventory', 'action' => 'edit'],
        ['slug' => 'approve-stock-counts', 'name' => 'اعتماد الجرد', 'module' => 'inventory', 'action' => 'approve'],
        ['slug' => 'view-accounting', 'name' => 'عرض الحسابات والتقارير المالية', 'module' => 'accounting', 'action' => 'view'],
        ['slug' => 'manage-accounting', 'name' => 'إدارة المحاسبة والقيود', 'module' => 'accounting', 'action' => 'manage'],
        ['slug' => 'view-debtor-statements', 'name' => 'عرض كشوف المدينين', 'module' => 'accounting', 'action' => 'view-statements'],
        ['slug' => 'view-hr', 'name' => 'عرض الموارد البشرية', 'module' => 'hr', 'action' => 'view'],
        ['slug' => 'manage-hr', 'name' => 'إدارة الموارد البشرية والرواتب', 'module' => 'hr', 'action' => 'manage'],
        ['slug' => 'approve-advances', 'name' => 'اعتماد وصرف سلف وقروض الموظفين', 'module' => 'hr', 'action' => 'approve'],
        ['slug' => 'view-material-requests', 'name' => 'عرض طلبات المواد', 'module' => 'production', 'action' => 'view-requests'],
        ['slug' => 'manage-material-requests', 'name' => 'تنفيذ طلبات المواد', 'module' => 'production', 'action' => 'manage-requests'],
        ['slug' => 'approve-credit', 'name' => 'اعتماد تجاوز حد الائتمان', 'module' => 'sales', 'action' => 'approve-credit'],
        ['slug' => 'approve-price-override', 'name' => 'الموافقة على البيع تحت الحد الأدنى للسعر', 'module' => 'sales', 'action' => 'approve'],
        ['slug' => 'view-clients', 'name' => 'عرض العملاء', 'module' => 'clients', 'action' => 'view'],
        ['slug' => 'edit-clients', 'name' => 'إدارة العملاء', 'module' => 'clients', 'action' => 'edit'],
        ['slug' => 'manage-settings', 'name' => 'إدارة بيانات الإعدادات', 'module' => 'settings', 'action' => 'manage'],
    ],

    'roles' => [
        'owner' => ['name' => 'المالك', 'description' => 'مالك النظام العام بتحكم كامل.'],
        'admin' => ['name' => 'المدير العام', 'description' => 'مدير عام بوصول إداري كامل.'],
        'manager' => ['name' => 'مدير الوحدة', 'description' => 'مدير وحدة تشغيلية بصلاحيات تشغيلية ومالية واسعة.'],
        'unit_manager' => ['name' => 'مدير وحدة تشغيلية', 'description' => 'مدير وحدة تشغيلية محددة بنفس صلاحيات المدير ضمن وحدته.'],
        'inventory-manager' => ['name' => 'مدير المخزون', 'description' => 'إدارة المخازن والأصناف وحركة المخزون.'],
        'accounting-manager' => ['name' => 'مدير المحاسبة', 'description' => 'إدارة الشؤون المالية والمحاسبية.'],
        'hr-manager' => ['name' => 'مدير الموارد البشرية', 'description' => 'إدارة الموارد البشرية وكشوف الرواتب.'],
        'procurement-manager' => ['name' => 'مدير المشتريات', 'description' => 'إدارة المشتريات وعمليات الشراء.'],
        'treasury-officer' => ['name' => 'مسؤول الخزانة', 'description' => 'إدارة الخزانة والتدفقات النقدية.'],
        'foam-manager' => ['name' => 'مدير مصنع الإسفنج', 'description' => 'إدارة مصنع إنتاج الإسفنج.'],
        'foam-operator' => ['name' => 'مشغل الإسفنج', 'description' => 'مشغل آلات مصنع الإسفنج.'],
        'cutter-manager' => ['name' => 'مدير قسم القص', 'description' => 'إدارة قسم القص.'],
        'cutter-operator' => ['name' => 'مشغل القص', 'description' => 'مشغل آلة القص.'],
        'furniture-manager' => ['name' => 'مدير قسم الأثاث', 'description' => 'إدارة قسم تجميع الأثاث.'],
        'assembler' => ['name' => 'مجمع الأثاث', 'description' => 'عامل في تجميع الأثاث.'],
        'store-manager' => ['name' => 'مدير صالة العرض', 'description' => 'إدارة صالة العرض والمبيعات.'],
        'pos-cashier' => ['name' => 'كاشير نقطة البيع', 'description' => 'كاشير نقطة البيع.'],
    ],

    'role_permissions' => [
        'admin' => ['*'],
        'accounting-manager' => ['approve-advances', 'approve-credit', 'approve-price-override', 'approve-stock-counts', 'create-sales-returns', 'create-treasury', 'edit-clients', 'edit-inventory', 'edit-procurement', 'manage-accounting', 'manage-hr', 'settle-payables', 'view-accounting', 'view-clients', 'view-debtor-statements', 'view-hr', 'view-inventory', 'view-procurement', 'view-treasury'],
        'assembler' => ['edit-furniture', 'edit-inventory', 'view-accounting', 'view-furniture', 'view-inventory'],
        'cutter-manager' => ['create-cutting', 'create-sales', 'create-treasury', 'edit-cutting', 'edit-inventory', 'edit-procurement', 'manage-hr', 'manage-material-requests', 'view-accounting', 'view-cutting', 'view-hr', 'view-inventory', 'view-material-requests', 'view-procurement', 'view-sales', 'view-treasury'],
        'cutter-operator' => ['edit-cutting', 'edit-inventory', 'view-accounting', 'view-cutting', 'view-inventory'],
        'foam-manager' => ['create-foam', 'create-treasury', 'edit-foam', 'edit-inventory', 'edit-procurement', 'grade-foam', 'manage-hr', 'manage-material-requests', 'view-accounting', 'view-foam', 'view-hr', 'view-inventory', 'view-material-requests', 'view-procurement', 'view-treasury'],
        'foam-operator' => ['edit-foam', 'edit-inventory', 'view-accounting', 'view-foam', 'view-inventory'],
        'furniture-manager' => ['create-furniture', 'create-treasury', 'edit-furniture', 'edit-inventory', 'edit-procurement', 'manage-hr', 'manage-material-requests', 'view-accounting', 'view-furniture', 'view-hr', 'view-inventory', 'view-material-requests', 'view-procurement', 'view-treasury'],
        'hr-manager' => ['approve-advances', 'create-treasury', 'create-users', 'edit-procurement', 'edit-users', 'manage-hr', 'manage-users', 'view-hr', 'view-procurement', 'view-treasury', 'view-users'],
        'inventory-manager' => ['create-treasury', 'edit-inventory', 'edit-procurement', 'view-accounting', 'view-inventory', 'view-procurement', 'view-treasury'],
        'manager' => ['approve-credit', 'approve-price-override', 'create-sales', 'create-sales-returns', 'create-treasury', 'edit-clients', 'edit-cutting', 'edit-foam', 'edit-inventory', 'edit-procurement', 'manage-hr', 'manage-material-requests', 'settle-payables', 'view-accounting', 'view-clients', 'view-cutting', 'view-debtor-statements', 'view-foam', 'view-hr', 'view-inventory', 'view-material-requests', 'view-procurement', 'view-sales', 'view-treasury'],
        'pos-cashier' => ['create-sales', 'edit-clients', 'edit-inventory', 'view-accounting', 'view-clients', 'view-inventory', 'view-sales'],
        'procurement-manager' => ['create-procurement', 'create-treasury', 'edit-clients', 'edit-inventory', 'edit-procurement', 'manage-hr', 'view-accounting', 'view-clients', 'view-hr', 'view-inventory', 'view-procurement', 'view-treasury'],
        'store-manager' => ['approve-credit', 'approve-price-override', 'create-sales', 'create-sales-returns', 'create-treasury', 'edit-clients', 'edit-inventory', 'edit-procurement', 'edit-sales', 'manage-hr', 'view-accounting', 'view-clients', 'view-hr', 'view-inventory', 'view-procurement', 'view-sales', 'view-treasury'],
        'treasury-officer' => ['approve-treasury', 'create-treasury', 'edit-inventory', 'edit-procurement', 'settle-payables', 'view-accounting', 'view-debtor-statements', 'view-inventory', 'view-procurement', 'view-treasury'],
        'unit_manager' => ['approve-credit', 'approve-price-override', 'create-sales', 'create-sales-returns', 'create-treasury', 'edit-clients', 'edit-cutting', 'edit-foam', 'edit-inventory', 'edit-procurement', 'manage-hr', 'manage-material-requests', 'settle-payables', 'view-accounting', 'view-clients', 'view-cutting', 'view-debtor-statements', 'view-foam', 'view-hr', 'view-inventory', 'view-material-requests', 'view-procurement', 'view-sales', 'view-treasury'],
    ],
];
