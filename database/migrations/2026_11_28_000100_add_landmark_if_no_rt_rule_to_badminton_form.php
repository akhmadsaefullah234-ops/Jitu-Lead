<?php

use App\LeadForms\FormFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Tas Badminton form (lead_forms id 4, Quicker Sport) asks for a house number or landmark only when the address
 * has no RT/RW ("landmark_if_no_rt"), on its required address fields. "house_or_landmark" is dropped there: with an
 * RT/RW the house number and landmark are optional and must not hold an order.
 */
return new class extends Migration
{
    private const FORM_ID = 4;

    public function up(): void
    {
        $this->change(function (array $rules) {
            $rules = array_values(array_diff($rules, ['house_or_landmark']));

            return in_array('landmark_if_no_rt', $rules, true) ? $rules : [...$rules, 'landmark_if_no_rt'];
        });
    }

    public function down(): void
    {
        $this->change(fn (array $rules) => array_values(array_diff($rules, ['landmark_if_no_rt'])));
    }

    private function change(Closure $rules): void
    {
        $form = DB::table('lead_forms')->where('id', self::FORM_ID)->first(['id', 'fields']);
        $fields = $form ? json_decode((string) $form->fields, true) : null;

        if (! is_array($fields)) {
            return;
        }

        $changed = false;

        foreach ($fields as $i => $field) {
            if (! is_array($field) || ($field['type'] ?? null) !== 'address') {
                continue;
            }

            $new = array_values(array_intersect(array_keys(FormFields::ADDRESS_RULES), $rules((array) ($field['rules'] ?? []))));

            if ($new !== (array) ($field['rules'] ?? [])) {
                $fields[$i]['rules'] = $new === [] ? null : $new;
                $fields[$i] = array_filter($fields[$i], fn ($v) => $v !== null);
                $changed = true;
            }
        }

        if ($changed) {
            DB::table('lead_forms')->where('id', self::FORM_ID)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }
};
