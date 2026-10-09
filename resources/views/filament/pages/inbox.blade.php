<x-filament-panels::page>
    <style>
        .wa { display: grid; grid-template-columns: 20rem 1fr; gap: 1rem; min-height: 32rem; }
        @media (max-width: 800px) {
            .wa { grid-template-columns: 1fr; min-height: 0; }
            .wa--open .wa-list, .wa:not(.wa--open) .wa-chat { display: none; }
            .wa-list, .wa-chat { max-height: none; }
            .wa-chat { min-height: calc(100vh - 11rem); }
        }
        .wa-back { display: none; font-size: .8rem; font-weight: 600; color: var(--primary-600); }
        @media (max-width: 800px) { .wa-back { display: inline-block; margin-bottom: .15rem; } }
        .wa-list, .wa-chat { background: white; border: 1px solid var(--gray-200); border-radius: .75rem; overflow: hidden; }
        .wa-list { overflow-y: auto; max-height: calc(100vh - 12rem); }
        .wa-item { display: block; width: 100%; text-align: left; padding: .7rem .9rem; border-bottom: 1px solid var(--gray-100); }
        .wa-item[aria-current="true"] { background: var(--gray-100); }
        .wa-item .n { display: flex; justify-content: space-between; font-weight: 600; font-size: .875rem; }
        .wa-item .p { font-size: .75rem; color: var(--gray-500); }
        .wa-badge { background: var(--primary-600); color: white; border-radius: 9999px; padding: 0 .45rem; font-size: .7rem; }
        .wa-chat { display: flex; flex-direction: column; max-height: calc(100vh - 12rem); }
        .wa-head { padding: .7rem 1rem; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
        .wa-banner { padding: .55rem 1rem; font-size: .8125rem; border-bottom: 1px solid var(--gray-200); }
        .wa-banner b { display: block; }
        .wa-banner.official { background: #ecfdf5; color: #065f46; }
        .wa-banner.gateway { background: #eff6ff; color: #1e40af; }
        .wa-banner.paid { background: #fffbeb; color: #92400e; }
        .wa-banner.none { background: var(--gray-100); color: var(--gray-700); }
        .wa-msgs { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .4rem; background: var(--gray-50); }
        .wa-m { max-width: 75%; padding: .45rem .7rem; border-radius: .7rem; font-size: .875rem; word-break: break-word; background: white; border: 1px solid var(--gray-200); }
        .wa-m.out { align-self: flex-end; background: #dcfce7; border-color: #bbf7d0; }
        .wa-m.out.gw { background: #dbeafe; border-color: #bfdbfe; }
        .wa-m .b { white-space: pre-wrap; }
        .wa-m .meta { font-size: .68rem; color: var(--gray-500); margin-top: .15rem; }
        .wa-m.failed { border-color: var(--danger-600); }
        .wa-compose { display: flex; gap: .5rem; padding: .7rem; border-top: 1px solid var(--gray-200); }
        .wa-compose textarea { flex: 1; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .7rem; font-size: .875rem; resize: none; }
        .wa-empty { padding: 2rem 1rem; text-align: center; color: var(--gray-500); font-size: .875rem; }
        .dark .wa-list, .dark .wa-chat, .dark .wa-m { background: var(--gray-900); border-color: var(--gray-700); }
        .dark .wa-msgs { background: var(--gray-950); }
        .dark .wa-m.out { background: #14532d; } .dark .wa-m.out.gw { background: #1e3a8a; }
        .dark .wa-item[aria-current="true"] { background: var(--gray-800); }
        .dark .wa-banner { background: var(--gray-800) !important; color: var(--gray-100) !important; }
        .dark .wa-compose textarea { background: var(--gray-900); border-color: var(--gray-700); }
    </style>

    <div class="wa {{ $conversation ? 'wa--open' : '' }}" wire:poll.10s>
        <aside class="wa-list" aria-label="Daftar percakapan">
            @forelse ($conversations as $c)
                <button type="button" class="wa-item" wire:key="c-{{ $c->id }}" wire:click="open({{ $c->lead_id }})" aria-current="{{ $conversation?->id === $c->id ? 'true' : 'false' }}">
                    <div class="n"><span>{{ $c->lead->name }}</span>@if ($c->unread_count)<span class="wa-badge">{{ $c->unread_count }}</span>@endif</div>
                    <div class="p">{{ $c->lead->phone }} · {{ $c->last_message_at?->diffForHumans() }}</div>
                </button>
            @empty
                <div class="wa-empty">Belum ada percakapan. Buka chat dari kartu lead, atau tunggu pesan masuk.</div>
            @endforelse
        </aside>

        <section class="wa-chat" aria-label="Chat">
            @if ($conversation)
                <div class="wa-head">
                    <div>
                        <button type="button" class="wa-back" wire:click="close">&larr; Semua chat</button>
                        <br class="wa-back-br"><strong>{{ $conversation->lead->name }}</strong>
                        <div class="p" style="font-size:.75rem;color:var(--gray-500)">{{ $conversation->phone }} · {{ $conversation->lead->stage?->name }}</div>
                    </div>
                    <a class="jl-link" style="font-size:.8rem;font-weight:600" href="{{ $leadUrl($conversation->lead) }}" wire:navigate>Buka lead</a>
                </div>
                <div class="wa-banner {{ $decision->tone() }}">
                    <b>{{ $decision->title }}</b>{{ $decision->note }}
                </div>
                <div class="wa-msgs" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:updated.window="$el.scrollTop = $el.scrollHeight">
                    @forelse ($conversation->messages as $m)
                        <div wire:key="m-{{ $m->id }}" class="wa-m {{ $m->direction === 'out' ? 'out' : '' }} {{ $m->channel && ! $m->channel->isOfficial() ? 'gw' : '' }} {{ $m->status === \App\Enums\MessageStatus::Failed ? 'failed' : '' }}">
                            <div class="b">{{ $m->body ?? '['.$m->type.']' }}</div>
                            <div class="meta">
                                {{ $m->sent_at?->format('d M H:i') }}
                                @if ($m->channel) · {{ $m->channel->name }}@endif
                                @if ($m->is_intro) · perkenalan @endif
                                @if ($m->is_paid) · berbayar @endif
                                @if ($m->direction === 'out') · {{ $m->status->value }}@endif
                                @if ($m->error) · {{ $m->error }}@endif
                            </div>
                        </div>
                    @empty
                        <div class="wa-empty">Belum ada pesan.</div>
                    @endforelse
                </div>
                @if ($aiDraft)
                    <div class="ai-draft" style="margin:.5rem 0;padding:.6rem .75rem;border:1px solid #fecaca;background:#fef2f2;border-radius:.6rem;font-size:.85rem">
                        @if ($aiDraft->status === \App\Models\AiDraft::HANDOFF)
                            <strong>AI menyerahkan chat ini ke Anda.</strong> {{ $aiDraft->reason }}.
                            <button type="button" wire:click="discardAiDraft({{ $aiDraft->id }})" style="margin-left:.5rem;text-decoration:underline">Tutup</button>
                        @else
                            <strong>Saran balasan AI</strong>
                            @if ($aiDraft->reason) <span style="color:#6b7280">({{ $aiDraft->reason }})</span> @endif
                            <div style="margin:.35rem 0;white-space:pre-wrap">{{ $aiDraft->body }}</div>
                            <button type="button" wire:click="useAiDraft({{ $aiDraft->id }})" style="font-weight:600;color:#b91c1c">Pakai saran ini</button>
                            <button type="button" wire:click="discardAiDraft({{ $aiDraft->id }})" style="margin-left:.75rem;color:#6b7280">Buang</button>
                        @endif
                    </div>
                @endif
                @if ($aiPausedUntil && $conversation->lead)
                    <div style="font-size:.78rem;color:#6b7280;margin:.25rem 0">AI dijeda di chat ini sampai {{ $aiPausedUntil->format('H:i:s') }} karena Anda sedang membalas. Setelah itu AI aktif lagi sendiri.</div>
                @endif
                <div class="wa-compose">
                    @if ($decision->isPaid())
                        {{ $this->sendPaidTemplateAction }}
                    @elseif ($decision->canSend())
                        <textarea rows="2" wire:model.live.debounce.1500ms="draft" placeholder="Tulis pesan" aria-label="Pesan" x-on:keydown.enter.prevent="if (! $event.shiftKey) $wire.send()"></textarea>
                        <x-filament::button wire:click="send" wire:loading.attr="disabled">Kirim</x-filament::button>
                    @else
                        <span class="wa-empty" style="padding:.3rem">Hubungkan nomor di menu Koneksi WhatsApp.</span>
                    @endif
                </div>
            @else
                <div class="wa-empty">Pilih percakapan dari daftar.</div>
            @endif
        </section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
