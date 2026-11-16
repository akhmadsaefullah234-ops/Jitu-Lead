<?php

namespace App\Ai;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * An agency's choices for how its WhatsApp AI writes and when it sends (tenants.ai_settings, set in
 * Pengetahuan AI > Arahan AI (lanjutan)). Every key left unset keeps the behaviour agencies had before these
 * settings existed: short replies without formatting, no extra checks, no pause, no quiet hours.
 */
final class AiSettings
{
    public const FORMATS = [
        'short' => 'Singkat tanpa format (1 sampai 3 kalimat)',
        'whatsapp' => 'Format WhatsApp (*tebal*, _miring_, daftar bernomor, emoji)',
    ];

    public const QUIET_MODES = [
        'greet' => 'Balas sambutan singkat, AI menjawab lengkap pagi harinya',
        'defer' => 'Tidak membalas sampai pagi, AI menjawab saat jam malam selesai',
    ];

    public const DEFAULT_QUIET_MESSAGE = 'Terima kasih kak, pesannya sudah kami terima 🙏 Kami balas lengkap besok pagi ya kak 😊';

    /** Phrases a property agency is offered for the guard; none is checked until the agency saves them. */
    public const SUGGESTED_PHRASES = ['siap huni', 'pasti disetujui', 'dijamin', 'gratis', 'sudah SHM', 'bersertifikat SHM'];

    /** Handoff words offered to a property agency on top of its own list. */
    public const PROPERTY_HANDOFF = ['wna', 'orang asing', 'warga negara asing', 'foreigner', 'notaris', 'sengketa', 'kpr ditolak', 'bi checking', 'slik', 'gugatan', 'pembatalan booking'];

    public const MAX_LINES = 20;

    public const MAX_DELAY = 60;

    /** Library items the first automatic reply to a lead may carry (brochure, price list). */
    public const MAX_WELCOME_ASSETS = 2;

    /** Minutes, at most, the AI may stay out of a chat a person is handling. */
    public const MAX_PAUSE_MINUTES = 1440;

    private function __construct(private array $values) {}

    public static function of(?Tenant $tenant): self
    {
        return new self((array) ($tenant?->ai_settings ?? []));
    }

    /** The values as the settings form shows them, defaults filled in. */
    public function form(): array
    {
        return [
            'format' => $this->format(),
            'max_lines' => $this->maxLines(),
            'guard' => $this->guard(),
            'forbidden_phrases' => implode(', ', $this->forbiddenPhrases()),
            'delay_min' => $this->delay()[0],
            'delay_max' => $this->delay()[1],
            'typing' => $this->typing(),
            'quiet_from' => $this->values['quiet_from'] ?? null,
            'quiet_until' => $this->values['quiet_until'] ?? null,
            'quiet_mode' => $this->quietMode(),
            'quiet_message' => $this->quietMessage(),
            'welcome_assets' => $this->welcomeAssets(),
            'agent_pause' => $this->agentPauseMinutes(),
            'handoff_pause' => $this->handoffPauseMinutes(),
            'product_list' => $this->asksProductWithList(),
        ];
    }

    /** What is stored from the settings form: cleaned, within bounds. */
    public static function fromForm(array $data): array
    {
        $time = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $v) ? substr($v, 0, 5) : null;
        $min = max(0, min(self::MAX_DELAY, (int) ($data['delay_min'] ?? 0)));
        $max = max($min, min(self::MAX_DELAY, (int) ($data['delay_max'] ?? 0)));
        $from = $time($data['quiet_from'] ?? null);
        $until = $time($data['quiet_until'] ?? null);

