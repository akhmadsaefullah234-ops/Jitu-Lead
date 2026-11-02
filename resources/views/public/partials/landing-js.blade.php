<script>
(function () {
    var rp = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };
    var num = function (s) { return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0; };
    document.querySelectorAll('[data-kpr]').forEach(function (box) {
        var f = function (k) { return box.querySelector('[data-k="' + k + '"]'); };
        var calc = function () {
            var price = num(f('price').value), dp = Math.min(Math.max(num(f('dp').value), 0), 90);
            var n = Math.max(num(f('years').value), 1) * 12, r = Math.max(num(f('rate').value), 0) / 100 / 12;
            var loan = price * (1 - dp / 100);
            var pay = r === 0 ? loan / n : loan * r / (1 - Math.pow(1 + r, -n));
            f('out').textContent = price > 0 ? rp(pay) : '-';
            f('loan').textContent = price > 0 ? 'Pokok pinjaman ' + rp(loan) + ', uang muka ' + rp(price * dp / 100) : '';
        };
        box.addEventListener('input', calc); calc();
    });
    document.querySelectorAll('[data-count]').forEach(function (el) {
        var end = new Date(el.getAttribute('data-count')).getTime();
        var tick = function () {
            var s = Math.floor((end - Date.now()) / 1000);
            if (s <= 0) { el.textContent = el.getAttribute('data-ended'); return; }
            var set = function (u, v) { el.querySelector('[data-u="' + u + '"]').textContent = v; };
            set('d', Math.floor(s / 86400)); set('h', Math.floor(s % 86400 / 3600)); set('m', Math.floor(s % 3600 / 60)); set('s', s % 60);
            setTimeout(tick, 1000);
        };
        tick();
    });
    // Product: a variant shows its own photos, and every pick is filled in on the order form when it has that choice.
    var pickInForm = function (value) {
        var v = value.toLowerCase();
        document.querySelectorAll('#daftar select').forEach(function (sel) {
            Array.prototype.forEach.call(sel.options, function (o) { if (o.value.toLowerCase() === v) { sel.value = o.value; sel.dispatchEvent(new Event('change', { bubbles: true })); } });
        });
        document.querySelectorAll('#daftar input[type=radio]').forEach(function (r) {
            if (r.value.toLowerCase() === v) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
        });
    };
    document.querySelectorAll('[data-product]').forEach(function (box) {
        box.querySelectorAll('.chips').forEach(function (group) {
            group.addEventListener('click', function (e) {
                var chip = e.target.closest('.chip');
                if (!chip) { return; }
                group.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
                var label = group.parentNode.querySelector('[data-vname]');
                if (label) { label.textContent = chip.getAttribute('data-pick'); }
                var v = chip.getAttribute('data-v');
                if (v !== null && (box.querySelector('[data-gal="' + v + '"]') || box.querySelector('[data-gal="all"]'))) {
                    var own = box.querySelector('[data-gal="' + v + '"]');
                    box.querySelectorAll('[data-gal]').forEach(function (g) {
                        g.hidden = own ? g !== own : g.getAttribute('data-gal') !== 'all';
                    });
                    if (own) { own.scrollLeft = 0; }
                }
                pickInForm(chip.getAttribute('data-pick'));
            });
        });
    });
    document.querySelectorAll('[data-yt]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var i = document.createElement('iframe');
            i.src = 'https://www.youtube-nocookie.com/embed/' + a.getAttribute('data-yt') + '?autoplay=1&rel=0';
            i.allow = 'autoplay; encrypted-media; picture-in-picture'; i.allowFullscreen = true; i.title = 'Video';
            a.replaceWith(i);
        });
    });
})();
</script>
