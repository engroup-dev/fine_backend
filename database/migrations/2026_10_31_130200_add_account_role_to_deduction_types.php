<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-03 (HR-13): a deduction type says where payroll credits it.
     * `liability` is withheld for a third party (225: social security, tax);
     * `expense_reduction` is kept by the company and reduces wage expense
     * (57: absence, lateness, penalties). Existing databases get the seeded
     * roles; a role already set by hand is left alone. DED-ADV has none:
     * advance recovery comes from the advance schedule (CR 1215).
     */
    private const ROLES = [
        'DED-SSF' => 'liability',
        'DED-TAX' => 'liability',
        'DED-ABS' => 'expense_reduction',
        'DED-LATE' => 'expense_reduction',
        'DED-PEN' => 'expense_reduction',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('reference_lookups')) {
            return;
        }

        $rows = DB::table('reference_lookups')
            ->where('category', 'deduction-types')
            ->whereIn('code', array_keys(self::ROLES))
            ->get(['id', 'code', 'fields']);

        foreach ($rows as $row) {
            $fields = json_decode((string) ($row->fields ?? ''), true);
            $fields = is_array($fields) ? $fields : [];

            if (isset($fields['account_role']) && $fields['account_role'] !== '') {
                continue;
            }

            $fields['account_role'] = self::ROLES[$row->code];

            DB::table('reference_lookups')->where('id', $row->id)->update([
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // The role is data an admin may have edited since; it is left in place.
    }
};
