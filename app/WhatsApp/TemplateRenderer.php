<?php

namespace App\WhatsApp;

use App\Models\Lead;
use App\Models\User;

/**
 * Fills {nama}, {agen}, {agensi} and {properti} in message templates.
 */
class TemplateRenderer
{
    public const PLACEHOLDERS = ['nama', 'agen', 'agensi', 'properti'];

    /**
     * @return array{text: string, parameters: array<int, string>} Parameters are in order of appearance, as Meta's positional template variables expect.
     */
    public function render(string $template, Lead $lead, User $agent, string $agencyName): array
    {
        $values = [
            'nama' => explode(' ', trim($lead->name))[0] ?: $lead->name,
            'agen' => $agent->name,
            'agensi' => $agencyName,
            'properti' => $lead->property?->name ?? $lead->location ?? 'properti yang Anda tanyakan',
        ];

        $parameters = [];

        $text = preg_replace_callback('/\{('.implode('|', self::PLACEHOLDERS).')\}/', function (array $match) use ($values, &$parameters) {
            $parameters[] = $values[$match[1]];

            return $values[$match[1]];
        }, $template);

        return ['text' => $text, 'parameters' => $parameters];
    }
}
