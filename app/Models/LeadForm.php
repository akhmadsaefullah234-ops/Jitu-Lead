<?php

namespace App\Models;

use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use App\Welcome\WelcomeSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead form built under "Formulir", shown at /f/{agency}/{slug}, inside landing pages, and in iframes on other
 * sites. The agency's "Formulir utama" (is_primary) is also what the older /f/{capture_token} link shows.
 */
#[Fillable(['name', 'slug', 'is_primary', 'status', 'template', 'design', 'fields', 'submit', 'after_submit', 'buttons', 'page_events', 'property_id', 'welcome'])]
class LeadForm extends Model
{
    use BelongsToTenant;

    public const BACKGROUNDS = ['white' => 'Putih', 'soft' => 'Abu-abu muda', 'tint' => 'Warna utama lembut', 'brand' => 'Warna utama', 'dark' => 'Gelap'];

    public const AFTER = ['message' => 'Tampilkan pesan', 'redirect' => 'Arahkan ke halaman terima kasih', 'whatsapp' => 'Buka WhatsApp dengan pesan otomatis'];

    protected static function booted(): void
    {
        // A new form starts with the WhatsApp welcome on for a shop (forms made before it existed stay off).
        static::creating(function (LeadForm $form) {
            $form->welcome ??= WelcomeSettings::newForm(Tenant::query()->find($form->tenant_id));
        });
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'design' => 'array',
            'fields' => 'array',
            'submit' => 'array',
            'after_submit' => 'array',
            'buttons' => 'array',
            'page_events' => 'array',
            'welcome' => 'array',
        ];
    }

    /** The product every lead from this form asks about; none = taken from an answer, if any. */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isActive(): bool
    {
        return $this->status !== 'inactive';
    }

    /** @return list<array<string, mixed>> */
    public function fieldList(): array
    {
        return FormFields::normalize($this->fields);
    }

    public function design(string $key, mixed $default = null): mixed
    {
        $value = $this->design[$key] ?? null;

        return filled($value) ? $value : $default;
    }

    public function color(): string
    {
        $color = (string) $this->design('color', '#dc2626');

        return preg_match(LandingPage::COLOR_PATTERN, $color) ? $color : '#dc2626';
    }

    public function publicUrl(): string
    {
        return route('form.page', [$this->tenant->slug, $this->slug]);
    }

    public function submitUrl(): string
    {
        return route('form.submit', [$this->tenant->slug, $this->slug]);
    }

    public function embedCode(): string
    {
        return '<iframe src="'.e($this->publicUrl()).'" title="'.e($this->name).'" style="width:100%;max-width:520px;height:720px;border:0" loading="lazy"></iframe>';
    }

    /**
     * The agency's main form; made from the older form settings the first time it is needed.
     */
    public static function primary(): self
    {
        $existing = static::query()->where('is_primary', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $tenant = app(CurrentTenant::class)->get();

        return static::query()->create(static::legacyAttributes((array) $tenant?->capture_settings));
    }

    /**
     * The main form as the older single form looked: its texts and colour, name and WhatsApp, and email and
     * message when they were switched on.
     *
     * @param  array<string, mixed>  $settings  tenants.capture_settings
     * @return array<string, mixed>
     */
    public static function legacyAttributes(array $settings): array
    {
        $s = array_replace([
            'title' => 'Dapatkan informasi lengkap', 'intro' => 'Isi data Anda, tim kami akan menghubungi lewat WhatsApp.', 'button' => 'Kirim',
            'success' => 'Terima kasih! Tim kami akan segera menghubungi Anda.', 'color' => '#dc2626',
            'ask_email' => false, 'ask_note' => true, 'show_privacy' => true,
            'privacy' => 'Dengan mengirim, Anda setuju dihubungi oleh tim kami terkait permintaan ini.',
        ], array_filter($settings, fn ($v) => $v !== null));

        $fields = [
            ['key' => 'nama', 'type' => 'text', 'label' => 'Nama lengkap', 'placeholder' => 'Nama Anda', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'whatsapp', 'type' => 'phone', 'label' => 'Nomor WhatsApp', 'placeholder' => '0812xxxxxxxx', 'required' => true, 'maps_to' => 'phone'],
        ];

        if (! empty($s['ask_email'])) {
            $fields[] = ['key' => 'email', 'type' => 'email', 'label' => 'Email (opsional)', 'placeholder' => 'nama@email.com', 'maps_to' => 'email'];
        }

        if (! empty($s['ask_note'])) {
            $fields[] = ['key' => 'pesan', 'type' => 'textarea', 'label' => 'Pesan (opsional)', 'placeholder' => 'Tipe unit, budget, atau pertanyaan Anda', 'maps_to' => 'note'];
        }

        $fields[] = ['key' => 'utm_source', 'type' => 'hidden', 'label' => 'Sumber iklan', 'source' => 'utm_source'];

        return [
            'name' => 'Formulir utama',
            'slug' => 'utama',
            'is_primary' => true,
            'status' => 'active',
            'template' => FormTemplates::BLANK,
            'design' => [
                'title' => (string) $s['title'], 'description' => (string) $s['intro'], 'color' => (string) $s['color'], 'font' => 'modern', 'background' => 'white',
                'privacy' => ! empty($s['show_privacy']) ? (string) $s['privacy'] : null,
            ],
            'fields' => FormFields::normalize($fields),
            'submit' => ['label' => (string) $s['button'], 'color' => 'brand', 'size' => 'lg', 'icon' => 'none'],
            'after_submit' => ['type' => 'message', 'message' => (string) $s['success']],
            'buttons' => [],
        ];
    }
}
