<style>
    :root { --jl-red: #dc2626; --jl-red-dark: #b91c1c; --jl-red-deep: #991b1b; --jl-ink: #111827; }
    body, .fi-body { background: #fff; color: var(--jl-ink); }
    .fi-main-ctn, .fi-main { background: #fff; }

    /* Sidebar: solid red with white text, white pill for the open page */
    .fi-sidebar { background: linear-gradient(180deg, var(--jl-red) 0%, var(--jl-red-dark) 100%); border: 0; }
    .fi-sidebar-header { background: transparent; box-shadow: none; border: 0; }
    .fi-sidebar-nav { background: transparent; }
    .fi-sidebar-item-button, .fi-sidebar-group-button { border-radius: .6rem; }
    .fi-sidebar-item-label, .fi-sidebar-item-icon, .fi-sidebar-group-label, .fi-sidebar-group-collapse-button,
    .fi-sidebar-item-grouped-border { color: rgba(255,255,255,.88); }
    .fi-sidebar-group-label { text-transform: uppercase; letter-spacing: .06em; font-size: .75rem; color: rgba(255,255,255,.62); }
    .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button:hover { background: rgba(255,255,255,.14); }
    .fi-sidebar-item.fi-active .fi-sidebar-item-button { background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.15); }
    .fi-sidebar-item.fi-active .fi-sidebar-item-label, .fi-sidebar-item.fi-active .fi-sidebar-item-icon { color: var(--jl-red-dark); font-weight: 700; }
    .fi-sidebar .fi-badge { background: #fff; color: var(--jl-red-dark); --tw-ring-color: transparent; }
    .fi-sidebar-footer, .fi-sidebar .fi-tenant-menu-trigger { color: #fff; }
    .fi-sidebar .fi-tenant-menu-trigger span, .fi-sidebar .fi-tenant-menu-trigger svg { color: #fff; }
    .fi-sidebar .fi-icon-btn { color: #fff; }

    /* Top bar: white with a thin red line */
    .fi-topbar { background: #fff; border-bottom: 2px solid var(--jl-red); box-shadow: none; }
    .fi-topbar nav { background: #fff; }

    /* Headings, cards, tables */
    .fi-header-heading { font-weight: 800; letter-spacing: -.01em; color: var(--jl-ink); }
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-wi-chart { border-radius: .85rem; box-shadow: 0 1px 2px rgba(17,24,39,.05); }
    .fi-ta-header-cell { background: #fef2f2; }
    .fi-btn.fi-color-primary:not(.fi-outlined) { background: var(--jl-red); }
    .fi-btn.fi-color-primary:not(.fi-outlined):hover { background: var(--jl-red-dark); }
    /* Period picker (App\Support\Period::fields): a dropdown, full width on phones, about 220px from tablets up, with its dates beside it */
    .jl-period-row { gap: .25rem .75rem; }
    .jl-period-select { width: 100%; }
    @media (min-width: 768px) { .jl-period-select { width: 14rem; } }
    .jl-period-caption { line-height: 1.25rem; }
    @media (min-width: 768px) { .jl-period-caption { padding-bottom: .55rem; } }
    /* Toggle buttons (interest): only the chosen one is filled */
    .fi-fo-toggle-buttons-input:not(:checked) + .fi-btn.fi-color-primary,
    .fi-fo-toggle-buttons-input:not(:checked) + .fi-btn.fi-color-primary:hover { background: #fff; color: var(--gray-700); box-shadow: inset 0 0 0 1px var(--gray-300); }
    .fi-fo-toggle-buttons-input:not(:checked) + .fi-btn.fi-color-primary:hover { background: var(--gray-50); }
    .dark .fi-fo-toggle-buttons-input:not(:checked) + .fi-btn.fi-color-primary { background: var(--gray-900); color: var(--gray-200); box-shadow: inset 0 0 0 1px var(--gray-700); }

</style>
{{-- Phone and tablet layout for every panel page: public/css/jitu/responsive.css (see docs/ui-audit.md) --}}
<link rel="stylesheet" href="{{ asset('css/jitu/responsive.css') }}?v={{ @filemtime(public_path('css/jitu/responsive.css')) }}">
{{-- --jl-vvh: the height of the part of the screen that is visible, above the on-screen keyboard (iOS Safari keeps
     100dvh at full height while the keyboard is up). A field that is typed in stays in sight when the keyboard opens. --}}
<script>
    (function () {
        var vv = window.visualViewport, root = document.documentElement;
        if (!vv) return;
        function update() {
            root.style.setProperty('--jl-vvh', Math.round(vv.height) + 'px');
            var el = document.activeElement;
            if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && window.matchMedia('(max-width: 1023px)').matches) {
                el.scrollIntoView({ block: 'nearest' });
            }
        }
        vv.addEventListener('resize', update);
        update();
    })();
    // On touch screens a tap also opens a button's tooltip, which then stays on screen over what the button opened
    // (Inbox "Aksi lain" over the avatar). The button's label stays available to screen readers (aria-label).
    document.addEventListener('pointerdown', function (e) {
        if (e.pointerType !== 'touch') return;
        var el = e.target.closest && e.target.closest('button, a, [role="button"]');
        if (el && el._tippy) { el._tippy.setProps({ touch: false }); el._tippy.hide(); }
    }, true);
</script>
