<?php

namespace App\Welcome;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\Shipping;
use App\WhatsApp\TemplateRenderer;
use Throwable;

/**
 * Fills a welcome text: {ringkasan} (what the buyer ordered, in one line), {total} (promo price times quantity,
 * plus shipping when the shipping settings can count it from the address) and the usual {nama}, {produk},
 * {agensi}, {agen} of App\WhatsApp\TemplateRenderer.
 */
class WelcomeText
{
    public const NO_SHIPPING = 'ongkir kami hitungkan ya kak';

    public function __construct(private TemplateRenderer $renderer) {}

    /** @param  string|null  $answers  every answer of the form, one per line: the summary when the lead holds no order */
    public function render(string $text, Lead $lead, User $agent, Tenant $tenant, ?string $answers = null): string
    {
        $text = strtr($text, [
            '{ringkasan}' => str_contains($text, '{ringkasan}') ? $this->summary($lead, $answers) : '',
            '{total}' => str_contains($text, '{total}') ? $this->total($lead, $tenant) : '',
        ]);

        return trim($this->renderer->render($text, $lead, $agent, $tenant->name)['text']);
    }

    /** "Tas Badminton (Hitam) x2, dikirim ke Coblong, Kota Bandung". */
    public function summary(Lead $lead, ?string $answers = null): string
    {
        $item = $lead->property?->name;
        $parts = [];

        if ($item !== null || filled($lead->variant) || $lead->quantity) {
            $parts[] = trim(($item ?? 'Pesanan').(filled($lead->variant) ? ' ('.$lead->variant.')' : '').($lead->quantity ? ' x'.$lead->quantity : ''));
        }

        $address = $this->shortAddress($lead);
        if ($address !== null) {
            $parts[] = 'dikirim ke '.$address;
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return mb_substr(trim(str_replace("\n", ', ', (string) $answers)), 0, 500);
    }

    /** "Rp698.000 + ongkir Rp18.000 (JNE REG) = Rp716.000", or "Rp698.000 (ongkir kami hitungkan ya kak)". */
    public function total(Lead $lead, Tenant $tenant): string
    {
        $price = $lead->property?->price_from;

        if (! $price) {
            return self::NO_SHIPPING;
        }

        $quantity = max(1, (int) ($lead->quantity ?: 1));
        $subtotal = (int) $price * $quantity;
        $shipping = $this->shipping($lead, $tenant);

        return $shipping === null
            ? self::rupiah($subtotal).' ('.self::NO_SHIPPING.')'
            : self::rupiah($subtotal).' + ongkir '.self::rupiah($shipping[1]).' ('.$shipping[0].') = '.self::rupiah($subtotal + $shipping[1]);
    }

    /** @return array{0: string, 1: int}|null the cheapest courier and its price */
    private function shipping(Lead $lead, Tenant $tenant): ?array
    {
        $parts = (array) $lead->shipping_address_parts;

        if (! Shipping::available($tenant) || blank($parts['kota'] ?? null)) {
            return null;
        }

        try {
            $quote = Shipping::quote($tenant, $parts['kota'], $lead->shipping_province ?? ($parts['provinsi'] ?? null), [[
                'product_id' => $lead->property_id, 'produk' => $lead->property?->name, 'varian' => $lead->variant, 'jumlah' => $lead->quantity ?: 1,
            ]], $parts['kecamatan'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! $quote->ok() || $quote->options === []) {
            return null;
        }

        $cheapest = collect($quote->options)->sortBy('price')->first();

        return [$cheapest->courier, $cheapest->price];
    }

    private function shortAddress(Lead $lead): ?string
    {
        $parts = (array) $lead->shipping_address_parts;
        $short = implode(', ', array_filter([$parts['kecamatan'] ?? null, $parts['kota'] ?? null], fn ($v) => is_string($v) && trim($v) !== ''));

        if ($short !== '') {
            return $short;
        }

        return filled($lead->shipping_address) ? mb_strimwidth(trim((string) $lead->shipping_address), 0, 80, '…') : null;
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
