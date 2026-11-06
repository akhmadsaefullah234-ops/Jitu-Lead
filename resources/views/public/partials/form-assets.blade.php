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
    @media (max-width: 560px) { .lf-rg { grid-template-columns: 1fr; } }
    .rg { position: relative; display: block; }
    .rg-q:disabled { background: #f3f4f6; color: #9ca3af; }
    .rg-l { position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + .25rem); max-height: 15rem; overflow-y: auto; overscroll-behavior: contain; background: #fff;
        border: 1.5px solid #d1d5db; border-radius: .75rem; box-shadow: 0 10px 30px rgba(17,24,39,.15); padding: .25rem; display: grid; }
    .rg-l[hidden] { display: none; }
    .rg-o { display: block; padding: .7rem .75rem; border-radius: .5rem; font-weight: 400; color: #111827; cursor: pointer; text-align: left; min-height: 2.75rem; box-sizing: border-box; }
    .rg-o[aria-selected=true], .rg-o:hover { background: color-mix(in srgb, var(--c) 10%, #fff); }
    .rg-none { padding: .7rem .75rem; color: #6b7280; font-weight: 400; }
    .lf-btn { width: 100%; }
    .lf-btn:disabled { opacity: .6; cursor: wait; }
    .lf-trap { display: none !important; }
    .lf-err { margin: 0; color: #b91c1c; font-size: .875rem; }
    .lf-priv, .lf-note { margin: 0; color: #6b7280; font-size: .75rem; font-weight: 400; }
    .lf [hidden] { display: none !important; }
    .lf-ok { text-align: center; padding: 1.1rem; border-radius: .75rem; background: #f0fdf4; color: #166534; display: grid; gap: .6rem; justify-items: center; }
    .lf-ok p { margin: 0; white-space: pre-line; }
    .lf [aria-invalid=true] { border-color: #dc2626; }
    .lf-if { font-weight: inherit; }
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
    // An address without a real RT/RW: empty, 0, 000 or 000/000 (App\Leads\AddressCheck::hasRtRw).
    function noRt(box) { var rt = box.querySelector('input[name$="[rt_rw]"]'); return !rt || !/[1-9]/.test(rt.value); }

    document.querySelectorAll('form.lf[data-form]').forEach(function (form) {
        var err = form.querySelector('.lf-err'), ok = form.querySelector('.lf-ok'), btn = form.querySelector('.lf-btn');

        // "landmark_if_no_rt": house number / landmark marked as required while the RT/RW is empty or 0.
        form.querySelectorAll('fieldset.lf-addr').forEach(function (box) {
            if (!box.querySelector('[data-oneof-if]')) return;
            var sync = function () {
                var on = noRt(box);
                box.querySelectorAll('[data-if-star], [data-if-note]').forEach(function (el) { el.hidden = !on; });
                box.querySelectorAll('input[data-oneof-if]').forEach(function (el) { if (on) el.setAttribute('aria-required', 'true'); else el.removeAttribute('aria-required'); });
            };
            var rt = box.querySelector('input[name$="[rt_rw]"]');
            if (rt) rt.addEventListener('input', sync);
            sync();
        });

        // Hidden fields: UTM and ad click ids, where the visitor came from, or another URL parameter.
        form.querySelectorAll('input[type=hidden][data-src]').forEach(function (h) {
            var src = h.dataset.src;
            if (src === 'static') return;
            var v = src === 'page_url' ? location.href.split('#')[0] : src === 'query' ? params.get(h.dataset.param || '') : store[src];
            if (v) h.value = String(v).slice(0, 300);
        });

        // Address dropdowns: province > city > district > village, each loaded when the one above is picked,
        // searchable by typing. Changing a level empties the ones below it; a village fills in its postal code.
        form.querySelectorAll('.lf-rg').forEach(function (box) {
            var base = box.dataset.regions, postal = box.querySelector('input[data-postal]'), cache = {};
            var pickers = Array.prototype.slice.call(box.querySelectorAll('.rg'));
            function norm(t) { return String(t).toLowerCase().replace(/^(kabupaten administrasi|kota administrasi|kabupaten|kota)\s+/, ''); }
            function load(code, done) {
                if (cache[code]) { done(cache[code]); return; }
                fetch(base + '/' + code, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                    .then(function (j) { cache[code] = j.items || []; done(cache[code]); })
                    .catch(function () { done(null, true); });
            }
            function clearBelow(i) {
                for (var j = i + 1; j < pickers.length; j++) pickers[j].reset(null);
                if (postal && postal.dataset.auto) { postal.value = ''; delete postal.dataset.auto; }
            }
            pickers.forEach(function (p, i) {
                var q = p.querySelector('.rg-q'), h = p.querySelector('input[type=hidden]'), list = p.querySelector('.rg-l');
                var items = p.dataset.items ? JSON.parse(p.dataset.items) : [], shown = [], active = -1, waitHint = q.placeholder;
                function close() { list.hidden = true; q.setAttribute('aria-expanded', 'false'); q.removeAttribute('aria-activedescendant'); }
                function mark(n) {
                    var opts = list.querySelectorAll('.rg-o');
                    if (!opts.length) return;
                    active = (n + opts.length) % opts.length;
                    for (var k = 0; k < opts.length; k++) opts[k].setAttribute('aria-selected', k === active ? 'true' : 'false');
                    q.setAttribute('aria-activedescendant', opts[active].id);
                    opts[active].scrollIntoView({ block: 'nearest' });
                }
                function render() {
                    // Every typed word must appear: "jaw bar", "kab bogor", "kota band"; provinces also by short name ("jabar", "dki").
                    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
                    shown = items.filter(function (it) { var n = (it[1] + ' ' + (it[3] || '')).toLowerCase(); return words.every(function (w) { return n.indexOf(w) !== -1; }); }).slice(0, 150);
                    list.innerHTML = '';
                    shown.forEach(function (it, k) {
                        var o = document.createElement('span');
                        o.className = 'rg-o'; o.id = list.id + '-' + k; o.setAttribute('role', 'option'); o.textContent = it[1];
                        o.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        o.addEventListener('click', function () { choose(it); });
                        list.appendChild(o);
                    });
                    if (!shown.length) { var none = document.createElement('span'); none.className = 'rg-none'; none.textContent = 'Tidak ditemukan. Coba kata lain.'; list.appendChild(none); }
                    active = -1;
                    list.hidden = false; q.setAttribute('aria-expanded', 'true');
                }
                function choose(it) {
                    var changed = h.value !== it[0];
                    h.value = it[0]; q.value = it[1]; q.removeAttribute('aria-invalid'); close();
                    if (!changed) return;
                    clearBelow(i);
                    var next = pickers[i + 1];
                    if (next) { next.wait(); load(it[0], function (list, failed) { next.reset(list, failed); }); }
                    if (it[2] && postal && (!postal.value || postal.dataset.auto)) { postal.value = it[2]; postal.dataset.auto = '1'; }
                }
                p.wait = function () { q.disabled = true; q.value = ''; h.value = ''; q.placeholder = 'Memuat...'; };
                p.reset = function (newItems, failed) {
                    items = newItems || []; q.value = ''; h.value = ''; close();
                    q.disabled = !newItems;
                    q.placeholder = failed ? 'Gagal memuat, pilih ulang di atas' : (newItems ? (p.dataset.hint || '') : waitHint);
                };
                q.addEventListener('input', function () { if (h.value) { h.value = ''; clearBelow(i); } render(); });
                q.addEventListener('focus', render);
                q.addEventListener('blur', function () {
                    setTimeout(close, 150);
                    if (!h.value && q.value.trim()) {
                        var exact = items.filter(function (it) { return norm(it[1]) === norm(q.value.trim()); });
                        if (exact.length === 1) choose(exact[0]);
                    }
                });
                q.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (list.hidden) render(); mark(active + (e.key === 'ArrowDown' ? 1 : -1)); }
                    else if (e.key === 'Enter' && !list.hidden && shown.length) { e.preventDefault(); choose(shown[active >= 0 ? active : 0]); }
                    else if (e.key === 'Escape') { close(); }
                });
            });
            if (postal) postal.addEventListener('input', function () { delete postal.dataset.auto; });
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
                if (el.classList.contains('rg-q')) {
                    if (!el.parentNode.querySelector('input[type=hidden]').value) return [el, 'Pilih ' + el.closest('label').querySelector('span').textContent.replace(' *', '').toLowerCase() + ' dari daftar.'];
                    continue;
                }
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
            // "Salah satu wajib": at least one box of each group filled (RT/RW or dusun, house number or landmark;
            // "landmark_if_no_rt" only while the RT/RW is empty or 0).
            var seen = {}, ones = form.querySelectorAll('input[data-oneof]');
            for (var o = 0; o < ones.length; o++) {
                var box = ones[o].closest('fieldset'), rule = ones[o].dataset.oneof;
                if (seen[rule + box.querySelector('legend').textContent]) continue;
                seen[rule + box.querySelector('legend').textContent] = 1;
                if (ones[o].dataset.oneofIf === 'no_rt' && !noRt(box)) continue;
                var group = box.querySelectorAll('input[data-oneof="' + rule + '"]'), filled = false;
                for (var gi = 0; gi < group.length; gi++) { if (String(group[gi].value).trim() !== '') filled = true; }
                var note = box.querySelector('[data-oneof-note="' + rule + '"]');
                if (!filled) return [group[0], note ? note.textContent : 'Lengkapi alamat.'];
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
