@once
<style>
    .jl-dash-empty { font-size: .875rem; color: var(--gray-500); }
    .jl-dash-scroll { overflow-x: auto; margin: 0 -.25rem; }
    .jl-dash-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .jl-dash-table th { text-align: left; font-weight: 600; color: var(--gray-500); padding: .4rem .5rem; border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
    .jl-dash-table td { padding: .5rem; border-bottom: 1px solid var(--gray-100); vertical-align: top; }
    .jl-dash-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .jl-dash-table tr:last-child td { border-bottom: 0; }
    .jl-dash-link { color: var(--primary-600); font-weight: 600; }
    .jl-dash-list { display: grid; grid-template-columns: 1fr auto; gap: .45rem 1rem; font-size: .875rem; }
    .jl-dash-list dt { color: var(--gray-600); }
    .jl-dash-list dd { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
    .jl-dash-group { grid-column: 1 / -1; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--gray-500); margin-top: .35rem; }
    .jl-funnel { display: flex; flex-direction: column; gap: .5rem; }
    .jl-funnel .row { display: grid; grid-template-columns: minmax(7rem, 34%) 1fr auto; gap: .6rem; align-items: center; font-size: .8125rem; }
    .jl-funnel .bar { height: .7rem; border-radius: 9999px; background: var(--gray-100); overflow: hidden; }
    .jl-funnel .bar span { display: block; height: 100%; background: var(--primary-500); border-radius: 9999px; }
    .jl-funnel .bar span.won { background: var(--success-500); }
    .jl-funnel .n { font-weight: 600; font-variant-numeric: tabular-nums; text-align: right; min-width: 4.5rem; }
    .jl-funnel .n small { font-size: .75rem; color: var(--gray-500); font-weight: 500; }
    .jl-status { display: flex; flex-wrap: wrap; gap: .5rem; }
    .jl-status .pill { display: inline-flex; align-items: center; min-height: 2.75rem; gap: .4rem; border: 1px solid var(--gray-200); border-radius: .6rem; padding: .45rem .7rem; font-size: .8125rem; background: white; }
    .jl-status .dot { width: .55rem; height: .55rem; border-radius: 9999px; background: var(--gray-400); }
    .jl-status .dot.ok { background: var(--success-500); }
    .jl-status .dot.bad { background: var(--danger-500); }
    .jl-status a.pill { color: inherit; }
    .jl-status b { font-variant-numeric: tabular-nums; }
    .dark .jl-dash-table th { border-color: var(--gray-700); }
    .dark .jl-dash-table td { border-color: var(--gray-800); }
    .dark .jl-funnel .bar { background: var(--gray-800); }
    .dark .jl-status .pill { background: var(--gray-900); border-color: var(--gray-700); }
    .dark .jl-dash-link { color: var(--primary-400); }
    @media (max-width: 1023px) { .jl-dash-link { display: inline-flex; align-items: center; min-height: 2.75rem; } }
    .dark .jl-dash-list dt { color: var(--gray-400); }
    @media (max-width: 640px) {
        .jl-funnel .row { grid-template-columns: 1fr auto; }
        .jl-funnel .row .bar { grid-column: 1 / -1; order: 3; }
        /* Each table row becomes a small card: the name on top, labelled figures three across under it */
        .jl-dash-table thead { display: none; }
        .jl-dash-table tr { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .35rem .5rem; padding: .65rem 0; border-bottom: 1px solid var(--gray-100); }
        .jl-dash-table tr:last-child { border-bottom: 0; }
        .jl-dash-table td { padding: 0; border: 0; }
        .jl-dash-table td:first-child { grid-column: 1 / -1; font-weight: 600; }
        .jl-dash-table td.num { text-align: left; }
        .jl-dash-table td.num::before { content: attr(data-label); display: block; font-size: .75rem; color: var(--gray-500); font-weight: 400; }
        .dark .jl-dash-table tr { border-color: var(--gray-800); }
    }
</style>
@endonce
