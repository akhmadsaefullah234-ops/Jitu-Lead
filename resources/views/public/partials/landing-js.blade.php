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
    // Sliders: swiping is native (scroll snapping); arrows, dots, looping and autoplay are added here.
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('[data-slider]').forEach(function (box) {
        var track = box.querySelector('.sl-track'), slides = box.querySelectorAll('.slide'), dots = box.querySelectorAll('[data-dots] button');
        var fade = box.classList.contains('fade'), loop = box.hasAttribute('data-loop'), at = 0;
        var per = function () { return fade ? 1 : Math.max(1, parseInt(getComputedStyle(box).getPropertyValue('--pv'), 10) || 1); };
        var last = function () { return Math.max(0, slides.length - per()); };
        var mark = function (i) {
            at = i;
            dots.forEach(function (d, k) { d.setAttribute('aria-current', k === i ? 'true' : 'false'); });
            slides.forEach(function (s, k) { s.classList.toggle('on', k === i); });
        };
        var go = function (i) {
            if (i > last()) { i = loop ? 0 : last(); }
            if (i < 0) { i = loop ? last() : 0; }
            if (!fade) { track.scrollTo({ left: slides[i].offsetLeft - track.offsetLeft, behavior: still ? 'auto' : 'smooth' }); }
            mark(i);
        };
        if (!fade) {
            var t; track.addEventListener('scroll', function () {
                clearTimeout(t); t = setTimeout(function () {
                    var w = slides[0].getBoundingClientRect().width + parseFloat(getComputedStyle(track).columnGap || 0);
                    mark(Math.min(last(), Math.round(track.scrollLeft / (w || 1))));
                }, 80);
            });
        } else {
            var x0 = null;
            track.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
            track.addEventListener('touchend', function (e) { if (x0 === null) { return; } var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { go(at + (dx < 0 ? 1 : -1)); } x0 = null; });
        }
        var prev = box.querySelector('[data-prev]'), next = box.querySelector('[data-next]');
        if (prev) { prev.addEventListener('click', function () { go(at - 1); }); }
        if (next) { next.addEventListener('click', function () { go(at + 1); }); }
        dots.forEach(function (d, k) { d.addEventListener('click', function () { go(k); }); });
        var auto = parseInt(box.getAttribute('data-auto'), 10);
        if (auto && !still) {
            var paused = false;
            box.addEventListener('mouseenter', function () { paused = true; });
            box.addEventListener('mouseleave', function () { paused = false; });
            box.addEventListener('touchstart', function () { paused = true; }, { passive: true });
            setInterval(function () { if (!paused && !document.hidden) { go(at + 1); } }, auto * 1000);
        }
    });
    // Entrance animations: shown as the section scrolls into view.
    var anim = document.querySelectorAll('.bx-anim');
    if (anim.length) {
        if (!('IntersectionObserver' in window) || still) { anim.forEach(function (el) { el.classList.add('in'); }); }
        else {
            var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { threshold: 0.12 });
            anim.forEach(function (el) { io.observe(el); });
        }
    }
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
