<?php

namespace App\Ai\Facts;

use App\Ai\PropertyGuard;
use App\Models\AiFact;
use Illuminate\Support\Collection;

/**
 * How an agency is paid, as data: which ways to pay it offers (cash, cash in instalments, developer instalments, KPR,
 * something else), its down payment (none, 0%, free as a promo, light, standard) and its booking fee. Filled in the
 * "Isi properti" wizard (project facts, FactKeys::PAYMENT); nothing is assumed when it is empty. The AI is told it as
 * "MODEL PEMBAYARAN AGENSI", asks only about what is offered, and an answer that claims an option or a down payment the
 * model does not have is held (claims()). Must run inside the tenant.
 */
final class PaymentModel
{
    public const OPTIONS = [
        'cash' => 'Cash keras',
        'installment' => 'Cash bertahap',
        'developer' => 'Cicilan developer',
        'kpr' => 'KPR',
        'other' => 'Lainnya',
    ];

    public const DOWN_PAYMENTS = [
        'none' => 'Tidak ada DP',
        'zero' => 'DP 0%',
        'free' => 'DP gratis (promo)',
        'light' => 'DP ringan',
        'standard' => 'DP standar',
    ];

    /** Ways of saying an option is offered: held when the model does not offer it, a fact when it does. */
    private const CLAIMS = [
        'kpr' => ['bisa KPR', 'bisa pakai KPR', 'bisa memakai KPR', 'bisa lewat KPR', 'bisa melalui KPR', 'bisa dengan KPR', 'bisa ajukan KPR', 'bisa mengajukan KPR',
            'bisa diajukan KPR', 'KPR tersedia', 'tersedia KPR', 'menerima KPR', 'melayani KPR', 'bisa juga KPR', 'pilihan KPR', 'opsi KPR', 'skema KPR'],
        'developer' => ['cicilan developer', 'cicilan langsung ke developer', 'cicil ke developer', 'cicilan in-house', 'in-house'],
        'installment' => ['cash bertahap', 'tunai bertahap'],
        'dp_none' => ['tanpa DP', 'tidak ada DP', 'bebas DP', 'tanpa uang muka', 'tidak pakai DP'],
        'dp_zero' => ['DP 0', 'DP 0%', 'DP nol', 'DP nol persen', 'uang muka 0%'],
        'dp_free' => ['DP gratis', 'gratis DP', 'free DP', 'DP free', 'uang muka gratis'],
    ];

    /** @param  array<string, string>  $values  the approved payment facts by key */
    private function __construct(private array $values) {}

    public static function current(): self
    {
        return self::from(Facts::approved()->whereNull('subject'));
    }

    /** @param  Collection<int, AiFact>  $facts  approved project facts */
    public static function from(Collection $facts): self
    {
        return new self($facts->keyBy('key')->map(fn (AiFact $f) => (string) $f->value)->only([...FactKeys::PAYMENT, 'booking_fee'])->all());
    }

