<?php

namespace App\Actions;

use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Enums\PropertyKind;
use App\Enums\StageType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Fills an empty agency with sample properties and leads so the dashboard and
 * pipeline can be seen working, and removes exactly that sample data again.
 * Sample rows carry a "demo" flag; nothing the agency entered is touched.
 */
class DemoData
{
    private const NAMES = [
        'Budi Santoso', 'Siti Rahmawati', 'Andi Pratama', 'Dewi Lestari', 'Rizky Ramadhan', 'Hendra Wijaya', 'Lina Kusuma',
        'Fajar Nugroho', 'Ayu Wulandari', 'Joko Susilo', 'Ratna Sari', 'Agus Setiawan', 'Maya Anggraini', 'Eko Prasetyo',
        'Nia Ramadani', 'Tono Hartono', 'Putri Maharani', 'Bayu Aditya', 'Citra Dewi', 'Reza Firmansyah', 'Indah Permata',
        'Yoga Pranata', 'Lestari Handayani', 'Dedi Kurniawan', 'Wulan Sari', 'Arief Rahman', 'Tika Safitri', 'Galih Saputra',
        'Mega Puspita', 'Irfan Hakim', 'Nanda Kusumawati',
    ];

    /** [stage name, how many sample leads] */
    private const STAGES = [
        ['Lead baru', 6], ['Dihubungi', 5], ['Terkualifikasi', 4], ['Jadwal survei', 3], ['Sudah survei', 3],
        ['Negosiasi', 2], ['Booking', 2], ['Closing', 3], ['Gugur', 3],
    ];

    public function __construct(private CurrentTenant $current) {}

    public function hasLeads(): bool
    {
        return Lead::query()->exists();
    }

    public function hasDemo(): bool
    {
        return Lead::query()->whereJsonContains('custom_fields->demo', true)->exists()
            || Property::query()->whereJsonContains('details->demo', true)->exists();
    }

    public function load(): int
    {
        return DB::transaction(function () {
            $owners = $this->owners();
            $properties = $this->properties();
            $sources = LeadSource::query()->pluck('id')->all();
            $lost = LostReason::query()->pluck('id')->all();
            $created = 0;

            foreach (self::STAGES as [$stageName, $count]) {
                $stage = Stage::query()->where('name', $stageName)->first();

                if ($stage === null) {
                    continue;
                }

                for ($n = 0; $n < $count; $n++, $created++) {
                    $property = $properties[$created % count($properties)];
                    $owner = $owners === [] ? null : $owners[$created % count($owners)];
                    $age = intdiv($created * 28, 31) + ($created % 3);
                    $createdAt = now()->subDays(min(29, $age))->subHours($created % 9);

                    $lead = new Lead([
                        'name' => self::NAMES[$created % count(self::NAMES)],
                        'phone' => '08120000'.str_pad((string) (1000 + $created), 4, '0', STR_PAD_LEFT),
                        'lead_source_id' => $sources === [] ? null : $sources[($created * 3) % count($sources)],
                        'stage_id' => $stage->getKey(),
                        'owner_id' => $owner?->getKey(),
                        'interest' => [Interest::Hot, Interest::Warm, Interest::Cold][($created + $n) % 3],
                        'need' => Need::Buy,
                        'property_type' => $property->property_type,
                        'location' => $property->location,
                        'budget_min' => (int) ($property->price_from * 0.9),
                        'budget_max' => (int) ($property->price_from * 1.15),
                        'payment_method' => $created % 4 === 0 ? PaymentMethod::Cash : PaymentMethod::Kpr,
                        'property_id' => $property->getKey(),
                        'next_action' => $stage->default_action,
                        'custom_fields' => ['demo' => true],
                    ]);

                    $this->fillStage($lead, $stage, $property, $lost, $created);
                    $lead->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt->copy()->addHours(2 + $created % 20)]);
                    $lead->save();

                    $lead->activities()->create(['type' => 'created', 'body' => 'Lead masuk (data contoh)'])
                        ->forceFill(['created_at' => $createdAt])->save();
                }
            }

            return $created;
        });
    }

    public function clear(): int
    {
        return DB::transaction(function () {
            $leads = Lead::withTrashed()->whereJsonContains('custom_fields->demo', true)->get();
            $leads->each->forceDelete();
            Property::query()->whereJsonContains('details->demo', true)->delete();

            return $leads->count();
        });
    }

    private function fillStage(Lead $lead, Stage $stage, Property $property, array $lost, int $i): void
    {
        $due = [now()->subDays(2), now()->subHours(3), now()->addHours(5), now()->addDay(), now()->addDays(3)][$i % 5];

        if ($stage->type === StageType::Open) {
            $lead->next_action_due_at = $due;
        }

        match ($stage->name) {
            'Jadwal survei' => $lead->forceFill([
                'survey_at' => now()->addDays(1 + $i % 4)->setTime(10 + $i % 5, 0),
                'survey_location' => 'Unit contoh '.$property->name,
            ]),
            'Booking', 'Closing' => $lead->forceFill([
                'unit' => 'Unit '.chr(65 + $i % 5).'-'.(10 + $i),
                'deal_value' => $property->price_from,
            ]),
            'Gugur' => $lead->forceFill(['lost_reason_id' => $lost === [] ? null : $lost[$i % count($lost)]]),
            default => null,
        };

        if ($stage->type !== StageType::Open) {
            $lead->next_action = null;
            $lead->next_action_due_at = null;
        }
    }

    /** @return list<User> */
    private function owners(): array
    {
        $users = $this->current->get()->activeUsers()->get();
        $agents = $users->filter(fn (User $u) => $this->current->roleOf($u)?->value === 'agent');

        return ($agents->isNotEmpty() ? $agents : $users)->values()->all();
    }

    /** @return list<Property> */
    private function properties(): array
    {
        $rows = [
            [PropertyKind::Primary, 'Cluster Bukit Asri', 'Rumah', 'Bogor Selatan', 'PT Bukit Asri Lestari', 785_000_000],
            [PropertyKind::Primary, 'Apartemen Taman Kota Tower B', 'Apartemen', 'Jakarta Selatan', 'PT Taman Kota Hunian', 620_000_000],
            [PropertyKind::Secondary, 'Rumah Jl. Kenanga No. 8', 'Rumah', 'Depok', null, 1_150_000_000],
            [PropertyKind::Primary, 'Ruko Pasar Baru Blok A', 'Ruko', 'Bekasi', 'PT Pasar Baru Sejahtera', 1_450_000_000],
        ];

        return array_map(fn (array $r) => Property::create([
            'kind' => $r[0], 'name' => $r[1].' (contoh)', 'property_type' => $r[2], 'location' => $r[3], 'developer' => $r[4],
            'price_from' => $r[5], 'details' => ['demo' => true],
        ]), $rows);
    }
}
