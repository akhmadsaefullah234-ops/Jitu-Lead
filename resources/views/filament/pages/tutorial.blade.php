{{-- The in-app tutorial (App\Filament\Pages\Tutorial): the list of chapters, or one chapter with its steps. --}}
<x-filament-panels::page>
    <style>
        .tu { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .tu { grid-template-columns: 16rem minmax(0, 1fr); } }
        .tu-toc { align-self: start; background: white; border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem; font-size: .85rem; }
        @media (min-width: 1024px) { .tu-toc { position: sticky; top: 5rem; max-height: calc(100vh - 6rem); overflow-y: auto; } }
        .tu-toc summary { font-weight: 600; cursor: pointer; list-style: none; }
        .tu-toc summary::-webkit-details-marker { display: none; }
        .tu-toc h4 { margin: .75rem 0 .25rem; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--gray-500); }
        .tu-toc a { display: flex; gap: .35rem; align-items: center; padding: .2rem .4rem; border-radius: .4rem; color: var(--gray-700); }
        .tu-toc a[aria-current="page"] { background: var(--primary-50); color: var(--primary-700); font-weight: 600; }
        .tu-search { width: 100%; box-sizing: border-box; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .7rem; font-size: .875rem; background: white; }
        .tu-admin { flex: none; margin-left: auto; font-size: .62rem; font-weight: 700; color: #92400e; background: #fef3c7; border-radius: 9999px; padding: 0 .4rem; }
        .tu-main { min-width: 0; display: grid; gap: 1rem; }
        .tu-group h3 { font-weight: 700; margin: 0 0 .5rem; }
        .tu-cards { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); }
        .tu-card { display: block; background: white; border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem .9rem; }
        .tu-card:hover { border-color: var(--primary-400); }
        .tu-card b { display: flex; gap: .35rem; align-items: center; }
        .tu-card span.m { display: block; font-size: .75rem; color: var(--gray-500); margin-top: .15rem; }
        .tu-menu { font-size: .85rem; color: var(--gray-600); }
        .tu-menu b { color: var(--gray-800); }
        .tu-intro { color: var(--gray-700); max-width: 48rem; }
        .tu-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; counter-reset: tu; }
        .tu-step { display: grid; grid-template-columns: 2rem minmax(0, 1fr); gap: .6rem; counter-increment: tu; }
        .tu-step::before { content: counter(tu); display: grid; place-items: center; width: 1.8rem; height: 1.8rem; border-radius: 9999px; background: var(--primary-600); color: white; font-weight: 700; font-size: .85rem; }
        .tu-step .t { padding-top: .2rem; max-width: 48rem; }
        .tu-step .t p, .tu-note p { margin: 0 0 .35rem; }
        .tu-step .t ul, .tu-note ul { margin: .2rem 0 .35rem 1.1rem; list-style: disc; }
        .tu-shots { display: flex; gap: .75rem; align-items: flex-start; margin-top: .5rem; flex-wrap: wrap; }
        .tu-shots a { display: block; border: 1px solid var(--gray-200); border-radius: .5rem; overflow: hidden; background: var(--gray-50); max-width: 100%; }
        .tu-shots img { display: block; max-width: 100%; height: auto; }
        .tu-shots .wide { flex: 1 1 32rem; max-width: 56rem; }
        .tu-shots .hp { flex: 0 0 11rem; }
        .tu-note { border-radius: .6rem; padding: .65rem .85rem; font-size: .875rem; border: 1px solid; max-width: 48rem; }
        .tu-note strong.h { display: block; margin-bottom: .2rem; }
        .tu-note.tips { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
        .tu-note.perhatian { background: #fffbeb; border-color: #fde68a; color: #78350f; }
        .tu-note.penting { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
        .tu-nav { display: flex; justify-content: space-between; gap: .75rem; flex-wrap: wrap; border-top: 1px solid var(--gray-200); padding-top: .9rem; }
        .tu-nav a { display: block; max-width: 48%; font-size: .85rem; }
        .tu-nav a small { display: block; color: var(--gray-500); }
        .tu-nav a b { color: var(--primary-700); }
        .tu-empty { color: var(--gray-600); }
        .dark .tu-toc, .dark .tu-card, .dark .tu-search { background: var(--gray-900); border-color: var(--gray-700); }
        .dark .tu-toc a { color: var(--gray-300); }
        .dark .tu-toc a[aria-current="page"] { background: var(--gray-800); color: var(--primary-300); }
        .dark .tu-intro, .dark .tu-menu { color: var(--gray-300); } .dark .tu-menu b { color: white; }
        .dark .tu-shots a { border-color: var(--gray-700); background: var(--gray-800); }
        .dark .tu-note.tips { background: #172554; border-color: #1e40af; color: #dbeafe; }
        .dark .tu-note.perhatian { background: #451a03; border-color: #92400e; color: #fef3c7; }
        .dark .tu-note.penting { background: #450a0a; border-color: #991b1b; color: #fee2e2; }
        .dark .tu-nav { border-color: var(--gray-700); } .dark .tu-nav a b { color: var(--primary-300); }
    </style>

    <div class="tu">
        <nav class="tu-toc" aria-label="Daftar isi tutorial">
            <input type="search" class="tu-search" placeholder="Cari tutorial…" aria-label="Cari tutorial" wire:model.live.debounce.300ms="search">
            <details x-data x-init="$el.open = window.matchMedia('(min-width: 1024px)').matches" style="margin-top:.6rem">
                <summary>Daftar isi</summary>
                @foreach ($groups as $key => $label)
                    @if ($all->has($key))
                        <h4>{{ $label }}</h4>
                        @foreach ($all[$key] as $c)
                            <a href="{{ \App\Filament\Pages\Tutorial::url($c) }}" wire:navigate @if ($current?->slug === $c->slug) aria-current="page" @endif>
                                <span>{{ $c->title }}</span>@if ($c->admin)<span class="tu-admin">Admin</span>@elseif ($c->leader)<span class="tu-admin">Admin &amp; leader</span>@endif
                            </a>
                        @endforeach
                    @endif
                @endforeach
            </details>
        </nav>

        <div class="tu-main">
            @if ($current && trim($search) === '')
                <article class="tu-chapter" aria-labelledby="tu-title">
                    <div style="display:grid;gap:.6rem">
                        @if ($current->admin || $current->leader)<div><span class="tu-admin" style="margin:0">{{ $current->admin ? 'Hanya admin' : 'Admin dan team leader' }}</span></div>@endif
                        @if ($current->menu !== '')<div class="tu-menu">Menu: <b>{{ $current->menu }}</b></div>@endif
                        @if ($current->intro !== '')<div class="tu-intro">{!! \App\Tutorials\Chapter::html($current->intro, $industry) !!}</div>@endif
                    </div>

                    <ol class="tu-steps" style="margin-top:1.1rem">
                        @foreach ($current->steps as $i => $step)
                            <li class="tu-step" id="langkah-{{ $i + 1 }}">
                                <div class="t">
                                    {!! \App\Tutorials\Chapter::html($step['text'], $industry) !!}
                                    @if ($step['image'])
                                        <div class="tu-shots">
                                            <a class="wide" href="{{ asset($step['image']) }}" target="_blank" rel="noopener">
                                                <img src="{{ asset($step['image']) }}" alt="{{ $step['alt'] }}" loading="lazy" decoding="async" width="1280" height="800">
                                            </a>
                                            @if ($step['mobile'])
                                                <a class="hp" href="{{ asset($step['mobile']) }}" target="_blank" rel="noopener">
                                                    <img src="{{ asset($step['mobile']) }}" alt="{{ $step['alt'] }} (tampilan HP)" loading="lazy" decoding="async" width="390" height="844">
                                                </a>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @foreach (['tips' => 'Tips', 'perhatian' => 'Perhatian', 'penting' => 'Penting'] as $kind => $label)
                        @foreach ($current->notes[$kind] ?? [] as $note)
                            <div class="tu-note {{ $kind }}" style="margin-top:.8rem" role="note"><strong class="h">{{ $label }}</strong>{!! \App\Tutorials\Chapter::html($note, $industry) !!}</div>
                        @endforeach
                    @endforeach

                    <nav class="tu-nav" aria-label="Bab lain" style="margin-top:1.25rem">
                        @if ($previous)
                            <a href="{{ \App\Filament\Pages\Tutorial::url($previous) }}" wire:navigate rel="prev"><small>← Bab sebelumnya</small><b>{{ $previous->title }}</b></a>
                        @else
                            <span></span>
                        @endif
                        @if ($next)
                            <a href="{{ \App\Filament\Pages\Tutorial::url($next) }}" wire:navigate rel="next" style="text-align:right"><small>Bab berikutnya →</small><b>{{ $next->title }}</b></a>
                        @endif
                    </nav>
                </article>
            @else
                @if ($missing)
                    <div class="tu-note perhatian" role="alert">Bab ini tidak ada atau tidak tersedia untuk akun Anda. Pilih bab lain di bawah.</div>
                @endif
                @if (trim($search) !== '' && $chapters->isEmpty())
                    <p class="tu-empty">Tidak ada bab yang cocok dengan "{{ $search }}".</p>
                @endif
                @foreach ($groups as $key => $label)
                    @if ($chapters->has($key))
                        <section class="tu-group" aria-label="{{ $label }}">
                            <h3>{{ $label }}</h3>
                            <div class="tu-cards">
                                @foreach ($chapters[$key] as $c)
                                    <a class="tu-card" href="{{ \App\Filament\Pages\Tutorial::url($c) }}" wire:navigate>
                                        <b>{{ $c->title }}@if ($c->admin)<span class="tu-admin">Admin</span>@elseif ($c->leader)<span class="tu-admin">Admin &amp; leader</span>@endif</b>
                                        @if ($c->menu !== '')<span class="m">Menu: {{ $c->menu }}</span>@endif
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
