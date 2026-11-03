<x-filament-panels::page>
    <style>
        .wa { display: grid; grid-template-columns: 20rem 1fr; gap: 1rem; min-height: 32rem; }
        @media (max-width: 800px) {
            .wa { grid-template-columns: 1fr; min-height: 0; }
            .wa--open .wa-list, .wa:not(.wa--open) .wa-chat { display: none; }
            .wa-list, .wa-chat { max-height: none; }
            /* The chat fills the screen under the top bar: messages scroll, the compose box stays at the bottom,
               also while the keyboard is open (the viewport resizes with it, see pwa-head). */
            .wa .wa-chat { height: calc(100vh - 4.9rem); height: calc(100dvh - 4.9rem); max-height: none; margin-inline: -.75rem; border-radius: 0; border-inline: 0; }
            .fi-page:has(.wa--open) .fi-header { display: none; }
            .fi-page:has(.wa--open) .fi-page-header-main-ctn { padding-block: .3rem 0; row-gap: 0; }
            .wa .wa-compose { padding-bottom: calc(.7rem + env(safe-area-inset-bottom)); }
            .wa .wa-compose textarea { max-height: 30vh; max-height: 30dvh; }
        }
        /* Chat header: one compact row (back, name, stage, product, ⋯); the lead's details fold out under the name. */
        .wa-head { flex: none; padding: .45rem .6rem; border-bottom: 1px solid var(--gray-200); min-width: 0; }
        .wa-bar { display: flex; align-items: center; gap: .4rem; min-height: 2.5rem; min-width: 0; }
        .wa-back { display: none; flex: none; width: 2rem; height: 2rem; align-items: center; justify-content: center; border-radius: 9999px; color: var(--gray-700); }
        .wa-back:hover { background: var(--gray-100); }
        @media (max-width: 800px) { .wa-back { display: inline-flex; } }
        .wa-name { flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: .3rem; text-align: left; padding: .2rem 0; }
        .wa-name strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .95rem; }
        .wa-name .chev { flex: none; width: 1rem; height: 1rem; color: var(--gray-400); transition: transform .15s; }
        .wa-name[aria-expanded="true"] .chev { transform: rotate(180deg); }
        .wa-dot { flex: none; width: .55rem; height: .55rem; border-radius: 9999px; background: #f59e0b; }
        .wa-chip { flex: 0 1 auto; min-width: 0; max-width: 6rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .68rem; font-weight: 600; color: var(--gray-700); background: var(--gray-100); border-radius: 9999px; padding: .1rem .5rem; }
        .wa-chip-prod { flex: 0 1 auto; min-width: 0; max-width: 6rem; display: flex; }
        .wa-chip-prod .fi-badge { max-width: 100%; min-width: 0; }
        .wa-chip-prod .fi-badge-label-ctn, .wa-chip-prod .fi-badge-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wa-more { flex: none; }
        .wa-name { min-width: 5.5rem; }
        @media (max-width: 420px) { .wa-chip, .wa-chip-prod { max-width: 4.5rem; } .wa-chip-prod .fi-badge { padding-inline: .4rem; } .wa-chip-prod .fi-badge .fi-icon { display: none; } }
        @media (min-width: 1024px) { .wa-chip, .wa-chip-prod { max-width: 14rem; } }
        .wa-details { padding: .3rem 0 .15rem; font-size: .75rem; color: var(--gray-500); line-height: 1.5; overflow-wrap: anywhere; }
        .wa-details a { text-decoration: underline; }
        /* What the route means for this message: a quiet line, or a warning that opens to the full explanation. */
        .wa-route { flex: none; margin: 0 .7rem; padding: .3rem 0 0; font-size: .72rem; color: var(--gray-500); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wa-route.warn { padding: 0; margin: .35rem .7rem 0; white-space: normal; border-radius: .45rem; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .wa-route.warn summary { cursor: pointer; list-style: none; padding: .3rem .55rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wa-route.warn summary::-webkit-details-marker { display: none; }
        .wa-route.warn summary::before { content: "⚠ "; }
        .wa-route.warn[open] summary { white-space: normal; }
        .wa-route.warn p { margin: 0; padding: 0 .55rem .4rem; }
        .wa-paused { flex: none; margin: .3rem .7rem 0; font-size: .72rem; color: var(--gray-500); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dark .wa-route.warn { background: #451a03; color: #fde68a; border-color: #78350f; }
        .dark .wa-chip { background: var(--gray-800); color: var(--gray-200); }
        .wa-list, .wa-chat { background: white; border: 1px solid var(--gray-200); border-radius: .75rem; overflow: hidden; }
        .wa-list { overflow-y: auto; max-height: calc(100vh - 12rem); }
        .wa-item { display: block; width: 100%; text-align: left; padding: .7rem .9rem; border-bottom: 1px solid var(--gray-100); }
        .wa-item[aria-current="true"] { background: var(--gray-100); }
        .wa-item .n { display: flex; justify-content: space-between; font-weight: 600; font-size: .875rem; }
        .wa-item .p { font-size: .75rem; color: var(--gray-500); }
        .wa-prod { display: inline-block; margin-top: .2rem; font-size: .68rem; font-weight: 600; color: var(--primary-700); background: var(--primary-50); border-radius: 9999px; padding: .05rem .5rem; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wa-prod.none { color: var(--gray-500); background: var(--gray-100); }
        .dark .wa-prod { background: var(--gray-800); color: var(--primary-300); }
        .wa-badge { background: var(--primary-600); color: white; border-radius: 9999px; padding: 0 .45rem; font-size: .7rem; }
        .wa-chat { display: flex; flex-direction: column; max-height: calc(100vh - 12rem); }
        .wa-proof { font-size: .75rem; font-weight: 600; color: #92400e; background: #fef3c7; border-radius: 9999px; padding: .15rem .55rem; }
        .wa-msgs { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .4rem; background: var(--gray-50); }
        .wa-m { max-width: 75%; padding: .45rem .7rem; border-radius: .7rem; font-size: .875rem; word-break: break-word; background: white; border: 1px solid var(--gray-200); }
        .wa-m.out { align-self: flex-end; background: #dcfce7; border-color: #bbf7d0; }
        .wa-m.out.gw { background: #dbeafe; border-color: #bfdbfe; }
        .wa-m .b { white-space: pre-wrap; }
        .wa-m .meta { font-size: .68rem; color: var(--gray-500); margin-top: .15rem; }
        .wa-m.failed { border-color: var(--danger-600); }
        /* The compose box never shrinks; the messages and a long AI suggestion give up room (and scroll) instead,
           so the send and attach buttons stay on screen. */
        .wa-compose { flex: none; padding: .6rem .7rem .7rem; border-top: 1px solid var(--gray-200); min-width: 0; }
        .wa-msgs { min-height: 2.5rem; }
        .ai-draft { flex: none; max-height: 22vh; overflow-y: auto; }
        .ai-draft-body { white-space: pre-wrap; margin: .35rem 0; }
        .wa-compose-row { display: flex; align-items: flex-start; gap: .35rem; min-width: 0; }
        /* Level with the top of the text: a long message (e.g. an AI suggestion) never pushes them out of sight. */
        .wa-compose-row > .wa-compose-btn { flex: none; margin-top: .1rem; }
        /* 3 lines to start, grows with the text (see grow() below) up to 28% of the screen or 10 lines, then scrolls */
        .wa-compose textarea { flex: 1 1 auto; min-width: 0; width: 100%; box-sizing: border-box; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .7rem; font-size: .875rem; line-height: 1.5; resize: none; overflow-y: auto; max-height: min(28vh, calc(15em + .9rem + 2px)); max-height: min(28dvh, calc(15em + .9rem + 2px)); }
        .wa-compose-hint { margin: .25rem 0 0 2.6rem; font-size: .7rem; color: var(--gray-500); }
        @media (hover: none) and (pointer: coarse) { .wa-compose-hint { display: none; } }
        .wa-empty { padding: 2rem 1rem; text-align: center; color: var(--gray-500); font-size: .875rem; }
        .dark .wa-list, .dark .wa-chat, .dark .wa-m { background: var(--gray-900); border-color: var(--gray-700); }
        .dark .wa-msgs { background: var(--gray-950); }
        .dark .wa-m.out { background: #14532d; } .dark .wa-m.out.gw { background: #1e3a8a; }
        .dark .wa-item[aria-current="true"] { background: var(--gray-800); }
        .dark .wa-compose textarea { background: var(--gray-900); border-color: var(--gray-700); }
    </style>

    <div class="wa {{ $conversation ? 'wa--open' : '' }}" wire:poll.10s>
        <aside class="wa-list" aria-label="Daftar percakapan">
            @forelse ($conversations as $c)
                <button type="button" class="wa-item" wire:key="c-{{ $c->id }}" wire:click="open({{ $c->lead_id }})" aria-current="{{ $conversation?->id === $c->id ? 'true' : 'false' }}">
                    <div class="n"><span>{{ $c->lead->name }}</span>@if ($c->unread_count)<span class="wa-badge">{{ $c->unread_count }}</span>@endif</div>
                    <div class="p">{{ $c->lead->phone }} · {{ $c->last_message_at?->diffForHumans() }}</div>
                    @if ($seesAll && $c->lead->owner)<div class="p">Agen: {{ $c->lead->owner->name }}</div>@endif
                    @if ($c->lead->property)<span class="wa-prod" title="Produk yang ditanyakan">{{ $c->lead->property->name }}</span>@endif
                </button>
            @empty
                <div class="wa-empty">Belum ada percakapan. Buka chat dari kartu lead, atau tunggu pesan masuk.</div>
            @endforelse
        </aside>

        <section class="wa-chat" aria-label="Chat">
            @if ($conversation)
                @php($lead = $conversation->lead)
                {{-- Details open by default on wide screens only; the choice is kept per user and screen size. --}}
                <div class="wa-head" x-data="{
                        open: false,
                        key: 'jl.inbox.details.{{ auth()->id() }}.' + (window.matchMedia('(min-width: 1024px)').matches ? 'wide' : 'narrow'),
                        init() { let v = null; try { v = localStorage.getItem(this.key) } catch (e) {} this.open = v === null ? this.key.endsWith('wide') : v === '1' },
                        toggle() { this.open = ! this.open; try { localStorage.setItem(this.key, this.open ? '1' : '0') } catch (e) {} },
                    }">
                    <div class="wa-bar">
                        <button type="button" class="wa-back" wire:click="close" aria-label="Semua chat" title="Semua chat">
                            <x-filament::icon icon="heroicon-m-arrow-left" class="h-5 w-5" />
                        </button>
                        <button type="button" class="wa-name" x-on:click="toggle()" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="wa-details" title="Detail lead">
                            <strong>{{ $lead->name }}</strong>
                            @if ($lead->payment_proof_at && ! $lead->paid_at)<span class="wa-dot" title="Bukti transfer masuk, perlu dicek"></span>@endif
                            <x-filament::icon icon="heroicon-m-chevron-down" class="chev" />
                        </button>
                        @if ($lead->stage)<span class="wa-chip" title="Tahap: {{ $lead->stage->name }}">{{ $lead->stage->name }}</span>@endif
                        <span class="wa-chip-prod">{{ $this->productAction }}</span>
                        <span class="wa-more">
                            <x-filament-actions::group :actions="[$this->paymentReceivedAction, $this->trackingAction, $this->shippingQuoteAction, $this->openLeadAction]"
                                icon="heroicon-m-ellipsis-horizontal" label="Aksi lain" tooltip="Aksi lain" color="gray" :icon-button="true" dropdown-placement="bottom-end" />
                        </span>
                    </div>
                    <div class="wa-details" id="wa-details" x-show="open" x-cloak>
                        <div>{{ $conversation->phone }}@if ($lead->stage) · {{ $lead->stage->name }}@endif @if ($aiMode) · Pembalas: {{ $aiMode->getLabel() }}@endif</div>
                        @if ($lead->ad_title || $lead->ad_id)
                            <div>Dari iklan:
                                @if ($lead->ad_url && preg_match('#^https?://#i', $lead->ad_url))<a href="{{ $lead->ad_url }}" target="_blank" rel="noopener nofollow">{{ $lead->ad_title ?: 'ID '.$lead->ad_id }}</a>@else{{ $lead->ad_title ?: 'ID '.$lead->ad_id }}@endif
                            </div>
                        @endif
                        @if ($lead->payment_proof_at && ! $lead->paid_at)
                            <div><span class="wa-proof">Bukti transfer masuk, perlu dicek</span></div>
                        @endif
                    </div>
                </div>
                <div class="wa-msgs" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:updated.window="$el.scrollTop = $el.scrollHeight">
                    @forelse ($conversation->messages as $m)
                        <div wire:key="m-{{ $m->id }}" class="wa-m {{ $m->direction === 'out' ? 'out' : '' }} {{ $m->channel && ! $m->channel->isOfficial() ? 'gw' : '' }} {{ $m->status === \App\Enums\MessageStatus::Failed ? 'failed' : '' }}">
                            @if ($m->type === 'image')
                                @if (isset($m->media['path']))
                                    <a href="{{ route('wa.media', $m->id) }}" target="_blank" rel="noopener"><img src="{{ route('wa.media', $m->id) }}" alt="Gambar dari chat" loading="lazy" style="display:block;max-width:100%;max-height:18rem;border-radius:.5rem"></a>
                                @elseif (isset($m->media['error']))
                                    <div class="b">[Gambar tidak bisa ditampilkan{{ ($m->media['error'] ?? '') === 'too_large' ? ': ukurannya terlalu besar' : '' }}]</div>
                                @else
                                    <div class="b">[Gambar sedang dimuat…]</div>
                                @endif
                                @if (filled($m->body))<div class="b" style="margin-top:.25rem">{{ $m->body }}</div>@endif
                            @elseif ($m->type === 'document' && isset($m->media['path']))
                                <a class="b" href="{{ route('wa.media', $m->id) }}" target="_blank" rel="noopener" style="display:block;font-weight:600;text-decoration:underline">&#128206; {{ $m->media['filename'] ?? 'Dokumen' }}</a>
                                @if (filled($m->body))<div class="b" style="margin-top:.25rem">{{ $m->body }}</div>@endif
                            @else
                                <div class="b">{{ $m->body ?? '['.$m->type.']' }}</div>
                            @endif
                            <div class="meta">
                                {{ $m->sent_at?->format('d M H:i') }}
                                @if ($m->channel) · {{ $m->channel->name }}@endif
                                @if ($m->is_intro) · perkenalan @endif
                                @if ($m->from_phone) · dari WhatsApp HP @endif
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
                    <div class="ai-draft" style="margin:.5rem .7rem;padding:.6rem .75rem;border:1px solid #fecaca;background:#fef2f2;border-radius:.6rem;font-size:.85rem">
                        @if ($aiDraft->status === \App\Models\AiDraft::HANDOFF)
                            <strong>AI menyerahkan chat ini ke Anda.</strong> {{ $aiDraft->reason }}.
                            <button type="button" wire:click="discardAiDraft({{ $aiDraft->id }})" style="margin-left:.5rem;text-decoration:underline">Tutup</button>
                        @else
                            <strong>Saran balasan AI</strong>
                            @if ($aiDraft->reason) <span style="color:#6b7280">({{ $aiDraft->reason }})</span> @endif
                            @if ($usedAiDraft === $aiDraft->id)
                                <span style="color:#6b7280">sudah dimasukkan ke kolom pesan, silakan ubah lalu kirim.</span>
                            @else
                                <div class="ai-draft-body">{{ $aiDraft->body }}</div>
                            @endif
                            @foreach ($aiDraft->actions ?? [] as $action)
                                @if (($action['type'] ?? null) === 'order' && ! ($action['applied'] ?? false))
                                    <div style="margin:.25rem 0;font-size:.8rem;color:#6b7280">Bila dikirim, pesanan ikut dicatat di lead ({{ $action['result']['kurir'] ?? '' }}, ongkir {{ $action['result']['ongkir_teks'] ?? '' }}{{ isset($action['result']['total_teks']) ? ', total '.$action['result']['total_teks'] : '' }}) dan lead pindah ke Menunggu pembayaran.</div>
                                @endif
                            @endforeach
                            @foreach ($aiDraft->actions ?? [] as $action)
                                @if (($action['type'] ?? null) === 'product' && ! ($action['applied'] ?? false))
                                    <div style="margin:.25rem 0;font-size:.8rem;color:#6b7280">Bila dikirim, produk lead diganti ke {{ implode(', ', $action['names'] ?? []) }}.</div>
                                @endif
                            @endforeach
                            @if ($aiDraft->suggestedAssets()->isNotEmpty())
                                <div style="margin:.25rem 0;font-size:.8rem;color:#6b7280">Ikut terkirim: {{ $aiDraft->suggestedAssets()->pluck('title')->implode(', ') }}</div>
                            @endif
                            @if ($usedAiDraft !== $aiDraft->id)
                                <button type="button" wire:click="useAiDraft({{ $aiDraft->id }})" style="font-weight:600;color:#b91c1c">Pakai saran ini</button>
                            @endif
                            <button type="button" wire:click="discardAiDraft({{ $aiDraft->id }})" style="margin-left:.75rem;color:#6b7280">Buang</button>
                        @endif
                    </div>
                @endif
                @if ($aiPausedUntil && $conversation->lead)
                    <div class="wa-paused" title="AI dijeda di chat ini sampai {{ $aiPausedUntil->format('H:i:s') }} karena Anda sedang membalas. Setelah itu AI aktif lagi sendiri.">AI dijeda sampai {{ $aiPausedUntil->format('H:i') }} karena Anda sedang membalas</div>
                @endif
                @if ($routeLine = $decision->summary())
                    @if ($decision->needsAttention())
                        <details class="wa-route warn">
                            <summary>{{ $routeLine }}</summary>
                            <p><b>{{ $decision->title }}.</b> {{ $decision->note }}</p>
                        </details>
                    @else
                        <div class="wa-route" title="{{ $decision->title }}. {{ $decision->note }}">{{ $routeLine }}</div>
                    @endif
                @endif
                <div class="wa-compose">
                    @if ($decision->isPaid())
                        {{ $this->sendPaidTemplateAction }}
                    @elseif ($decision->canSend())
                        {{-- Enter is a new line; only the send button or Ctrl/Cmd+Enter sends. wire:ignore.self keeps the height
                             grow() set when the page polls; the text itself still follows $wire.draft. --}}
                        <div class="wa-compose-row">
                            <span class="wa-compose-btn">{{ $this->sendAttachmentAction }}</span>
                            <textarea rows="3" wire:model.live.debounce.1500ms="draft" wire:ignore.self placeholder="Tulis pesan" aria-label="Pesan" enterkeyhint="enter"
                                x-data="{ grow() { $el.style.height = 'auto'; $el.style.height = ($el.scrollHeight + $el.offsetHeight - $el.clientHeight) + 'px' } }"
                                x-init="$nextTick(() => grow())" x-effect="$wire.draft; $nextTick(() => grow())" x-on:input="grow()"
                                x-on:keydown.ctrl.enter.prevent="$wire.send()"
                                x-on:keydown.meta.enter.prevent="$wire.send()"></textarea>
                            <span class="wa-compose-btn">
                                <x-filament::icon-button :icon="\Filament\Support\Icons\Heroicon::PaperAirplane" size="lg" label="Kirim" tooltip="Kirim"
                                    wire:click="send" wire:loading.attr="disabled" wire:target="send" />
                            </span>
                        </div>
                        <p class="wa-compose-hint" x-data x-text="/Mac|iP(hone|ad)/.test(navigator.platform) ? 'Cmd+Enter untuk kirim' : 'Ctrl+Enter untuk kirim'">Ctrl+Enter untuk kirim</p>
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
