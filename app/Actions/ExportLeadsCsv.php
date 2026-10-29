<?php

namespace App\Actions;

use App\Models\Lead;
use App\Support\Industry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writes leads as a CSV that opens cleanly in Excel and Google Sheets
 * (UTF-8 with BOM, semicolons are not needed because values are quoted).
 */
class ExportLeadsCsv
{
    /** @return list<string> the column titles; the middle ones are the agency's industry fields */
    public static function header(Industry $industry): array
    {
        return [
            'Nama', 'Telepon', 'Email', 'Tahap', 'Minat', 'Agen', 'Sumber',
            ...array_values($industry->leadFields()),
            'Aksi berikutnya', 'Tenggat aksi', 'Jadwal '.mb_strtolower($industry->term('visit')), 'Nilai deal', 'Masuk',
        ];
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  resource  $out
     */
    public function __invoke(Builder $query, $out): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        $industry = Industry::current();
        fputcsv($out, self::header($industry), ',', '"', '');

        $count = 0;

        $query->with(['stage', 'owner', 'source', 'property'])->reorder('id')->chunkById(200, function ($leads) use ($out, &$count, $industry) {
            foreach ($leads as $lead) {
                fputcsv($out, array_map($this->safe(...), [
                    $lead->name,
                    $lead->phone,
                    $lead->email,
                    $lead->stage?->name,
                    $lead->interest?->getLabel(),
                    $lead->owner?->name,
                    $lead->source?->name,
                    ...array_map(fn (string $field) => $this->fieldValue($lead, $field), array_keys($industry->leadFields())),
                    $lead->next_action,
                    $lead->next_action_due_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    $lead->survey_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    $lead->deal_value,
                    $lead->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ]), ',', '"', '');
                $count++;
            }
        });

        return $count;
    }

    private function fieldValue(Lead $lead, string $field): mixed
    {
        return match ($field) {
            'property_id' => $lead->property?->name,
            'need' => $lead->need?->getLabel(),
            'payment_method' => $lead->payment_method?->getLabel(),
            default => $lead->{$field},
        };
    }

    /** Stops spreadsheet apps from running a cell as a formula. */
    private function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Phone numbers legitimately start with "+"; they are validated digits only.
        if (preg_match('/^\+\d+$/', $value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
