{{--
    Fires the pixel events of buttons (data-px), form submits (via jlPx) and the page itself ($pagePx). Only on
    pages whose agency has pixel ids; the pixel libraries themselves load async (public.partials.tracking).
--}}
@if ($tracking ?? null)
<script>
(function () {
    // cfg: {meta: [name, custom], tiktok: name, google: name, send_to, value, currency, content_name}
    window.jlPx = function (cfg, eventId) {
        if (!cfg) return;
        var p = {};
        if (cfg.value !== undefined) { p.value = cfg.value; p.currency = cfg.currency || 'IDR'; }
        if (cfg.content_name) p.content_name = cfg.content_name;
        var id = eventId || ((window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'e' + Date.now() + Math.random().toString(16).slice(2));
        try { if (cfg.meta && window.fbq) fbq(cfg.meta[1] ? 'trackCustom' : 'track', cfg.meta[0], p, { eventID: id }); } catch (e) {}
        try { if (cfg.tiktok && window.ttq) ttq.track(cfg.tiktok, p, { event_id: id }); } catch (e) {}
        try {
            if (window.gtag) {
                if (cfg.google) gtag('event', cfg.google, Object.assign({ transport_type: 'beacon' }, p));
                if (cfg.send_to) gtag('event', 'conversion', Object.assign({ send_to: cfg.send_to, transport_type: 'beacon' }, p));
            }
        } catch (e) {}
    };

    // Button clicks: fire first, then follow the link after a short pause so the requests are not cut off.
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('a[data-px]');
        if (!a) return;
        var cfg; try { cfg = JSON.parse(a.getAttribute('data-px')); } catch (e) { return; }
        jlPx(cfg);
        var href = a.getAttribute('href') || '';
        if (a.target === '_blank' || href.charAt(0) === '#' || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
        ev.preventDefault();
        setTimeout(function () { location.href = a.href; }, 300);
    });

    @if ($pagePx ?? null)
    try { jlPx(JSON.parse(@json($pagePx))); } catch (e) {}
    @endif
})();
</script>
@endif
