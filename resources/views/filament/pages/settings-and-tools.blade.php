{{-- "Pengaturan & Alat" (App\Filament\Pages\SettingsAndTools): quick links, a search box, and grouped cards
     (App\Filament\Support\SettingsCatalog). The search runs in the browser over the same words as
     SettingsCatalog::matches(): every word typed must appear in the name, description, or keywords. --}}
<x-filament-panels::page>
    <style>
        .st { display: grid; gap: 1.25rem; }
        .st-top { display: grid; gap: .75rem; }
        .st-quick { display: flex; flex-wrap: wrap; gap: .5rem; }
        .st-quick a { display: inline-flex; align-items: center; gap: .45rem; min-height: 2.75rem; padding: .4rem .9rem; border-radius: 9999px;
            background: var(--primary-50); color: var(--primary-700); font-weight: 600; font-size: .9rem; border: 1px solid var(--primary-200); }
        .st-quick a:hover { background: var(--primary-100); }
        .st-search { width: 100%; max-width: 32rem; box-sizing: border-box; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .7rem;
            min-height: 2.75rem; font-size: 1rem; background: white; }
        .st-group h2 { font-weight: 700; font-size: 1rem; margin: 0 0 .5rem; color: var(--gray-800); }
        .st-cards { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 640px) { .st-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .st-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .st-card { display: grid; grid-template-columns: 2.5rem minmax(0, 1fr); gap: .75rem; align-items: start; min-height: 2.75rem;
            background: white; border: 1px solid var(--gray-200); border-radius: .75rem; padding: .85rem 1rem; color: inherit; }
        .st-card:hover, .st-card:focus-visible { border-color: var(--primary-400); }
        .st-icon { display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: .6rem; background: var(--primary-50); color: var(--primary-600); }
        .st-name { display: flex; align-items: center; gap: .5rem; font-weight: 600; color: var(--gray-900); }
        .st-badge { margin-left: auto; flex: none; min-width: 1.5rem; padding: 0 .45rem; border-radius: 9999px; background: var(--primary-600); color: white;
            font-size: .8rem; font-weight: 700; text-align: center; line-height: 1.5rem; }
        .st-desc { display: block; margin-top: .15rem; font-size: .875rem; color: var(--gray-600); }
        .st-empty { color: var(--gray-600); }
    </style>

    <div class="st" x-data="{
        q: '',
        words() { return this.q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean) },
        hit(text) { const t = ' ' + text; return this.words().every((w) => t.includes(w)) },
        any(el) { return [...el.querySelectorAll('[data-search]')].some((c) => this.hit(c.dataset.search)) },
    }">
        <div class="st-top">
            @if ($quick)
                <nav class="st-quick" aria-label="Akses cepat">
                    @foreach ($quick as $card)
                        <a href="{{ $card['url'] }}" wire:navigate>
                            <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                            <span>{{ $card['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif
            <input type="search" class="st-search" x-model="q" placeholder="Cari pengaturan, mis. ongkir, rekening, pixel…" aria-label="Cari pengaturan dan alat">
        </div>

        <div class="st" x-ref="all">
            @foreach ($groups as $key => $group)
                <section class="st-group" x-show="any($el)" aria-labelledby="st-{{ $key }}">
                    <h2 id="st-{{ $key }}">{{ $group['label'] }}</h2>
                    <div class="st-cards">
                        @foreach ($group['cards'] as $card)
                            <a href="{{ $card['url'] }}" wire:navigate class="st-card" data-search="{{ $card['search'] }}" x-show="hit($el.dataset.search)">
                                <span class="st-icon"><x-filament::icon :icon="$card['icon']" class="h-6 w-6" /></span>
                                <span>
                                    <span class="st-name">
                                        <span>{{ $card['label'] }}</span>
                                        @if ($card['badge'])<span class="st-badge" aria-label="{{ $card['badge'] }} perlu ditinjau">{{ $card['badge'] }}</span>@endif
                                    </span>
                                    <span class="st-desc">{{ $card['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <p class="st-empty" x-show="! any($refs.all)" x-cloak>Tidak ada pengaturan yang cocok dengan "<span x-text="q"></span>". Coba kata lain, misalnya ongkir, rekening, atau pixel.</p>
        </div>
    </div>
</x-filament-panels::page>
