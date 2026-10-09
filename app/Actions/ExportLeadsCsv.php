<?php

namespace App\Actions;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writes leads as a CSV that opens cleanly in Excel and Google Sheets
 * (UTF-8 with BOM, semicolons are not needed because values are quoted).
 */
class ExportLeadsCsv
{
    public const HEADER = [
        'Nama', 'Telepon', 'Email', 'Tahap', 'Minat', 'Agen', 'Sumber', 'Properti',
        'Kebutuhan', 'Tipe properti', 'Lokasi', 'Budget min', 'Budget maks', 'Cara bayar',
        'Aksi berikutnya', 'Tenggat aksi', 'Jadwal survei', 'Nilai deal', 'Masuk',
    ];

    /**
     * @param  Builder<Lead>  $query
     * @param  resource  $out
     */
    public function __invoke(Builder $query, $out): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, ',', '"', '');

        $count = 0;

        $query->with(['stage', 'owner', 'source', 'property'])->reorder('id')->chunkById(200, function ($leads) use ($out, &$count) {
            foreach ($leads as $lead) {
                fputcsv($out, array_map($this->safe(...), [
                    $lead->name,
                    $lead->phone,
                    $lead->email,
                    $lead->stage?->name,
                    $lead->interest?->getLabel(),
                    $lead->owner?->name,
                    $lead->source?->name,
                    $lead->property?->name,
                    $lead->need?->getLabel(),
                    $lead->property_type,
                    $lead->location,
                    $lead->budget_min,
                    $lead->budget_max,
                    $lead->payment_method?->getLabel(),
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