        return [
            'format' => array_key_exists($data['format'] ?? '', self::FORMATS) ? $data['format'] : 'short',
            'max_lines' => max(1, min(self::MAX_LINES, (int) ($data['max_lines'] ?? 8))),
            'guard' => (bool) ($data['guard'] ?? false),
            'forbidden_phrases' => self::phrases((string) ($data['forbidden_phrases'] ?? '')),
            'delay_min' => $min,
            'delay_max' => $max,
            'typing' => (bool) ($data['typing'] ?? true),
            'quiet_from' => $from !== null && $until !== null && $from !== $until ? $from : null,
            'quiet_until' => $from !== null && $until !== null && $from !== $until ? $until : null,
            'quiet_mode' => array_key_exists($data['quiet_mode'] ?? '', self::QUIET_MODES) ? $data['quiet_mode'] : 'greet',
            'quiet_message' => mb_substr(trim((string) ($data['quiet_message'] ?? '')), 0, 500) ?: self::DEFAULT_QUIET_MESSAGE,
            'welcome_assets' => self::ids($data['welcome_assets'] ?? []),
            'agent_pause' => max(1, min(self::MAX_PAUSE_MINUTES, (int) ($data['agent_pause'] ?? self::defaultAgentPause()))),
            'handoff_pause' => max(0, min(self::MAX_PAUSE_MINUTES, (int) ($data['handoff_pause'] ?? 0))),
            'product_list' => (bool) ($data['product_list'] ?? true),
        ];
    }

    /** @return list<string> */
    public static function phrases(string $text): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($p) => trim(preg_replace('/\s+/u', ' ', $p)), preg_split('/[,\n]+/', $text) ?: []),
            fn ($p) => mb_strlen($p) >= 2 && mb_strlen($p) <= 60)));
    }

    public function format(): string
    {
        return array_key_exists($this->values['format'] ?? '', self::FORMATS) ? $this->values['format'] : 'short';
    }

    public function whatsappFormat(): bool
    {
        return $this->format() === 'whatsapp';
    }

    public function maxLines(): int
    {
        return max(1, min(self::MAX_LINES, (int) ($this->values['max_lines'] ?? 8)));
    }

    /** The style sentence at the top of the prompt. "Short" is word for word what every agency had before. */
    public function styleInstruction(string $business, string $agency): string
    {
        $intro = "Kamu asisten WhatsApp untuk {$business} \"{$agency}\". Balas calon pembeli dalam bahasa Indonesia yang sopan, singkat, dan hangat, seperti staf agensi sungguhan.";

        return $this->whatsappFormat()
            ? $intro.' Boleh memakai format WhatsApp: *tebal* untuk angka dan istilah penting, _miring_ untuk nama proyek, daftar bernomor 1. 2. 3. untuk pilihan, dan emoji secukupnya. '
                .'Paling banyak '.$this->maxLines().' baris. Jangan memakai format lain: tanpa judul #, tanpa **dua bintang**, tanpa tabel, tanpa tautan [teks](url).'
            : $intro.' Satu sampai tiga kalimat, tanpa markdown.';
    }

    /**
     * Markdown a model writes from habit, turned into WhatsApp's own: **tebal** to *tebal*, "# Judul" to *Judul*,
     * "- a" to "• a"; and the answer kept within the line limit (the rest is dropped at a line break).
     */
    public function shape(string $answer): string
    {
        if (! $this->whatsappFormat()) {
            return $answer;
        }

        $answer = (string) preg_replace('/\*\*(.+?)\*\*/u', '*$1*', $answer);
        $answer = (string) preg_replace('/__(.+?)__/u', '_$1_', $answer);
        $answer = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu', '*$1*', $answer);
        $answer = (string) preg_replace('/^[ \t]*[-*][ \t]+/mu', '• ', $answer);
        $lines = preg_split('/\R/u', trim($answer)) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (trim($line) !== '' && count(array_filter($kept, fn ($l) => trim($l) !== '')) >= $this->maxLines()) {
                break;
            }

            $kept[] = rtrim($line);
        }

        return trim(implode("\n", $kept));
    }

    /** The number and phrase check before an automatic property reply (PropertyGuard). */
    public function guard(): bool
    {
        return (bool) ($this->values['guard'] ?? false);
    }

    /** @return list<string> */
    public function forbiddenPhrases(): array
    {
        return array_values(array_filter((array) ($this->values['forbidden_phrases'] ?? []), 'is_string'));
    }

    /** @return array{0: int, 1: int} seconds before an automatic reply, at least and at most */
    public function delay(): array
    {
        $min = max(0, min(self::MAX_DELAY, (int) ($this->values['delay_min'] ?? 0)));

        return [$min, max($min, min(self::MAX_DELAY, (int) ($this->values['delay_max'] ?? 0)))];
    }

    /** A random pause within the agency's range; 0 when none is set. */
    public function pause(): int
    {
        [$min, $max] = $this->delay();

        return $max === 0 ? 0 : random_int($min, $max);
    }

    public function typing(): bool
    {
        return (bool) ($this->values['typing'] ?? true);
    }

    public function quietMode(): string
    {
        return array_key_exists($this->values['quiet_mode'] ?? '', self::QUIET_MODES) ? $this->values['quiet_mode'] : 'greet';
    }

    public function quietMessage(): string
    {
        return filled($this->values['quiet_message'] ?? null) ? (string) $this->values['quiet_message'] : self::DEFAULT_QUIET_MESSAGE;
    }

    /**
     * When the quiet hours that $at falls in end, or null when $at is outside them (or none are set). Hours may run
     * past midnight (21:00 to 07:00).
     */
    public function quietUntil(CarbonImmutable $at, string $timezone): ?CarbonImmutable
    {
        $from = $this->values['quiet_from'] ?? null;
        $until = $this->values['quiet_until'] ?? null;

        if (! is_string($from) || ! is_string($until) || $from === $until) {
            return null;
        }

        $local = $at->setTimezone($timezone);
        $now = $local->format('H:i');
        $overnight = $from > $until;
        $inside = $overnight ? ($now >= $from || $now < $until) : ($now >= $from && $now < $until);

        if (! $inside) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $until));
        $end = $local->setTime($h, $m);

        return $end->lessThanOrEqualTo($local) ? $end->addDay() : $end;
    }

    /** When the current quiet hours began, for "greeted once per night". */
    public function quietStart(CarbonImmutable $until): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', (string) $this->values['quiet_from']));
        $start = $until->setTime($h, $m);

        return $start->greaterThanOrEqualTo($until) ? $start->subDay() : $start;
    }

    /**
     * "Tanyakan tipe lewat daftar": while a lead's product (property type) is not known, the AI asks which one with
     * the list of names and names no price until it is clear. On by default, as it always was.
     */
    public function asksProductWithList(): bool
    {
        return (bool) ($this->values['product_list'] ?? true);
    }

    /** @return list<int> the library items the first automatic reply to a lead carries; none by default */
    public function welcomeAssets(): array
    {
        return self::ids($this->values['welcome_assets'] ?? []);
    }

    /** Minutes the AI keeps out of a chat after a person sent a message in it (config, 5 minutes, unless set). */
    public function agentPauseMinutes(): int
    {
        return max(1, min(self::MAX_PAUSE_MINUTES, (int) ($this->values['agent_pause'] ?? self::defaultAgentPause())));
    }

    public function agentPauseSeconds(): int
    {
        return isset($this->values['agent_pause']) ? $this->agentPauseMinutes() * 60 : (int) config('whatsapp.ai_after_send_pause_seconds');
    }

    /** Minutes the AI keeps out of a chat after it handed the chat to a person; 0 (the default) keeps answering. */
    public function handoffPauseMinutes(): int
    {
        return max(0, min(self::MAX_PAUSE_MINUTES, (int) ($this->values['handoff_pause'] ?? 0)));
    }

    private static function defaultAgentPause(): int
    {
        return max(1, (int) round((int) config('whatsapp.ai_after_send_pause_seconds') / 60));
    }

    /** @return list<int> */
    private static function ids(mixed $ids): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $ids), fn (int $id) => $id > 0))), 0, self::MAX_WELCOME_ASSETS);
    }
}
