<?php

namespace App\Ai;

use App\Actions\MoveLeadToStage;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The property agency's AI tools: simpan_kebutuhan (what the client looks for, onto the lead) and jadwalkan_survei
 * (the survey date, time and place). A tool checks what it is given and tells the model what will be written; the
 * lead itself is written only once the answer really goes out (GenerateAiReply::applyActions), so a draft an agent
 * throws away changes nothing. Must run inside the tenant.
 */
class PropertyTools
{
    public const NEEDS = 'simpan_kebutuhan';

    public const SURVEY = 'jadwalkan_survei';

    /** cara_bayar as the model writes it => the lead's payment method. */
    public const PAYMENTS = ['kpr' => PaymentMethod::Kpr, 'tunai_keras' => PaymentMethod::Cash, 'tunai_bertahap' => PaymentMethod::Installment];

    /** Words that show the client spoke about a day or a time: a survey is only planned when they did. */
    private const WHEN = '/\b(?:besok|lusa|nanti|minggu\s+depan|pekan\s+depan|hari\s+ini|senin|selasa|rabu|kamis|jumat|jum\'at|sabtu|minggu|ahad|tanggal|tgl|jam|pukul|pagi|siang|sore|malam|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|jan|feb|mar|apr|jun|jul|agu|agt|sep|okt|nov|des)\b|\d{1,2}[.:]\d{2}|\d{1,2}\s*[\/-]\s*\d{1,2}/iu';

    /** @var list<array{type: string}> what the answer will write, in call order */
    private array $actions = [];

    private string $clientSaid = '';

    public function __construct(private Tenant $tenant, private Lead $lead) {}

    /** Only for a property agency's chat with a lead. */
    public static function for(Tenant $tenant, ?Lead $lead): ?self
    {
        return $tenant->industry()->key === 'properti' && $lead !== null ? new self($tenant, $lead) : null;
    }

    /** What the client wrote in this chat: a survey is planned only when they spoke about a day or a time. */
    public function clientSaid(string $text): void
    {
        $this->clientSaid = $text;
    }

    public function timezone(): string
    {
        return $this->tenant->timezone ?: (string) config('app.timezone');
    }

    /** The open stage a planned survey moves the lead to, or null when the pipeline has none. */
    public static function surveyStage(): ?Stage
    {
        return Stage::query()->where('requirement', StageRequirement::Survey->value)->where('type', StageType::Open->value)->orderBy('position')->first();
    }

    /** @return list<array{name: string, description: string, input_schema: array}> */
    public function definitions(): array
    {
        $tools = [[
            'name' => self::NEEDS,
            'description' => 'Simpan kebutuhan properti yang klien sebut sendiri di chat ini ke data lead. Isi hanya kolom yang klien sebut; jangan menebak. '
                .'Nilai yang sudah ada tidak ditimpa, kecuali klien jelas mengoreksinya (isi koreksi = true).',
            'input_schema' => ['type' => 'object', 'properties' => [
                'tujuan' => ['type' => 'string', 'enum' => array_keys(LeadForm::PURPOSES), 'description' => 'hunian, investasi, villa (liburan), atau sewa'],
                'domisili' => ['type' => 'string', 'description' => 'Kota atau daerah tempat klien tinggal sekarang'],
                'budget_min' => ['type' => 'integer', 'description' => 'Budget terendah dalam rupiah penuh, mis. 1500000000 untuk 1,5 M'],
                'budget_max' => ['type' => 'integer', 'description' => 'Budget tertinggi dalam rupiah penuh'],
                'cara_bayar' => ['type' => 'string', 'enum' => array_keys(self::PAYMENTS)],
                'tipe' => ['type' => 'string', 'enum' => array_keys(LeadForm::PROPERTY_TYPES)],
                'lokasi' => ['type' => 'string', 'description' => 'Daerah properti yang dicari'],
                'koreksi' => ['type' => 'boolean', 'description' => 'true hanya bila klien mengoreksi data yang pernah ia sebut'],
            ]],
        ]];

        if (self::surveyStage() !== null) {
            $tools[] = [
                'name' => self::SURVEY,
                'description' => 'Catat jadwal survei lokasi HANYA setelah klien sendiri menyetujui hari, jam, dan lokasinya di chat ini. '
                    .'Satu lead hanya punya satu jadwal: memanggil lagi mengubah jadwal yang ada. Waktu dalam zona waktu agensi.',
                'input_schema' => ['type' => 'object', 'required' => ['tanggal', 'jam', 'lokasi'], 'properties' => [
                    'tanggal' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'jam' => ['type' => 'string', 'description' => 'HH:MM, 24 jam'],
                    'lokasi' => ['type' => 'string', 'description' => 'Tempat bertemu atau properti yang disurvei'],
                ]],
            ];
        }

        return $tools;
    }

