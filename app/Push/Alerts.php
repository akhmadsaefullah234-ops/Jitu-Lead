<?php

namespace App\Push;

use App\Enums\Role;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use App\Jobs\SendPushAlert;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides who is told about a new lead or message, and what the notification says.
 * A new lead goes to the agency's admins and the lead's owner; a message goes to the lead's owner
 * (or the admins when nobody owns the lead yet). People can switch each kind off on their own.
 */
class Alerts
{
    public function __construct(private CurrentTenant $current) {}

    /** @param  string  $via  Where it came from, shown in the title ("formulir", "WhatsApp", ...) */
    public function newLead(Lead $lead, string $via, ?string $firstMessage = null): void
    {
        $tenant = $this->current->get();

        if ($tenant === null) {
            return;
        }

        $body = collect([$lead->name, $firstMessage ? Str::limit($firstMessage, 80) : ($lead->phone ?: $lead->email)])->filter()->implode(' · ');

        $this->send($this->recipients($tenant, $lead, withAdmins: true), 'notify_leads', [
            'title' => "Lead baru dari {$via}",
            'body' => $body,
            'url' => LeadResource::getUrl('edit', ['record' => $lead], tenant: $tenant),
            'tag' => 'lead-'.$lead->getKey(),
        ]);
    }

    public function newMessage(Lead $lead, WaMessage $message): void
    {
        $tenant = $this->current->get();

        if ($tenant === null) {
            return;
        }

        $text = filled($message->body) ? Str::limit(trim((string) $message->body), 100) : match ($message->type) {
            'image' => '[Gambar]',
            'video' => '[Video]',
            'audio' => '[Pesan suara]',
            'document' => '[Dokumen]',
            default => '[Pesan]',
        };

        $this->send($this->recipients($tenant, $lead, withAdmins: $lead->owner_id === null), 'notify_messages', [
            'title' => 'Pesan WhatsApp dari '.($lead->name ?: $lead->phone),
            'body' => $text,
            'url' => Inbox::getUrl(['lead' => $lead->getKey()], tenant: $tenant),
            'tag' => 'chat-'.$lead->getKey(),
        ]);
    }

    /** @return Collection<int, User> */
    private function recipients(Tenant $tenant, Lead $lead, bool $withAdmins): Collection
    {
        $members = $tenant->activeUsers()->get();

        return $members->filter(fn (User $u) => $u->getKey() === $lead->owner_id
            || ($withAdmins && $u->pivot->role === Role::Admin))->values();
    }

    /**
     * @param  Collection<int, User>  $people
     * @param  array{title: string, body: string, url: string, tag?: string}  $payload
     */
    private function send(Collection $people, string $preference, array $payload): void
    {
        $ids = $people->filter(fn (User $u) => $u->{$preference})->pluck('id')->all();

        if ($ids !== [] && app(PushTransport::class)->enabled()) {
            SendPushAlert::dispatch($ids, $payload);
        }
    }
}
