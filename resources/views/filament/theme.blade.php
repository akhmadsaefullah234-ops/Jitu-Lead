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
    .fi-sidebar-group-label { text-transform: uppercase; letter-spacing: .06em; font-size: .6875rem; color: rgba(255,255,255,.62); }
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

    /* Small screens: comfortable touch targets and no sideways page scroll */
    html, body { max-width: 100%; overflow-x: hidden; }
    .fi-main { padding-inline: 1rem; }
    @media (max-width: 640px) {
        .fi-header-heading { font-size: 1.35rem; }
        .fi-header { flex-direction: column; align-items: stretch; gap: .75rem; }
        .fi-page-header-actions, .fi-header .fi-ac { width: 100%; }
        .fi-sidebar-item-button { min-height: 2.75rem; }
        .fi-ta-content { overflow-x: auto; }
        .fi-modal-window { margin-inline: .5rem; }
    }
    @media (min-width: 1024px) { .fi-main { padding-inline: 2rem; } }

    /* ===== Mobile: feels like an app (tab bar, floating add button, sheets, big touch targets) ===== */
    .jl-tabbar, .jl-fab { display: none; }
    @media (max-width: 1023px) {
        body { padding-bottom: calc(4.4rem + env(safe-area-inset-bottom)); background: #f9fafb; }
        .fi-main-ctn, .fi-main { background: #f9fafb; }
        .fi-main { padding-inline: .75rem; padding-top: .5rem; }
        .fi-topbar { position: sticky; top: 0; z-index: 20; padding-top: env(safe-area-inset-top); }

        .jl-tabbar { display: flex; position: fixed; inset-inline: 0; bottom: 0; z-index: 40; background: rgba(255,255,255,.96);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); border-top: 1px solid #fecaca;
            padding: .3rem .25rem calc(.3rem + env(safe-area-inset-bottom)); box-shadow: 0 -6px 18px rgba(17,24,39,.07); }
        .jl-tab { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .1rem; min-height: 3.4rem; justify-content: center;
            font-size: .68rem; font-weight: 600; color: #6b7280; border-radius: .8rem; background: none; border: 0; text-decoration: none; -webkit-tap-highlight-color: transparent; }
        .jl-tab:active { background: #fef2f2; }
        .jl-tab.is-active { color: var(--jl-red); }
        .jl-tab-icon { position: relative; display: inline-flex; padding: .1rem .9rem; border-radius: 9999px; }
        .jl-tab.is-active .jl-tab-icon { background: #fee2e2; }
        .jl-tab-badge { position: absolute; top: -.15rem; right: .35rem; min-width: 1.1rem; height: 1.1rem; padding: 0 .28rem; border-radius: 9999px;
            background: var(--jl-red); color: #fff; font-size: .65rem; line-height: 1.1rem; text-align: center; font-weight: 700; }
        .jl-fab { display: flex; align-items: center; justify-content: center; position: fixed; right: 1rem; z-index: 39;
            bottom: calc(5rem + env(safe-area-inset-bottom)); width: 3.5rem; height: 3.5rem; border-radius: 9999px; background: var(--jl-red); color: #fff;
            box-shadow: 0 8px 20px rgba(220,38,38,.4); -webkit-tap-highlight-color: transparent; }
        .jl-fab:active { transform: scale(.94); }
        body:has(.wa--open) .jl-tabbar, body:has(.wa--open) .jl-fab { display: none; }
        body:has(.wa--open) { padding-bottom: 0; }

        /* Help bubble sits bottom-left above the tab bar so it never covers the tabs or the add button */
        .jl-support { left: 1rem !important; right: auto !important; bottom: calc(5rem + env(safe-area-inset-bottom)) !important; }
        body:has(.wa--open) .jl-support { bottom: 1rem !important; }

        /* Cards instead of boxed tables */
        .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-wi-chart { border-radius: 1rem; border: 0; box-shadow: 0 1px 3px rgba(17,24,39,.08); }
        .fi-ta-row { padding-block: .15rem; }
        .fi-ta-record { border-radius: .9rem; }
        .fi-header-heading { font-size: 1.3rem; }
        .fi-btn, .fi-icon-btn { min-height: 2.75rem; }
        .fi-ac { gap: .5rem; }

        /* 16px fields stop iOS from zooming in when a field is tapped */
        .fi-input, .fi-select-input, input, select, textarea { font-size: 16px !important; }
        .fi-input-wrp { border-radius: .8rem; }

        /* Dialogs rise from the bottom like a sheet */
        .fi-modal-window-ctn { align-items: flex-end !important; padding: 0 !important; }
        .fi-modal-window { width: 100% !important; max-width: 100% !important; margin: 0 !important; border-radius: 1.25rem 1.25rem 0 0 !important;
            max-height: 92vh; padding-bottom: env(safe-area-inset-bottom); }
        .fi-modal-footer-actions { flex-direction: column-reverse; align-items: stretch; }
        .fi-modal-footer-actions > * { width: 100%; }

        /* Sidebar drawer */
        .fi-sidebar { padding-top: env(safe-area-inset-top); }
        .fi-sidebar-item-button { min-height: 3rem; font-size: .95rem; }
    }
    @media (max-width: 1023px) and (display-mode: standalone) { .fi-topbar { box-shadow: 0 1px 0 #fecaca; } }
    @media (prefers-reduced-motion: reduce) { * { transition-duration: .01ms !important; animation-duration: .01ms !important; } }
</style>
