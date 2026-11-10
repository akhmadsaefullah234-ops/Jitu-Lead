<?php

namespace App\LeadForms;

use App\LandingPages\Embeds;
use App\LandingPages\PageData;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Tracking\PixelEvents;

/**
 * What the public views need to draw a built form: on its own page (page()) and as a block inside a landing
 * page (block()). The live preview in the editor uses the same, with $preview on (no pixels).
 */
class FormView
{
    /** @return array<string, mixed> for public.form */
    public static function page(LeadForm $form, Tenant $tenant, bool $preview): array
    {
        $tracking = $preview ? null : TrackingSetting::active();
        $font = LandingPage::FONTS[$form->design('font')] ?? LandingPage::FONTS['modern'];
        $background = array_key_exists((string) $form->design('background'), LeadForm::BACKGROUNDS) ? $form->design('background') : 'soft';
        $pagePx = PixelEvents::resolve($form->page_events, [], $tracking);

        return [
            'tenant' => $tenant,
            'form' => $form,
            'preview' => $preview,
            'color' => $form->color(),
            'bodyFont' => $font[1],
            'headingFont' => $font[2],
            'background' => $background,
            'banner' => Embeds::image($form->design('banner'), $tenant->getKey()),
            'backgroundImage' => Embeds::image($form->design('background_image'), $tenant->getKey()),
            'title' => (string) $form->design('title', $form->name),
            'description' => (string) $form->design('description', ''),
            'tracking' => $tracking,
            'pagePx' => $pagePx ? json_encode($pagePx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'block' => self::block($form, $tenant, $tracking, null, 'lead-form', null, $form->publicUrl()),
        ];
    }

    /**
     * @return array<string, mixed> for public.partials.built-form
     */
    public static function block(LeadForm $form, Tenant $tenant, ?TrackingSetting $tracking, ?int $pageId, string $formId, ?string $submitLabel, ?string $url, array $vars = []): array
    {
        $submit = (array) $form->submit;
        $submitPx = PixelEvents::resolve($submit['pixel'] ?? null, $tracking?->formDefaults() ?? [], $tracking, $tracking?->google_ads_label);

        return [
            'form' => $form,
            'fields' => $form->fieldList(),
            'twoStep' => $form->twoStep(),
            'quoteUrl' => FormQuote::available($tenant, $form, $pageId) ? route('form.quote', [$tenant->slug, $form->slug]) : null,
            // Set by the landing page when it was served through its A/B test address (App\LandingPages\AbTest).
            'abVariant' => in_array($v = request()->attributes->get('ab_variant'), ['A', 'B'], true) ? $v : null,
            'endpoint' => $form->submitUrl(),
            'pageId' => $pageId,
            'formId' => $formId,
            'tracking' => $tracking,
            'submit' => ['label' => $submitLabel ?: ($submit['label'] ?? 'Kirim')] + $submit,
            'submitPx' => $submitPx ? json_encode($submitPx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'privacy' => $form->design('privacy'),
            'buttons' => array_values(array_filter((array) $form->buttons, 'is_array')),
            'ctx' => [
                'tenant' => $tenant,
                'wa' => PageData::agencyNumber(),
                'vars' => $vars + ['judul' => (string) $form->design('title', $form->name), 'produk' => '', 'halaman' => (string) $url],
                'anchor' => '#'.$formId,
            ],
        ];
    }
}
