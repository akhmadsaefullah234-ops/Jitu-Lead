<?php

namespace App\WhatsApp;

use Illuminate\Http\Request;

/**
 * What the CRM needs from a WhatsApp number, whichever way it is connected.
 * The official Cloud API and the in-house gateway both implement this, so the
 * rest of the app never depends on how a number works internally.
 */
interface WhatsAppProvider
{
    public function sendText(string $to, string $text): SendResult;

    /**
     * @param  array<int, string>  $parameters  Positional template body parameters.
     */
    public function sendTemplate(string $to, string $name, string $language, array $parameters = []): SendResult;

    /**
     * Asks the provider whether the number is reachable. Returns null when it
     * is, otherwise a short reason.
     */
    public function checkConnection(): ?string;

    /**
     * True when the request really comes from the provider.
     */
    public function verifySignature(Request $request): bool;

    /**
     * @return array{messages: array<int, InboundMessage>, statuses: array<int, StatusUpdate>, session: ?string}
     */
    public function parseWebhook(Request $request): array;
}
