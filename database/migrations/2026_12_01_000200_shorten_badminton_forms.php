<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quicker Sport's Tas Badminton forms, shortened for the ad test:
 * - form 4 (page A, 13 fields): postal code hidden (taken from the kelurahan) and "Alamat saya memakai: RT/RW |
 *   Dusun/kampung". It keeps its province dropdown until the test says otherwise.
 * - form 8 "Tas Badminton Premium (Form Pendek)" (page B, tas-badminton-premium-b): postal code hidden, dropdowns
 *   starting at the city/regency (province derived), and the soft RT/RW-or-dusun check on its one address box.
 * - page tas-badminton-premium gets the A/B test against tas-badminton-premium-b (served only at ?v=auto).
 * Nothing changes when the forms or pages are not there or belong to another agency.
 */
return new class extends Migration
{
    private const TENANT = 'quicker-sport';

    private const FORMS = [4 => ['hide_postal', 'rt_choice'], 8 => ['hide_postal', 'city_first', 'soft_rt_check']];

    private const PAGE_A = 'tas-badminton-premium';

    private const PAGE_B = 'tas-badminton-premium-b';

    public function up(): void
    {
        $tenant = DB::table('tenants')->where('slug', self::TENANT)->value('id');

        if ($tenant === null) {
            return;
        }

        foreach (self::FORMS as $id => $options) {
            $this->change($id, (int) $tenant, function (array $field) use ($options) {
                $field['address_options'] = array_values(array_unique([...(array) ($field['address_options'] ?? []), ...$options]));
                $parts = (array) ($field['parts'] ?? []);
                if ($parts !== [] && ! in_array('kode_pos', $parts, true)) {
                    $field['parts'] = [...$parts, 'kode_pos'];
                }

                return $field;
            });
        }

        $a = DB::table('landing_pages')->where('tenant_id', $tenant)->where('slug', self::PAGE_A)->first(['id', 'ab_test']);
        $b = DB::table('landing_pages')->where('tenant_id', $tenant)->where('slug', self::PAGE_B)->value('id');

        if ($a !== null && $b !== null && $a->ab_test === null) {
            DB::table('landing_pages')->where('id', $a->id)->update(['ab_test' => json_encode(['enabled' => true, 'partner_id' => (int) $b]), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $tenant = DB::table('tenants')->where('slug', self::TENANT)->value('id');

        if ($tenant === null) {
            return;
        }

        foreach (self::FORMS as $id => $options) {
            $this->change($id, (int) $tenant, function (array $field) use ($options) {
                $left = array_values(array_diff((array) ($field['address_options'] ?? []), $options));
                $field['address_options'] = $left === [] ? null : $left;

                return array_filter($field, fn ($v) => $v !== null);
            });
        }

        DB::table('landing_pages')->where('tenant_id', $tenant)->where('slug', self::PAGE_A)->update(['ab_test' => null]);
    }

    /** Applies $change to every address field of the form that fills the shipping address. */
    private function change(int $id, int $tenant, Closure $change): void
    {
        $form = DB::table('lead_forms')->where('id', $id)->where('tenant_id', $tenant)->first(['id', 'fields']);
        $fields = $form ? json_decode((string) $form->fields, true) : null;

        if (! is_array($fields)) {
            return;
        }

        $changed = false;

        foreach ($fields as $i => $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'address' && ($field['maps_to'] ?? null) === 'shipping_address') {
                $new = $change($field);
                $changed = $changed || $new !== $field;
                $fields[$i] = $new;
            }
        }

        if ($changed) {
            DB::table('lead_forms')->where('id', $id)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }
};
