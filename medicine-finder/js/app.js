/* MedRadar — shared UI behaviour (all pages) */
(function () {
    'use strict';
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];

    /* ---------- Toasts (window.toast) ---------- */
    window.toast = function (msg, ms = 2600) {
        let wrap = $('#toast-wrap');
        if (!wrap) { wrap = document.createElement('div'); wrap.id = 'toast-wrap'; wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
        const t = document.createElement('div');
        t.className = 'toast'; t.textContent = msg; wrap.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, ms);
    };

    /* ---------- Dark / light theme ---------- */
    const themeBtn = $('#theme-toggle');
    if (themeBtn) themeBtn.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('rx-theme', next); } catch (e) {}
    });

    /* ---------- Mobile menu ---------- */
    const menuBtn = $('#menu-toggle'), links = $('#nav-links');
    if (menuBtn && links) {
        const close = () => { links.classList.remove('open'); menuBtn.setAttribute('aria-expanded', 'false'); };
        menuBtn.addEventListener('click', () => {
            const open = links.classList.toggle('open');
            menuBtn.setAttribute('aria-expanded', String(open));
        });
        links.addEventListener('click', e => { if (e.target.closest('a')) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    }

    /* ---------- Footer year ---------- */
    const yr = $('#year'); if (yr) yr.textContent = new Date().getFullYear();

    /* ---------- Back to top ---------- */
    const toTop = $('#to-top');
    if (toTop) {
        addEventListener('scroll', () => toTop.classList.toggle('show', scrollY > 600), { passive: true });
        toTop.addEventListener('click', () => scrollTo({ top: 0, behavior: 'smooth' }));
    }

    /* ---------- Scroll reveal ---------- */
    const reveals = $$('.reveal');
    if ('IntersectionObserver' in window && reveals.length) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
        }, { threshold: .12 });
        reveals.forEach((el, i) => { el.style.transitionDelay = (i % 4) * 80 + 'ms'; io.observe(el); });
    } else reveals.forEach(el => el.classList.add('in'));

    /* ---------- Animated counters ---------- */
    const counters = $$('[data-count]');
    const run = el => {
        const end = +el.dataset.count, suffix = el.dataset.suffix || '', t0 = performance.now(), dur = 1100;
        const tick = now => {
            const p = Math.min((now - t0) / dur, 1);
            el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))) + suffix;
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };
    if ('IntersectionObserver' in window) {
        const co = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { run(e.target); co.unobserve(e.target); } }), { threshold: .4 });
        counters.forEach(c => co.observe(c));
    } else counters.forEach(run);

    /* ---------- Password show/hide (login) ---------- */
    $$('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? '🙈' : '👁';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }));

    /* ---------- Login / Register tabs ---------- */
    const tabTriggers = $$('[data-tab]');
    if (tabTriggers.length) {
        const copy = {
            login: ['Welcome back', 'Sign in to manage inventory and requests.'],
            register: ['Register your pharmacy', 'New pharmacies start as “pending” until an administrator approves them.']
        };
        const show = mode => {
            $$('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === mode));
            $$('[data-panel]').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== mode));
            const vis = $('.auth-visual'); if (vis) vis.classList.toggle('is-register', mode === 'register');
            const box = $('#auth-box'); if (box) box.classList.toggle('wide', mode === 'register');
            $('#auth-title').textContent = copy[mode][0];
            $('#auth-sub').textContent = copy[mode][1];
            $$('.alert-err').forEach(a => a.remove());
        };
        tabTriggers.forEach(t => t.addEventListener('click', e => { e.preventDefault(); show(t.dataset.tab); }));
        if (location.hash === '#register') show('register');
        // client-side password match check
        const reg = $('[data-panel="register"]');
        if (reg) reg.addEventListener('submit', e => {
            if ($('#r-pass').value !== $('#r-pass2').value) { e.preventDefault(); toast('Passwords do not match'); }
        });
    }

    /* ---------- Confirm destructive actions ---------- */
    document.addEventListener('submit', e => {
        const f = e.target.closest('form[data-confirm]');
        if (f && !confirm(f.dataset.confirm)) e.preventDefault();
    });

    /* ---------- Dismissible alerts ---------- */
    $$('.alert').forEach(a => {
        const x = a.querySelector('button'); if (x) x.addEventListener('click', () => a.remove());
        if (a.classList.contains('alert-ok')) setTimeout(() => a.remove(), 5000);
    });

    /* ---------- Dashboard: live table filter ---------- */
    const tf = $('#table-filter');
    if (tf) {
        const rows = $$('#stock-table tbody tr[data-search]');
        const empty = $('#filter-empty');
        tf.addEventListener('input', () => {
            const q = tf.value.trim().toLowerCase(); let n = 0;
            rows.forEach(r => { const hit = r.dataset.search.includes(q); r.classList.toggle('hidden', !hit); if (hit) n++; });
            if (empty) empty.classList.toggle('hidden', n > 0);
        });
    }
})();
