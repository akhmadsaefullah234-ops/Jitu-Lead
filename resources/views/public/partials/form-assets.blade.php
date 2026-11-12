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
    .lf-warn { margin: 0; color: #b45309; font-size: .8rem; font-weight: 600; }
    .lf-rtk { min-width: 0; }
    /* minmax(0, 1fr): a long choice ("Dusun/kampung") never pushes the form past the screen; under 360px the two
       choices stack */
    .lf-rtk .lf-seg { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .45rem; }
    .lf-rtk .lf-o { min-width: 0; }
    .lf-rtk .lf-o span { min-width: 0; overflow-wrap: break-word; }
    @media (max-width: 359px) { .lf-rtk .lf-seg { grid-template-columns: minmax(0, 1fr); } }
    .lf-qty { display: grid; grid-template-columns: 3rem 1fr 3rem; gap: .4rem; align-items: stretch; }
    .lf-qty input { text-align: center; -moz-appearance: textfield; }
    .lf-qty input::-webkit-outer-spin-button, .lf-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .lf-qb { font: inherit; font-size: 1.35rem; line-height: 1; border: 1.5px solid #d1d5db; border-radius: .75rem; background: #fff; color: #111827; cursor: pointer; min-height: 3rem; }
    .lf-qb:active { background: color-mix(in srgb, var(--c) 10%, #fff); }
    .lf-step { display: grid; gap: .9rem; min-width: 0; }
    .lf-prog { margin: 0; display: grid; gap: .35rem; font-size: .85rem; color: #374151; }
    .lf-bar { display: block; height: .35rem; border-radius: 1rem; background: #e5e7eb; overflow: hidden; }
    .lf-bar i { display: block; height: 100%; width: 50%; background: var(--c); transition: width .25s; }
    .lf[data-at="2"] .lf-bar i { width: 100%; }
    .lf-back { justify-self: start; font: inherit; font-size: .85rem; background: none; border: 0; padding: .25rem 0; color: #4b5563; cursor: pointer; text-decoration: underline; }
    .lf-sum { display: grid; gap: .35rem; padding: .85rem 1rem; border-radius: .75rem; background: #f9fafb; border: 1.5px solid #e5e7eb; font-size: .9rem; color: #111827; }
    .lf-sum p { margin: 0; display: flex; justify-content: space-between; gap: .75rem; }
    .lf-sum small { color: #6b7280; font-weight: 400; }
    .lf-sum s { color: #9ca3af; font-weight: 400; }
    .lf-sum-promo { color: #15803d; font-size: .8rem; }
    .lf-sum-total { border-top: 1px dashed #d1d5db; padding-top: .45rem; font-size: 1rem; }
    .lf-ok strong { font-size: 1.1rem; }
    /* Phones: the send button stays in reach while the buyer scrolls through the form. */
    @media (max-width: 640px) {
        .lf-send, .lf-next { position: sticky; bottom: .6rem; z-index: 30; box-shadow: 0 8px 24px rgba(17,24,39,.18); }
        .lf { gap: 1rem; }
    }
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
    function rtKind(box) { var k = box.querySelector('input[name$="[rt_kind]"]:checked'); return k ? k.value : null; }
    function noRt(box) { var rt = box.querySelector('input[name$="[rt_rw]"]'); return !rt || rtKind(box) === 'dusun' || !/[1-9]/.test(rt.value); }
    // An RT/RW or a dusun/kampung written in one address box ("RT 01", "RT/RW 02/03", "Dusun X", "Dsn.", "Kp. Y").
    var RT_OR_DUSUN = /\brt\.?\s*(?:\/\s*rw\.?)?\s*[:.]?\s*0*[1-9]|\b(?:dusun|dsn|dukuh|kampung|kp|lingkungan|lingk)\b/i;

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
            box.addEventListener('rtk', sync);
            sync();
        });

        // "Alamat saya memakai: RT/RW | Dusun/kampung": only the chosen box is shown.
        form.querySelectorAll('fieldset.lf-addr').forEach(function (box) {
            var kinds = box.querySelectorAll('input[name$="[rt_kind]"]');
            if (!kinds.length) return;
            var sync = function () {
                var kind = rtKind(box) || 'rt';
                box.querySelectorAll('[data-rtk]').forEach(function (el) { el.hidden = el.dataset.rtk !== kind; });
                box.dispatchEvent(new Event('rtk'));
            };
            kinds.forEach(function (k) { k.addEventListener('change', sync); });
            sync();
        });

        // A street box that should name an RT/RW or a dusun: a hint, never a refusal (the lead is marked instead).
        form.querySelectorAll('input[data-soft-rt]').forEach(function (el) {
            var warn = el.parentNode.querySelector('[data-soft-rt-warn]');
            var fine = function () { return !el.value.trim() || RT_OR_DUSUN.test(el.value); };
            if (!warn) return;
            el.addEventListener('blur', function () { warn.hidden = fine(); });
            el.addEventListener('input', function () { if (!warn.hidden && fine()) warn.hidden = true; });
        });

        // Quantity: - and + (at least 1).
        form.querySelectorAll('.lf-qty').forEach(function (q) {
            var input = q.querySelector('input');
            q.querySelectorAll('[data-qty]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var n = Math.max(1, (parseInt(input.value, 10) || 1) + parseInt(b.dataset.qty, 10));
                    input.value = n; input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
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
            // "Sembunyikan kode pos": filled in from the kelurahan; its box shows only when that has none, or when
            // the kelurahan was typed but not picked from the list, or the list could not load.
            var fallback = box.querySelector('[data-postal-fallback]'), village = box.querySelector('.rg[data-part=kelurahan]');
            function syncPostal() {
                if (!fallback) return;
                var picked = village && village.querySelector('input[type=hidden]').value, typed = village && village.querySelector('.rg-q').value.trim();
                var need = (picked && !(postal.value && postal.dataset.auto)) || (!picked && typed && !village.querySelector('.rg-q').matches(':focus')) || (village && village.dataset.failed);
                fallback.hidden = !need;
                postal.required = !!need;
            }
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
                syncPostal();
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
                    syncPostal();
                    box.dispatchEvent(new CustomEvent('region:picked', { bubbles: true }));
                }
                p.wait = function () { q.disabled = true; q.value = ''; h.value = ''; q.placeholder = 'Memuat...'; };
                p.reset = function (newItems, failed) {
                    items = newItems || []; q.value = ''; h.value = ''; close();
                    q.disabled = !newItems;
                    if (failed) p.dataset.failed = '1'; else delete p.dataset.failed;
                    q.placeholder = failed ? 'Gagal memuat, pilih ulang di atas' : (newItems ? (p.dataset.hint || '') : waitHint);
                    syncPostal();
                };
                // "Mulai dari kota/kabupaten": the first list is every city at once (GET /wilayah/semua-kota).
                if (p.dataset.load) {
                    q.disabled = true; q.placeholder = 'Memuat...';
                    fetch(p.dataset.load, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                        .then(function (j) { items = j.items || []; q.disabled = false; q.placeholder = p.dataset.hint || ''; })
                        .catch(function () { q.disabled = false; q.placeholder = 'Gagal memuat daftar. Muat ulang halaman.'; });
                }
                q.addEventListener('input', function () { if (h.value) { h.value = ''; clearBelow(i); } render(); });
                q.addEventListener('focus', render);
                q.addEventListener('blur', function () {
                    setTimeout(close, 150);
                    if (!h.value && q.value.trim()) {
                        var exact = items.filter(function (it) { return norm(it[1]) === norm(q.value.trim()) || norm(it[1].replace(/\s*\([^)]*\)$/, '')) === norm(q.value.trim()); });
                        if (exact.length === 1) choose(exact[0]);
                    }
                    setTimeout(syncPostal, 0);
                });
                q.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (list.hidden) render(); mark(active + (e.key === 'ArrowDown' ? 1 : -1)); }
                    else if (e.key === 'Enter' && !list.hidden && shown.length) { e.preventDefault(); choose(shown[active >= 0 ? active : 0]); }
                    else if (e.key === 'Escape') { close(); }
                });
            });
            if (postal) postal.addEventListener('input', function () { delete postal.dataset.auto; });
            syncPostal();
        });

        // Two steps: name, WhatsApp, colour and quantity first; "Lanjut" keeps the lead, then the address.
        var steps = form.querySelectorAll('.lf-step');
        function showStep(n) {
            form.dataset.at = String(n);
            steps.forEach(function (st) { st.hidden = st.dataset.step !== String(n); });
            form.querySelectorAll('[data-step-only]').forEach(function (el) { el.hidden = el.dataset.stepOnly !== String(n); });
            var now = form.querySelector('[data-step-now]');
            if (now) now.textContent = 'Langkah ' + n + '/2';
        }
        if (form.dataset.steps === '2' && steps.length === 2) {
            showStep(1);
            form.querySelector('.lf-next').addEventListener('click', function () {
                err.hidden = true;
                var bad = check();
                if (bad) { fail(bad[1], bad[0]); return; }
                var next = this; next.disabled = true;
                var fd = collect();
                fd.set('step', '1');
                // The lead is kept even if the buyer stops here; going on never waits for it.
                fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd }).catch(function () {});
                next.disabled = false;
                showStep(2);
                try { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                var first = steps[1].querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
                if (first) setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) {} }, 300);
            });
            form.querySelector('.lf-back').addEventListener('click', function () { showStep(1); });
        }

        // Order summary: price x quantity, shipping (with the promo) and the total, once the city is known.
        var sum = form.querySelector('[data-sum]'), quoted = '', quoteTimer = null;
        function quote() {
            if (!sum || !form.dataset.quote) return;
            var box = form.querySelector('fieldset.lf-addr'), qs = new URLSearchParams();
            if (!box) return;
            box.querySelectorAll('input[name], select[name]').forEach(function (el) {
                var m = el.name.match(/\[([a-z_]+)\]$/);
                if (m && el.type !== 'radio' && el.value) qs.set('alamat[' + m[1] + ']', el.value);
            });
            var qty = form.querySelector('input[data-qty-input]');
            qs.set('jumlah', qty && /^\d+$/.test(qty.value) && +qty.value > 0 ? qty.value : '1');
            if (form.dataset.page) qs.set('page', form.dataset.page);
            var key = qs.toString();
            if (key === quoted) return;
            quoted = key;
            fetch(form.dataset.quote + '?' + key, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok || key !== quoted) return;
                    var set = function (sel, text) { var el = sum.querySelector(sel); if (el) el.textContent = text || ''; return el; };
                    set('[data-sum-item]', 'Harga ' + j.jumlah + ' x ' + j.harga_teks);
                    set('[data-sum-subtotal]', j.subtotal_teks);
                    set('[data-sum-ship]', j.ongkir_teks || 'dikonfirmasi');
                    set('[data-sum-courier]', j.kurir ? '(' + j.kurir + ')' : '');
                    var normal = set('[data-sum-normal]', j.ongkir_normal_teks); normal.hidden = !j.ongkir_normal_teks;
                    var promo = set('[data-sum-promo]', j.promo ? 'Promo: ' + j.promo : ''); promo.hidden = !j.promo;
                    set('[data-sum-total]', j.total_teks || (j.subtotal_teks + ' + ongkir'));
                    var note = set('[data-sum-note]', j.pesan); note.hidden = !j.pesan;
                    sum.hidden = false;
                }).catch(function () {});
        }
        function quoteSoon() { clearTimeout(quoteTimer); quoteTimer = setTimeout(quote, 250); }
        if (sum) {
            form.addEventListener('region:picked', quoteSoon);
            form.addEventListener('change', function (e) { if (e.target.matches('input[data-qty-input], fieldset.lf-addr input, fieldset.lf-addr select')) quoteSoon(); });
        }

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
                // A box not shown (the other step, the RT/RW | dusun box not chosen, a postal code filled in for the buyer).
                if (el.closest('[hidden]')) continue;
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
                if (ones[o].closest('.lf-step[hidden]')) continue;
                var box = ones[o].closest('fieldset'), rule = ones[o].dataset.oneof;
                if (seen[rule + box.querySelector('legend').textContent]) continue;
                seen[rule + box.querySelector('legend').textContent] = 1;
                if (ones[o].dataset.oneofIf === 'no_rt' && !noRt(box)) continue;
                var group = box.querySelectorAll('input[data-oneof="' + rule + '"]'), filled = false;
                for (var gi = 0; gi < group.length; gi++) { if (!group[gi].closest('[hidden]') && String(group[gi].value).trim() !== '' && !(/\[rt_rw\]$/.test(group[gi].name) && rtKind(box) && !/[1-9]/.test(group[gi].value))) filled = true; }
                var note = box.querySelector('[data-oneof-note="' + rule + '"]');
                if (!filled) return [group[0], note ? note.textContent : 'Lengkapi alamat.'];
            }
            var qtys = form.querySelectorAll('input[data-qty-input]');
            for (var qi = 0; qi < qtys.length; qi++) {
                if (qtys[qi].closest('[hidden]') || qtys[qi].value === '') continue;
                if (!/^\d+$/.test(qtys[qi].value) || +qtys[qi].value < 1) return [qtys[qi], 'Jumlah minimal 1.'];
            }
            var phones = form.querySelectorAll('input[data-phone]');
            for (var p = 0; p < phones.length; p++) {
                if (phones[p].closest('[hidden]')) continue;
                var digits = phones[p].value.replace(/[\s().+-]/g, '');
                if (digits && !PHONE.test(digits)) return [phones[p], 'Nomor WhatsApp harus diawali 08 atau 62, contoh 081234567890.'];
            }
            var files = form.querySelectorAll('input[type=file]');
            for (var f = 0; f < files.length; f++) { if (files[f].files[0] && files[f].files[0].size > 5 * 1024 * 1024) return [files[f], 'Foto terlalu besar, maksimal 5 MB.']; }
            return null;
        }

        // The form's answers with the ad tags, browser ids and page (both steps send the same).
        function collect() {
            var fd = new FormData(form);
            CAMPAIGN.forEach(function (k) { if (store[k]) fd.set(k, store[k]); });
            var fbc = cookie('_fbc') || (store.fbclid ? 'fb.1.' + Date.now() + '.' + store.fbclid : null);
            if (cookie('_fbp')) fd.set('fbp', cookie('_fbp'));
            if (fbc) fd.set('fbc', fbc);
            if (store.ttclid) fd.set('ttclid', store.ttclid);
            if (cookie('_ttp')) fd.set('ttp', cookie('_ttp'));
            fd.set('source_url', location.href.split('#')[0].slice(0, 500));
            if (form.dataset.page) fd.set('page', form.dataset.page);
            return fd;
        }

        function go(action) {
            var msg = form.querySelector('.lf-ok-msg'), link = form.querySelector('.lf-go');
            Array.prototype.forEach.call(form.children, function (c) { if (c !== ok) c.hidden = true; });
            msg.textContent = action.message || 'Terima kasih!';
            ok.hidden = false;
            form.dispatchEvent(new CustomEvent('lead:sent', { bubbles: true, detail: action }));
            if ((action.type === 'whatsapp' || action.type === 'redirect') && action.url) {
                link.href = action.url; link.hidden = false;
                link.textContent = action.type === 'whatsapp' ? 'Buka WhatsApp' : 'Lanjutkan';
                var wa = form.querySelector('.lf-ok-wa');
                if (wa && action.type === 'whatsapp') wa.hidden = false;
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
            var fd = collect();
            var eventId = uuid();
            fd.set('event_id', eventId);
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
