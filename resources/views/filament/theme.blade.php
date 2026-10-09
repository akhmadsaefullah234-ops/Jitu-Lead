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
    @media (prefers-reduced-motion: reduce) { * { transition-duration: .01ms !important; animation-duration: .01ms !important; } }
</style>
