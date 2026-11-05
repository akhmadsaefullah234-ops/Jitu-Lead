<?php

namespace App\LeadForms;

use App\Actions\RegisterLead;
use App\Automation\StageAutomation;
use App\Catalog\ProductMatcher;
use App\Jobs\SendConversionEvents;
use App\LandingPages\Buttons;
use App\LandingPages\PageData;
use App\LandingPages\WhatsAppRotation;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Push\Alerts;
use App\Tracking\ConversionPayload;
use App\Tracking\PipelineConversions;
use App\Tracking\PixelEvents;
use App\Welcome\WelcomeSender;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * One visitor sending a built form: the lead is always saved first (new, or noted on the existing one), its
 * answers land on the lead, the ad platforms hear about it with the browser's event_id, the WhatsApp welcome is
 * booked (App\Welcome\WelcomeSender), and then the form's "after sending" step is worked out (message, thank-you page, or WhatsApp with the answers). Runs inside the tenant.
 */
class SubmitLeadForm
{
    public const CAMPAIGN_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public const BROWSER_IDS = ['fbp', 'fbc', 'ttclid', 'ttp'];

    public function __construct(private RegisterLead $register) {}

    /**
     * @param  array<string, mixed>  $data  validated input: f[...] plus page, event_id, source_url, utm_*, fbp...
     *                                      A submission that SpamGuard rejects as a robot saves nothing (lead null, logged and counted) and gets the
     *                                      form's plain message; one it only suspects is saved, marked, and handled like any other.
     * @return array{lead: ?Lead, created: bool, action: array<string, mixed>}
     */
    public function __invoke(Tenant $tenant, LeadForm $form, array $data, Request $request): array
    {
        $fields = $form->fieldList();
        $result = FormFields::answers($fields, (array) ($data['f'] ?? []));
        $spam = SpamGuard::check($request, $result['lead']['phone'] ?? null);

        if ($spam['verdict'] === SpamGuard::REJECT) {
            SpamGuard::reject($request, $tenant->getKey(), $form->getKey(), 'form', $spam['reasons']);

            return ['lead' => null, 'created' => false, 'action' => ['type' => 'message', 'message' => (string) ($form->after_submit['message'] ?? '') ?: 'Terima kasih!']];
        }
        $page = filled($data['page'] ?? null) ? LandingPage::query()->find($data['page']) : null;
        $source = $page ? 'Landing: '.$page->title : ($form->is_primary ? 'Formulir web' : 'Formulir: '.$form->name);
        $campaign = array_filter(array_intersect_key($data, array_flip(self::CAMPAIGN_FIELDS)));

        [$lead, $created] = ($this->register)($result['lead'] + ['name' => 'Tanpa nama'], $source, 'form', null, $campaign, $page?->property_id);

        $this->applyAnswers($lead, $created, $form, $result, $tenant);

        if ($spam['verdict'] === SpamGuard::SUSPECT) {
            SpamGuard::mark($lead, $created, $spam['reasons'], $request->userAgent());
        }
        $this->product($lead, $form, $result);

        if ($created) {
            PipelineConversions::rememberBrowserIds($lead, $data);
        }
        $this->orderIn($lead, $result);

        if ($created) {
            try {
                app(Alerts::class)->newLead($lead, $page ? 'landing page' : 'formulir '.$form->name, $result['lead']['note'] ?? null);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $tracking = TrackingSetting::active();

        if ($created && filled($data['event_id'] ?? null) && $tracking?->hasAny()) {
            $resolved = PixelEvents::resolve($form->submit['pixel'] ?? null, $tracking->formDefaults(), $tracking, $tracking->google_ads_label) ?? [];
            // The same events the browser fired; a platform the form switched off gets none.
            $event = array_filter([
                'value' => $resolved['value'] ?? null, 'currency' => $resolved['currency'] ?? null, 'content_name' => $resolved['content_name'] ?? null,
            ], fn ($v) => $v !== null) + [
                'meta' => PixelEvents::eventName('meta', (array) ($form->submit['pixel'] ?? []), $tracking->eventFor('meta')),
                'tiktok' => PixelEvents::eventName('tiktok', (array) ($form->submit['pixel'] ?? []), $tracking->eventFor('tiktok')),
            ];

            SendConversionEvents::dispatch($tenant->getKey(), ConversionPayload::make(
                $data['event_id'], $lead->email, $lead->phone, $data['source_url'] ?? null,
                $request->ip(), $request->userAgent(), array_filter(array_intersect_key($data, array_flip(self::BROWSER_IDS))), $event,
            )->toArray());
            PipelineConversions::recordFormEvent($lead, (string) $data['event_id'], $event['meta'] ?? $event['tiktok']);
        }

        $form->newQuery()->whereKey($form->getKey())->increment('submissions');

        // The WhatsApp welcome, after the form's delay, even when the buyer never opens the chat.
        try {
            app(WelcomeSender::class)->schedule($form, $page, $lead, $created, $result['vars']['ringkasan'] ?? null, collect($result['answers'])->pluck('value', 'key')->all());
        } catch (Throwable $e) {
            report($e);
        }

        return ['lead' => $lead, 'created' => $created, 'action' => $this->after($tenant, $form, $lead, $result['vars'], $page)];
    }

    /** Lead columns, extra fields, the answers as a note, and stored photos. A returning lead keeps what it had. */
    private function applyAnswers(Lead $lead, bool $created, LeadForm $form, array $result, Tenant $tenant): void
    {
        foreach (['variant', 'quantity', 'shipping_address', 'shipping_province', 'shipping_address_parts', 'payment_type'] as $column) {
            if (isset($result['lead'][$column]) && ($created || blank($lead->{$column}))) {
                $lead->{$column} = $result['lead'][$column];
            }
        }

        $custom = (array) $lead->custom_fields;

        if ($result['custom'] !== []) {
            $custom['formulir'] = array_replace((array) ($custom['formulir'] ?? []), $result['custom']);
        }

        foreach ($result['photos'] as $key => $file) {
            $path = $this->storePhoto($file, $tenant, $lead);
            $label = collect($form->fieldList())->firstWhere('key', $key)['label'] ?? 'Foto';
            $custom['foto_formulir'] = [...(array) ($custom['foto_formulir'] ?? []), ['label' => $label, 'path' => $path, 'at' => now()->toIso8601String()]];
        }

        $lead->custom_fields = $custom ?: null;
        $lead->save();

        if ($result['answers'] !== []) {
            $lead->activities()->create([
                'type' => 'note',
                'body' => "Jawaban formulir {$form->name}:\n".collect($result['answers'])->map(fn ($a) => "- {$a['label']}: {$a['value']}")->implode("\n")
                    .(isset($result['lead']['shipping_address']) && blank($lead->payment_type) ? "\nCara bayar belum ditanyakan." : ''),
                'meta' => ['formulir' => $form->getKey()],
            ]);
        }
    }

    /**
     * The product the lead asks about: the buyer's answer when it names a catalogue product, otherwise the form's
     * own product. A product an agent picked stays. A form with neither leaves the lead as it was.
     */
    private function product(Lead $lead, LeadForm $form, array $result): void
    {
        if ($lead->product_source === 'agent' && $lead->property_id !== null) {
            return;
        }

        $answer = trim((string) ($result['lead']['product'] ?? ''));
        $product = null;

        if ($answer !== '') {
            $product = Property::query()->whereRaw('lower(name) = ?', [mb_strtolower($answer)])->first()
                ?? app(ProductMatcher::class)->match(null, $answer)['product'] ?? null;
        }

        $detail = $product ? $answer : $form->name;
        $product ??= $form->property_id ? Property::query()->find($form->property_id) : null;

        if ($product === null || $lead->property_id === $product->getKey()) {
            return;
        }

        try {
            ProductMatcher::set($lead, $product, 'form', $detail);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * An order sent through a form (it has a delivery address) goes to "Order masuk" when the shop's stage
     * automation is on. How it is paid never moves it further: that waits for the admin or the chat.
     */
    private function orderIn(Lead $lead, array $result): void
    {
        if (! isset($result['lead']['shipping_address'])) {
            return;
        }

        try {
            $automation = app(StageAutomation::class);
            if ($automation->applies()) {
                $automation->orderDrafted($lead);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function storePhoto(UploadedFile $file, Tenant $tenant, Lead $lead): string
    {
        $ext = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };

        return $file->storeAs("lead-uploads/{$tenant->getKey()}/{$lead->getKey()}", Str::uuid().'.'.$ext, 'local');
    }

    /**
     * What the visitor's browser does next.
     *
     * @return array{type: string, message?: string, url?: string, new_tab?: bool}
     */
    private function after(Tenant $tenant, LeadForm $form, Lead $lead, array $vars, ?LandingPage $page): array
    {
        $after = (array) $form->after_submit;
        $vars += ['judul' => $page?->title ?? $form->name, 'produk' => $vars['produk'] ?? ($page?->productName() ?? '')];
        $message = FormFields::fillSummary((string) ($after['message'] ?? ''), $vars) ?: 'Terima kasih! Tim kami akan segera menghubungi Anda.';

        if (($after['type'] ?? 'message') === 'redirect') {
            $url = ($after['page_kind'] ?? 'landing') === 'url'
                ? Buttons::safeUrl($after['url'] ?? null)
                : (($p = LandingPage::query()->find($after['landing_page_id'] ?? 0)) && $p->isPublished() ? $p->publicUrl() : null);

            if ($url !== null) {
                return ['type' => 'redirect', 'url' => $url, 'new_tab' => ! empty($after['new_tab']), 'message' => $message];
            }
        }

        if (($after['type'] ?? 'message') === 'whatsapp') {
            $number = match ($after['wa_target'] ?? 'agency') {
                'rotate' => WhatsAppRotation::ownerOf($lead->owner) ?: WhatsAppRotation::next($tenant),
                'manual' => PageData::digits($after['wa_number'] ?? null),
                default => ($page ? PageData::digits($page->whatsapp_number) : '') ?: PageData::agencyNumber(),
            };
            $text = FormFields::fillSummary((string) ($after['wa_message'] ?? 'Halo, saya {nama}. Saya baru mengisi formulir {judul}.'), $vars);

            if ($number !== '') {
                return ['type' => 'whatsapp', 'url' => PageData::waLink($number, $text), 'new_tab' => false, 'message' => $message];
            }
        }

        return ['type' => 'message', 'message' => $message];
    }
}