    /** @param  array<string, mixed>  $values  wizard state (project.*) */
    public static function fromArray(array $values): self
    {
        $out = [];

        foreach ([...FactKeys::PAYMENT, 'booking_fee'] as $key) {
            $value = $key === 'pay_options' ? self::encode($values[$key] ?? []) : FactFormat::clean($key, $values[$key] ?? null);

            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return new self($out);
    }

    /** The wizard's checkbox list as stored: "cash,kpr". */
    public static function encode(mixed $options): ?string
    {
        $list = array_values(array_intersect(array_keys(self::OPTIONS), is_array($options) ? $options : explode(',', (string) $options)));

        return $list === [] ? null : implode(',', $list);
    }

    /** @return list<string> */
    public static function decode(?string $stored): array
    {
        return array_values(array_intersect(array_keys(self::OPTIONS), array_map('trim', explode(',', (string) $stored))));
    }

    /** True when the agency said how it is paid: at least one option and its down payment. */
    public function filled(): bool
    {
        return $this->options() !== [] && isset(self::DOWN_PAYMENTS[$this->values['dp_model'] ?? '']);
    }

    /** @return list<string> option keys offered */
    public function options(): array
    {
        return self::decode($this->values['pay_options'] ?? null);
    }

    public function offers(string $option): bool
    {
        return in_array($option, $this->options(), true);
    }

    public function downPayment(): ?string
    {
        $dp = $this->values['dp_model'] ?? null;

        return isset(self::DOWN_PAYMENTS[$dp]) ? $dp : null;
    }

    /** "cash keras, cash bertahap, atau KPR": the offered ways in words, for a question to the client. */
    public function choices(): string
    {
        $words = array_map(fn (string $o) => $o === 'other' ? (string) ($this->values['pay_other'] ?? 'cara lain') : (in_array($o, ['kpr'], true) ? 'KPR' : mb_strtolower(self::OPTIONS[$o])), $this->options());
        $last = array_pop($words);

        return match (count($words)) {
            0 => (string) $last,
            1 => $words[0].' atau '.$last,
            default => implode(', ', $words).', atau '.$last,
        };
    }

    /** @return array<string, string> label => text, for the standard knowledge entry */
    public function lines(): array
    {
        if (! $this->filled()) {
            return [];
        }

        $offered = array_map(fn (string $o) => $this->describe($o), $this->options());
        $missing = array_values(array_diff(array_keys(self::OPTIONS), $this->options(), ['other']));

        return array_filter([
            'Cara bayar yang tersedia' => implode('; ', $offered),
            'Tidak tersedia' => $missing === [] ? null : implode(', ', array_map(fn (string $o) => $o === 'kpr' ? 'KPR' : mb_strtolower(self::OPTIONS[$o]), $missing)),
            'Uang muka (DP)' => $this->downPaymentText(),
            'Tanda jadi' => isset($this->values['booking_fee'])
                ? FactFormat::show('booking_fee', $this->values['booking_fee']).match ($this->values['booking_fee_reduces'] ?? null) {
                    'ya' => ', mengurangi harga', 'tidak' => ', di luar harga (tidak mengurangi harga)', default => '',
                } : null,
            'Catatan pembayaran' => $this->values['payment_notes'] ?? null,
        ], fn ($v) => filled($v));
    }

    private function describe(string $option): string
    {
        return match ($option) {
            'installment' => 'cash bertahap'.(isset($this->values['installment_tenor']) ? ' sampai '.$this->values['installment_tenor'].' bulan' : '')
                .match ($this->values['installment_interest'] ?? null) {
                    'tidak' => ', tanpa bunga', 'ada' => ', dengan bunga', default => '',
                },
            'developer' => 'cicilan developer'.(isset($this->values['developer_tenor']) ? ' sampai '.$this->values['developer_tenor'].' bulan' : ''),
            'kpr' => 'KPR'.(filled($this->values['kpr_banks'] ?? null) ? ' (bank rekanan: '.$this->values['kpr_banks'].')' : ''),
            'other' => (string) ($this->values['pay_other'] ?? 'cara lain'),
            default => 'cash keras',
        };
    }

    private function downPaymentText(): ?string
    {
        $amount = $this->values['dp_amount'] ?? null;

        return match ($this->downPayment()) {
            'none' => 'tidak ada DP',
            'zero' => 'DP 0%',
            'free' => 'DP gratis (promo'.(filled($this->values['dp_promo'] ?? null) ? ', '.$this->values['dp_promo'] : '').')',
            'light' => 'DP ringan'.($amount ? ', mulai '.$amount : ''),
            'standard' => 'DP'.($amount ? ' '.$amount : ' standar'),
            default => null,
        };
    }

    /** The model as the AI reads it, with how to use it; empty when the agency has not filled it. */
    public function prompt(): string
    {
        if (! $this->filled()) {
            return '';
        }

        $lines = collect($this->lines())->map(fn (string $v, string $k) => "{$k}: {$v}.")->implode("\n");

        return "MODEL PEMBAYARAN AGENSI (data dari agensi, ikuti persis):\n{$lines}\n"
            .'Tanyakan atau tawarkan cara bayar HANYA dari yang tersedia di atas ('.$this->choices().'). '
            .'Cara bayar yang tidak tersedia jangan ditawarkan; bila klien menanyakannya, jawab jujur bahwa belum tersedia lalu tawarkan yang ada. '
            .'Jangan menyebut "tanpa DP", "DP 0", atau "gratis" kecuali tertulis di atas. Jangan menjanjikan KPR disetujui.';
    }

    /**
     * Claims in an answer that the model contradicts: an option it does not offer, or a down payment it does not have.
     * A denial ("KPR belum tersedia") is not a claim. Empty when the model is not filled (nothing to compare with).
     *
     * @return list<string>
     */
    public function claims(string $answer, ?string $sources = null): array
    {
        if (! $this->filled()) {
            return [];
        }

        $problems = [];

        // Something "gratis" the agency never wrote down (a free DP is in the model, a free bonus in the knowledge).
        if ($this->downPayment() !== 'free' && ($phrase = self::stated($answer, ['gratis', 'free'])) !== null
            && ($sources === null || ! preg_match('/\b(?:gratis|free)\b/iu', $sources))) {
            $problems[] = "menyebut \"{$phrase}\" yang tidak ada di data agensi";
        }

        foreach (['kpr' => 'KPR', 'developer' => 'cicilan developer', 'installment' => 'cash bertahap'] as $option => $what) {
            if (! $this->offers($option) && ($phrase = self::stated($answer, self::CLAIMS[$option])) !== null) {
                $problems[] = "menawarkan {$what} (\"{$phrase}\") yang tidak tersedia di model pembayaran";
            }
        }

        foreach (['none' => 'dp_none', 'zero' => 'dp_zero', 'free' => 'dp_free'] as $dp => $claims) {
            if (! in_array($this->downPayment(), $this->allowedDownPayments($dp), true) && ($phrase = self::stated($answer, self::CLAIMS[$claims])) !== null) {
                $problems[] = "menyebut \"{$phrase}\" padahal model pembayaran: ".$this->downPaymentText();
            }
        }

        return $problems;
    }

    /** A down payment of nothing reads the same whether it is "none", "0%" or free. */
    private function allowedDownPayments(string $claimed): array
    {
        return match ($claimed) {
            'none' => ['none', 'zero', 'free'],
            'zero' => ['zero', 'free', 'none'],
            default => ['free'],
        };
    }

    /**
     * Phrases the model makes facts, so a forbidden or test phrase that names them passes ("bisa pakai KPR" when KPR
     * is offered, "gratis" when the down payment is a free promo).
     *
     * @return list<string>
     */
    public function granted(): array
    {
        if (! $this->filled()) {
            return [];
        }

        $out = [];

        foreach (['kpr', 'developer', 'installment'] as $option) {
            if ($this->offers($option)) {
                $out = [...$out, ...self::CLAIMS[$option]];
            }
        }

        $out = match ($this->downPayment()) {
            'none', 'zero' => [...$out, ...self::CLAIMS['dp_none'], ...self::CLAIMS['dp_zero']],
            'free' => [...$out, ...self::CLAIMS['dp_none'], ...self::CLAIMS['dp_zero'], ...self::CLAIMS['dp_free'], 'gratis', 'free'],
            default => $out,
        };

        return array_values(array_unique($out));
    }

    /** @param  list<string>  $phrases */
    private static function stated(string $answer, array $phrases): ?string
    {
        foreach ($phrases as $phrase) {
            if (PropertyGuard::states($answer, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }
}
