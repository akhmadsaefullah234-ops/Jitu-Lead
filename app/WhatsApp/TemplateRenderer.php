<?php

namespace App\WhatsApp;

use App\Agency\AgencyProfile;
use App\FollowUps\AskProduct;
use App\FollowUps\SurveyReminder;
use App\LandingPages\PageData;
use App\Models\Lead;
use App\Models\User;
use App\Support\Industry;

/**
 * Fills {nama}, {agen}, {agensi}, {wa_agensi} (Profil agensi's business WhatsApp, else the first WhatsApp number), {properti} or {produk} (the same thing in shop words), and {daftar_produk}
 * (the numbered list of active products, see App\FollowUps\AskProduct), and {tanggal_survei}, {jam_survei} and
 * {lokasi_survei} in message templates.
 */
class TemplateRenderer
{
    public const PLACEHOLDERS = ['nama', 'agen', 'agensi', 'wa_agensi', 'properti', 'produk', 'daftar_produk', 'tanggal_survei', 'jam_survei', 'lokasi_survei'];

    /**
     * @return array{text: string, parameters: array<int, string>} Parameters are in order of appearance, as Meta's positional template variables expect.
     */
    public function render(string $template, Lead $lead, User $agent, string $agencyName): array
    {
        $values = [
            'nama' => explode(' ', trim($lead->name))[0] ?: $lead->name,
            'agen' => $agent->name,
            'agensi' => $agencyName,
            'wa_agensi' => str_contains($template, '{wa_agensi}') ? self::agencyWhatsapp($lead) : '',
            'properti' => $item = $lead->property?->name ?? $lead->location ?? Industry::of($lead->tenant)->term('item_fallback'),
            'produk' => $item,
            // Only worked out when the message uses it: the numbered list of active products.
            'daftar_produk' => str_contains($template, '{daftar_produk}') ? AskProduct::listText(AskProduct::products()) : '',
            // The survey planned for the lead (App\FollowUps\SurveyReminder), in the agency's time zone.
            ...SurveyReminder::values($lead),
        ];

        $parameters = [];

        $text = preg_replace_callback('/\{('.implode('|', self::PLACEHOLDERS).')\}/', function (array $match) use ($values, &$parameters) {
            $parameters[] = $values[$match[1]];

            return $values[$match[1]];
        }, $template);

        return ['text' => $text, 'parameters' => $parameters];
    }

    /** "0812...": Profil agensi's business number, else the agency's first WhatsApp number. */
    public static function agencyWhatsapp(Lead $lead): string
    {
        $tenant = $lead->tenant;
        $wa = $tenant ? AgencyProfile::for($tenant)->whatsapp() : null;
        $wa ??= PageData::agencyNumber() ?: null;

        return $wa ? '0'.substr($wa, 2) : '';
    }
}
