<style>
    .lf { display: grid; gap: .85rem; }
    .lf-f { display: grid; gap: .3rem; font-size: .875rem; font-weight: 600; color: #111827; }
    .lf-f input, .lf-f textarea { font: inherit; font-weight: 400; width: 100%; box-sizing: border-box; padding: .8rem .9rem; border: 1.5px solid #d1d5db; border-radius: .75rem; background: #fff; color: #111827; }
    .lf-f input:focus, .lf-f textarea:focus { outline: 3px solid color-mix(in srgb, var(--c) 30%, transparent); border-color: var(--c); }
    .lf-btn { font: inherit; font-weight: 700; padding: .95rem 1rem; border: 0; border-radius: .75rem; background: var(--c); color: #fff; cursor: pointer; min-height: 3rem; }
    .lf-btn:disabled { opacity: .6; cursor: wait; }
    .lf-trap { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
    .lf-err { margin: 0; color: #b91c1c; font-size: .875rem; }
    .lf-priv, .lf-note { margin: 0; color: #6b7280; font-size: .75rem; }
    .lf-ok { text-align: center; padding: 1rem; border-radius: .75rem; background: #f0fdf4; color: #166534; }
    .lf-ok p { margin: .25rem 0 0; }
</style>
<script>
(function () {
    function cookie(name) { var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : null; }
    function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'e' + Date.now() + Math.random().toString(16).slice(2); }
    var params = new URLSearchParams(location.search), store = {};
    try { store = JSON.parse(sessionStorage.getItem('jl_campaign') || '{}'); } catch (e) {}
    ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid','ttclid'].forEach(function (k) { if (params.get(k)) store[k] = params.get(k); });
    try { sessionStorage.setItem('jl_campaign', JSON.stringify(store)); } catch (e) {}

    document.querySelectorAll('form.lf[data-endpoint]').forEach(function (form) {
        if (!form.dataset.endpoint) return;
        var err = form.querySelector('.lf-err'), ok = form.querySelector('.lf-ok'), btn = form.querySelector('.lf-btn');
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            err.hidden = true;
            var fd = new FormData(form), body = {};
            fd.forEach(function (v, k) { if (String(v).trim() !== '') body[k] = String(v).trim(); });
            if (!body.name || !body.phone) { err.textContent = 'Isi nama dan nomor WhatsApp Anda.'; err.hidden = false; return; }
            ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(function (k) { if (store[k]) body[k] = store[k]; });
            var fbclid = store.fbclid, fbc = cookie('_fbc') || (fbclid ? 'fb.1.' + Date.now() + '.' + fbclid : null);
            if (cookie('_fbp')) body.fbp = cookie('_fbp');
            if (fbc) body.fbc = fbc;
            if (store.ttclid) body.ttclid = store.ttclid;
            if (cookie('_ttp')) body.ttp = cookie('_ttp');
            body.event_id = uuid();
            body.source_url = location.href.split('#')[0].slice(0, 500);
            if (form.dataset.page) body.page = parseInt(form.dataset.page, 10);
            btn.disabled = true;
            fetch(form.dataset.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok) { throw new Error((res.j && res.j.message) || 'Gagal mengirim. Coba lagi.'); }
                    // Same event_id as the server-side event, so each platform counts it once.
                    try { if (window.fbq) fbq('track', 'Lead', {}, { eventID: body.event_id }); } catch (e) {}
                    try { if (window.ttq) ttq.track('SubmitForm', {}, { event_id: body.event_id }); } catch (e) {}
                    try { if (window.gtag) { gtag('event', 'generate_lead'); if (form.dataset.googleLabel) gtag('event', 'conversion', { send_to: form.dataset.googleLabel }); } } catch (e) {}
                    Array.prototype.forEach.call(form.children, function (c) { if (c !== ok) c.hidden = true; });
                    ok.hidden = false;
                    form.dispatchEvent(new CustomEvent('lead:sent', { bubbles: true }));
                })
                .catch(function (e) { err.textContent = e.message || 'Gagal mengirim. Coba lagi.'; err.hidden = false; btn.disabled = false; });
        });
    });
})();
</script>