    public function instructions(): string
    {
        $now = CarbonImmutable::now($this->timezone())->locale('id');

        return implode("\n", array_filter([
            'ALAT KLIEN: bila klien menyebut tujuan, domisili, budget, cara bayar, tipe, atau lokasi incaran, panggil '.self::NEEDS.' dengan yang ia sebut saja. Jangan memanggilnya karena contoh di PENGETAHUAN.',
            self::surveyStage() !== null
                ? 'Bila klien setuju survei dan sudah menyebut hari, jam, dan lokasi, panggil '.self::SURVEY.'. Bila belum lengkap, tanyakan yang kurang dulu. '
                    .'Bila hasilnya ditolak, jelaskan dengan ramah dan tanyakan ulang; jangan pernah menulis jadwal yang tidak tercatat. '
                    .'Setelah tercatat, ulangi konfirmasinya ke klien: nama, hari dan tanggal, jam, dan lokasi. '
                    .'Sekarang '.$now->translatedFormat('l, j F Y').' pukul '.$now->format('H.i').' (zona waktu agensi).'
                : null,
        ]));
    }

    /** @return array<string, mixed> what the model reads back */
    public function run(string $name, array $input): array
    {
        try {
            return match ($name) {
                self::NEEDS => $this->needs($input),
                self::SURVEY => $this->survey($input),
                default => ['status' => 'error', 'pesan' => 'Tool tidak dikenal.'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'pesan' => 'Data belum bisa dicatat. Lanjutkan percakapan tanpa menjanjikan apa pun yang belum tercatat.'];
        }
    }

    /** @return list<array{type: string}> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function forget(): void
    {
        $this->actions = [];
    }

    /** The survey the answer plans, if it does: [when, where] for the confirmation check. */
    public function plannedSurvey(): ?array
    {
        $survey = collect($this->actions)->last(fn (array $a) => $a['type'] === 'survey');

        return $survey === null ? null : [CarbonImmutable::parse($survey['survey_at'])->timezone($this->timezone()), $survey['survey_location']];
    }

    private function needs(array $input): array
    {
        $correct = (bool) ($input['koreksi'] ?? false);
        $wanted = array_filter([
            'purpose' => in_array($input['tujuan'] ?? null, array_keys(LeadForm::PURPOSES), true) ? $input['tujuan'] : null,
            'domicile' => self::text($input['domisili'] ?? null, 120),
            'budget_min' => self::amount($input['budget_min'] ?? null),
            'budget_max' => self::amount($input['budget_max'] ?? null),
            'payment_method' => isset(self::PAYMENTS[$input['cara_bayar'] ?? '']) ? self::PAYMENTS[$input['cara_bayar']]->value : null,
            'property_type' => array_key_exists($input['tipe'] ?? '', LeadForm::PROPERTY_TYPES) ? $input['tipe'] : null,
            'location' => self::text($input['lokasi'] ?? null, 120),
        ], fn ($v) => $v !== null);

        if (isset($wanted['purpose'])) {
            $wanted['need'] = $wanted['purpose'] === 'sewa' ? Need::Rent->value : Need::Buy->value;
        }

        if (isset($wanted['budget_min'], $wanted['budget_max']) && $wanted['budget_min'] > $wanted['budget_max']) {
            [$wanted['budget_min'], $wanted['budget_max']] = [$wanted['budget_max'], $wanted['budget_min']];
        }

        $changes = [];
        $kept = [];

        foreach ($wanted as $field => $value) {
            $current = $this->lead->getRawOriginal($field);

            // Need is set by default on every new lead; it follows the purpose the client gave.
            if (filled($current) && (string) $current !== (string) $value && ! $correct && $field !== 'need') {
                $kept[$field] = $current;

                continue;
            }

            if ((string) $current !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        if ($changes !== []) {
            $this->actions[] = ['type' => 'needs', 'changes' => $changes];
        }

        return array_filter([
            'status' => $changes !== [] ? 'tersimpan' : 'tidak_berubah',
            'disimpan' => $changes ?: null,
            'tidak_ditimpa' => $kept !== [] ? $kept : null,
            'pesan' => $kept !== [] ? 'Nilai yang sudah ada tidak ditimpa. Bila klien memang mengoreksi, panggil lagi dengan koreksi = true.' : null,
        ]);
    }

    private function survey(array $input): array
    {
        $stage = self::surveyStage();
        $current = $this->lead->stage;

        if ($stage === null) {
            return ['status' => 'ditolak', 'pesan' => 'Pipeline agensi belum punya tahap survei. Serahkan ke admin.'];
        }

        // A lead past the survey (booking, closing) or out of the pipeline is not taken back by the AI.
        if ($current !== null && ($current->type !== StageType::Open || $current->position > $stage->position)) {
            return ['status' => 'ditolak', 'pesan' => 'Lead ini sudah melewati tahap survei. Panggil serahkan_ke_admin agar tim yang mengatur.'];
        }

        if (preg_match(self::WHEN, $this->clientSaid) !== 1) {
            return ['status' => 'ditolak', 'masalah' => ['Klien belum menyebut hari atau jam survei.'], 'pesan' => 'Tanyakan dulu hari dan jam yang klien mau; jangan menjadwalkan sendiri.'];
        }

        $date = trim((string) ($input['tanggal'] ?? ''));
        $time = str_replace('.', ':', trim((string) ($input['jam'] ?? '')));
        $location = self::text($input['lokasi'] ?? null, 190);
        $at = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            try {
                $at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $this->timezone());
            } catch (Throwable) {
                $at = null;
            }
        }

        try {
            $checked = MoveLeadToStage::survey(['survey_at' => $at?->timezone((string) config('app.timezone')), 'survey_location' => $location]);
        } catch (ValidationException $e) {
            return ['status' => 'ditolak', 'masalah' => array_values(array_map(fn ($m) => $m[0], $e->errors())),
                'pesan' => 'Jadwal belum tercatat. Jelaskan dengan ramah apa yang kurang atau keliru, lalu tanyakan ulang. Jangan menulis jadwal ini seolah sudah tercatat.'];
        }

        $local = $checked['survey_at']->timezone($this->timezone())->locale('id');
        $this->actions = array_values(array_filter($this->actions, fn (array $a) => $a['type'] !== 'survey'));
        $this->actions[] = ['type' => 'survey', 'survey_at' => $checked['survey_at']->toIso8601String(), 'survey_location' => $checked['survey_location']];

        return [
            'status' => $this->lead->survey_at !== null ? 'jadwal_diubah' : 'terjadwal',
            'nama' => $this->lead->name,
            'hari_tanggal' => $local->translatedFormat('l, j F Y'),
            'jam' => $local->format('H.i').' '.PropertyClientFacts::zone($this->timezone()),
            'lokasi' => $checked['survey_location'],
            'pesan' => 'Ulangi konfirmasi ini ke klien: nama, hari dan tanggal, jam, dan lokasi.',
        ];
    }

    /**
     * Writes what the answer planned onto the lead, once it was really sent.
     *
     * @return array{status: string, pesan: string}
     */
    public static function apply(Lead $lead, array $action, ?User $actor, string $by): array
    {
        if ($action['type'] === 'needs') {
            $changes = array_intersect_key((array) ($action['changes'] ?? []), array_flip(['purpose', 'domicile', 'budget_min', 'budget_max', 'payment_method', 'property_type', 'location', 'need']));

            if ($changes === []) {
                return ['status' => 'tidak_berubah', 'pesan' => 'Tidak ada yang diubah.'];
            }

            $lead->forceFill($changes)->save();
            $lead->activities()->create(['type' => 'ai_needs', 'body' => 'Kebutuhan klien dicatat dari chat ('.$by.'): '.implode(', ', array_keys($changes)), 'meta' => ['changes' => $changes]]);

            return ['status' => 'tercatat', 'pesan' => 'Kebutuhan dicatat: '.implode(', ', array_keys($changes))];
        }

        $details = ['survey_at' => CarbonImmutable::parse($action['survey_at'])->timezone((string) config('app.timezone')), 'survey_location' => $action['survey_location']];
        $stage = self::surveyStage();

        try {
            if ($stage === null) {
                return ['status' => 'gagal', 'pesan' => 'Tahap survei tidak ada.'];
            }

            $lead->stage?->is($stage)
                ? app(MoveLeadToStage::class)->reschedule($lead, $details, $actor, 'dijadwalkan AI dari chat ('.$by.')')
                : app(MoveLeadToStage::class)($lead, $stage, $actor, $details, 'survei dijadwalkan AI dari chat ('.$by.')');
        } catch (ValidationException $e) {
            return ['status' => 'gagal', 'pesan' => implode(' ', array_map(fn ($m) => $m[0], $e->errors()))];
        }

        $lead->activities()->create(['type' => 'ai_survey', 'body' => 'Survei dijadwalkan dari chat ('.$by.'): '.$lead->survey_at->format('j M Y H:i').' di '.$lead->survey_location,
            'meta' => ['survey_at' => $lead->survey_at->toIso8601String()]]);

        return ['status' => 'tercatat', 'pesan' => 'Survei '.$lead->survey_at->format('j M Y H:i').' di '.$lead->survey_location];
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** A rupiah amount the model gave, when it is one: whole, positive, below a trillion. */
    private static function amount(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1_000_000 && (int) $value < 1_000_000_000_000 ? (int) $value : null;
    }
}
