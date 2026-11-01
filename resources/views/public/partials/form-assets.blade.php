<style>
    .lf { display: grid; gap: .9rem; min-width: 0; }
    .lf-f { display: grid; gap: .3rem; font-size: .9rem; font-weight: 600; color: #111827; min-width: 0; }
    .lf-f input, .lf-f textarea, .lf-f select { font: inherit; font-weight: 400; width: 100%; max-width: 100%; box-sizing: border-box; padding: .8rem .9rem; border: 1.5px solid #d1d5db;
        border-radius: .75rem; background: #fff; color: #111827; min-height: 3rem; }
    .lf-f input[type=file] { padding: .6rem; }
    .lf-f input:focus, .lf-f textarea:focus, .lf-f select:focus, .lf-o input:focus-visible { outline: 3px solid color-mix(in srgb, var(--c) 30%, transparent); border-color: var(--c); }
    .lf-g { border: 0; margin: 0; padding: 0; min-width: 0; display: grid; gap: .5rem; }
    .lf-g legend { font-size: .9rem; font-weight: 600; color: #111827; padding: 0; margin-bottom: .35rem; }
    .lf-opts { display: grid; gap: .45rem; }
    .lf-o { display: flex; gap: .6rem; align-items: center; padding: .7rem .85rem; border: 1.5px solid #d1d5db; border-radius: .75rem; background: #fff; color: #111827;
        cursor: pointer; font-size: .95rem; min-height: 3rem; }
    .lf-o input { width: 1.2rem; height: 1.2rem; accent-color: var(--c); flex: none; margin: 0; }
    .lf-o:has(input:checked) { border-color: var(--c); background: color-mix(in srgb, var(--c) 7%, #fff); }
    .lf-one { align-items: flex-start; }
    .lf-addr { grid-template-columns: 1fr 1fr; gap: .6rem; }
    .lf-addr legend { grid-column: 1 / -1; }
    .lf-addr .wide { grid-column: 1 / -1; }
    @media (max-width: 380px) { .lf-addr { grid-template-columns: 1fr; } }
    .lf-btn { width: 100%; }
    .lf-btn:disabled { opacity: .6; cursor: wait; }
    .lf-trap { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
    .lf-err { margin: 0; color: #b91c1c; font-size: .875rem; }
    .lf-priv, .lf-note { margin: 0; color: #6b7280; font-size: .75rem; font-weight: 400; }
    .lf [hidden] { display: none !important; }
    .lf-ok { text-align: center; padding: 1.1rem; border-radius: .75rem; background: #f0fdf4; color: #166534; display: grid; gap: .6rem; justify-items: center; }
    .lf-ok p { margin: 0; white-space: pre-line; }
    .lf [aria-invalid=true] { border-color: #dc2626; }
</style>
<script>
(function () {
    function cookie(name) { var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : null; }
    function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'e' + Date.now() + Math.random().toString(16).slice(2); }
    var CAMPAIGN = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];
    var params = new URLSearchParams(location.search), store = {};
    try { store = JSON.parse(sessionStorage.getItem('jl_campaign') || '{}'); } catch (e) {}
    CAMPAIGN.concat(['fbclid','ttclid','gclid']).forEach(function (k) { if (params.get(k)) store[k] = params.get(k); });
    if (!store.referrer && document.referrer) store.referrer = document.referrer.slice(0, 300);
    try { sessionStorage.setItem('jl_campaign', JSON.stringify(store)); } catch (e) {}
    var PHONE = /^(?:0|62)8\d{7,12}$/;

    document.querySelectorAll('form.lf[data-form]').forEach(function (form) {
        var err = form.querySelector('.lf-err'), ok = form.querySelector('.lf-ok'), btn = form.querySelector('.lf-btn');

        // Hidden fields: UTM and ad click ids, where the visitor came from, or another URL parameter.
        form.querySelectorAll('input[type=hidden][data-src]').forEach(function (h) {
            var src = h.dataset.src;
            if (src === 'static') return;
            var v = src === 'page_url' ? location.href.split('#')[0] : src === 'query' ? params.get(h.dataset.param || '') : store[src];
            if (v) h.value = String(v).slice(0, 300);
        });

        function fail(msg, el) {
            err.textContent = msg; err.hidden = false;
            if (el) { el.setAttribute('aria-invalid', 'true'); try { el.focus({ preventScroll: false }); } catch (e) {} }
            btn.disabled = false;
        }

        function check() {
            form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
            var fields = form.querySelectorAll('input[required], select[required], textarea[required]');
            for (var i = 0; i < fields.length; i++) {
                var el = fields[i];
                if (el.type === 'radio') { if (!form.querySelector('input[name="' + el.name.replace(/"/g, '\\"') + '"]:checked')) return [el, 'Pilih salah satu: ' + el.closest('fieldset').querySelector('legend').textContent.replace(' *', '') + '.']; continue; }
                if (el.type === 'checkbox' ? !el.checked : String(el.value).trim() === '') {
                    var label = (el.closest('label') && el.closest('label').querySelector('span')) ? el.closest('label').querySelector('span').textContent.replace(' *', '') : 'kolom wajib';
                    return [el, 'Lengkapi ' + label.toLowerCase() + '.'];
                }
            }
            var groups = form.querySelectorAll('fieldset[data-required]');
            for (var g = 0; g < groups.length; g++) {
                if (groups[g].querySelector('input[type=checkbox]') && !groups[g].querySelector('input:checked')) return [groups[g].querySelector('input'), 'Pilih minimal satu: ' + groups[g].querySelector('legend').textContent.replace(' *', '') + '.'];
            }
            var phones = form.querySelectorAll('input[data-phone]');
            for (var p = 0; p < phones.length; p++) {
                var digits = phones[p].value.replace(/[\s().+-]/g, '');
                if (digits && !PHONE.test(digits)) return [phones[p], 'Nomor WhatsApp harus diawali 08 atau 62, contoh 081234567890.'];
            }
            var files = form.querySelectorAll('input[type=file]');
            for (var f = 0; f < files.length; f++) { if (files[f].files[0] && files[f].files[0].size > 5 * 1024 * 1024) return [files[f], 'Foto terlalu besar, maksimal 5 MB.']; }
            return null;
        }

        function go(action) {
            var msg = form.querySelector('.lf-ok-msg'), link = form.querySelector('.lf-go');
            Array.prototype.forEach.call(form.children, function (c) { if (c !== ok) c.hidden = true; });
            msg.textContent = action.message || 'Terima kasih!';
            ok.hidden = false;
            form.dispatchEvent(new CustomEvent('lead:sent', { bubbles: true, detail: action }));
            if ((action.type === 'whatsapp' || action.type === 'redirect') && action.url) {
                link.href = action.url; link.hidden = false;
                link.textContent = action.type === 'whatsapp' ? 'Lanjut ke WhatsApp' : 'Lanjutkan';
                if (action.new_tab) { link.target = '_blank'; window.open(action.url, '_blank', 'noopener'); return; }
                // A short pause so the pixel requests leave before the page changes.
                setTimeout(function () { try { window.top.location.href = action.url; } catch (e) { location.href = action.url; } }, 450);
            }
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            err.hidden = true;
            btn.disabled = true;
            var bad = check();
            if (bad) { fail(bad[1], bad[0]); return; }
            var fd = new FormData(form);
            CAMPAIGN.forEach(function (k) { if (store[k]) fd.set(k, store[k]); });
            var fbc = cookie('_fbc') || (store.fbclid ? 'fb.1.' + Date.now() + '.' + store.fbclid : null);
            if (cookie('_fbp')) fd.set('fbp', cookie('_fbp'));
            if (fbc) fd.set('fbc', fbc);
            if (store.ttclid) fd.set('ttclid', store.ttclid);
            if (cookie('_ttp')) fd.set('ttp', cookie('_ttp'));
            var eventId = uuid();
            fd.set('event_id', eventId);
            fd.set('source_url', location.href.split('#')[0].slice(0, 500));
            if (form.dataset.page) fd.set('page', form.dataset.page);
            fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
                .then(function (res) {
                    if (!res.ok) {
                        var first = res.j && res.j.errors ? Object.values(res.j.errors)[0][0] : null;
                        throw new Error(first || (res.status === 429 ? 'Terlalu sering. Tunggu sebentar lalu coba lagi.' : 'Gagal mengirim. Coba lagi.'));
                    }
                    // Same event_id as the server-side event, so each platform counts it once.
                    if (window.jlPx && form.dataset.pxSubmit) { try { jlPx(JSON.parse(form.dataset.pxSubmit), eventId); } catch (e) {} }
                    go((res.j && res.j.action) || { type: 'message' });
                })
                .catch(function (e) { fail(e.message || 'Gagal mengirim. Coba lagi.'); });
        });
    });
})();
</script>
