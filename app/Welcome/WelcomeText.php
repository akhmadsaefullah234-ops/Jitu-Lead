<?php

namespace App\Welcome;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\Shipping;
use App\WhatsApp\TemplateRenderer;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills a welcome text. Variables (VARIABLES): {nama}, {produk}, {agensi}, {agen}, {ringkasan} (what the buyer
 * ordered, in one line), {total} (promo price times quantity, plus shipping when the shipping settings can count
 * it from the address), the buyer's own answers {whatsapp} (08xx), {alamat}, {varian} (alias {warna}), {jumlah},
 * {ongkir} and {kurir} (cheapest courier, when it can be counted), and {jawaban:<field key>} for any other field.
 *
 * A variable that is unknown or empty is never sent as written: a line that is only a label and such variables
 * ("Varian : {varian}") is left out, anywhere else it becomes "-".
 */
class WelcomeText
{
    public const NO_SHIPPING = 'ongkir kami hitungkan ya kak';

    public const EMPTY = '-';

    /** Every variable a welcome may use, with what it holds (the chips under the text box). */
    public const VARIABLES = [
        'nama' => 'nama depan pembeli',
        'produk' => 'produk yang dipesan',
        'agensi' => 'nama toko / agensi',
        'agen' => 'nama agen pemilik lead',
        'ringkasan' => 'ringkasan pesanan',
        'total' => 'harga x jumlah + ongkir',
        'whatsapp' => 'nomor WhatsApp pembeli (08xx)',
        'alamat' => 'alamat kirim lengkap',
        'varian' => 'varian yang dipilih',
        'warna' => 'warna / varian',
        'jumlah' => 'jumlah pesanan',
        'ongkir' => 'ongkir termurah',
        'kurir' => 'kurir termurah',
    ];

    /** {jawaban:<field key>}: the answer to any field of the form. */
    public const ANSWER_PREFIX = 'jawaban:';

    private const PATTERN = '/\{\s*([a-z0-9_]+(?::[a-z0-9_]+)?)\s*\}/i';

    public function __construct(private TemplateRenderer $renderer) {}

    /**
     * @param  string|null  $answers  every answer of the form, one per line: the summary when the lead holds no order
     * @param  array<string, string>  $fieldAnswers  the form's answers by field key ({jawaban:key})
     */
    public function render(string $text, Lead $lead, User $agent, Tenant $tenant, ?string $answers = null, array $fieldAnswers = []): string
    {
        $used = self::used($text);
        // The usual {nama}, {produk}, {agensi}, {agen} ... as App\WhatsApp\TemplateRenderer fills them ({daftar_produk} only when used).
        $own = array_values(array_filter(TemplateRenderer::PLACEHOLDERS, fn ($k) => $k !== 'daftar_produk' || in_array($k, $used, true)));
        $base = $this->renderer->render(implode("\x1F", array_map(fn ($k) => '{'.$k.'}', $own)), $lead, $agent, $tenant->name)['text'];
        $values = array_combine($own, explode("\x1F", $base));
        $shipping = array_intersect($used, ['ongkir', 'kurir']) !== [] ? $this->shipping($lead, $tenant) : null;
        $variant = (string) $lead->variant;

        $values += [
            'ringkasan' => in_array('ringkasan', $used, true) ? $this->summary($lead, $answers) : '',
            'total' => in_array('total', $used, true) ? $this->total($lead, $tenant) : '',
            'whatsapp' => self::localNumber($lead->phone),
            'telepon' => self::localNumber($lead->phone),
            'email' => (string) $lead->email,
            'alamat' => trim((string) $lead->shipping_address),
            'varian' => $variant,
            'warna' => $fieldAnswers['warna'] ?? $variant,
            'jumlah' => $lead->quantity ? (string) $lead->quantity : '',
            'ongkir' => $shipping ? self::rupiah($shipping[1]) : '',
            'kurir' => $shipping[0] ?? '',
        ];

        foreach ($fieldAnswers as $key => $value) {
            $values[self::ANSWER_PREFIX.Str::lower((string) $key)] = (string) $value;
        }

        return self::fill($text, $values);
    }

    /**
     * Puts $values into $text. Unknown or empty variables: a line that is only a label and those variables is
     * dropped; anywhere else they become "-".
     *
     * @param  array<string, string>  $values  variable (lower case) => value
     */
    public static function fill(string $text, array $values): string
    {
        $value = fn (string $name) => trim((string) ($values[Str::lower(preg_replace('/\s+/', '', $name))] ?? ''));
        $lines = [];

        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (preg_match_all(self::PATTERN, $line, $m) && ! array_filter($m[1], fn ($k) => $value($k) !== '')
                && self::onlyLabel(preg_replace(self::PATTERN, '', $line))) {
                continue;
            }

            $lines[] = preg_replace_callback(self::PATTERN, fn ($m) => $value($m[1]) !== '' ? $value($m[1]) : self::EMPTY, $line);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** @return list<string> the variables $text uses, lower case */
    public static function used(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique(array_map(fn ($k) => Str::lower(preg_replace('/\s+/', '', $k)), $m[1])));
    }

    /**
     * The variables in $text a welcome cannot fill. With $fieldKeys (the form's own fields), {jawaban:x} must name one.
     *
     * @param  list<string>|null  $fieldKeys
     * @return list<string>
     */
    public static function unknown(string $text, ?array $fieldKeys = null): array
    {
        $known = [...array_keys(self::VARIABLES), ...TemplateRenderer::PLACEHOLDERS, 'telepon', 'email'];
        $keys = $fieldKeys === null ? null : array_map(fn ($k) => Str::lower((string) $k), $fieldKeys);

        return array_values(array_filter(self::used($text), fn ($v) => str_starts_with($v, self::ANSWER_PREFIX)
            ? ($keys !== null && ! in_array(substr($v, strlen(self::ANSWER_PREFIX)), $keys, true))
            : ! in_array($v, $known, true)));
    }

    /** "Varian :", "- *Alamat*:", "" — what is left of a line once its variables are taken out, when it is just a label. */
    private static function onlyLabel(string $rest): bool
    {
        $rest = trim($rest);

        return $rest === '' || (bool) preg_match('/^[\-•*·>\s]*[^.!?{}:\n]{0,40}[:=][\s*_~]*$/u', $rest);
    }

    /** "+6281234567890" as "081234567890". */
    public static function localNumber(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
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
