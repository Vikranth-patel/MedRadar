/* MedRadar — public search (autocomplete, locality/PIN, filters, grouped results, retry) */
document.addEventListener('DOMContentLoaded', () => {
    const $ = s => document.querySelector(s);
    const form = $('#search-form'); if (!form) return;
    const input = $('#search-input'), place = $('#place-input'), sugBox = $('#suggestions');
    const avail = $('#avail-select'), fresh = $('#fresh-check'), sort = $('#sort-select');
    const out = $('#results'), skeleton = $('#skeleton-loader'), empty = $('#no-results'), errBox = $('#error-state');
    const toolbar = $('#results-toolbar'), countEl = $('#results-count'), note = $('#uncertain-note'), recentRow = $('#recent-row');

    let selectedId = 0, lastCtrl = null, sugTimer = null, sugIndex = -1, searched = false;
    const RECENT = 'mr-recent';
    const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
    const store = { get() { try { return JSON.parse(localStorage.getItem(RECENT)) || []; } catch (e) { return []; } }, set(v) { try { localStorage.setItem(RECENT, JSON.stringify(v)); } catch (e) {} } };

    /* ---------- recent searches (browser-stored only) ---------- */
    function renderRecent() {
        const l = store.get(); recentRow.classList.toggle('hidden', !l.length); if (!l.length) return;
        recentRow.innerHTML = '<span class="quick-label">Recent:</span>' + l.map(q => `<button type="button" class="tag-btn recent" data-search="${esc(q)}">${esc(q)}</button>`).join('') + '<button type="button" class="clear-recent" id="clear-recent">Clear</button>';
    }
    function saveRecent(q) { const l = store.get().filter(x => x.toLowerCase() !== q.toLowerCase()); l.unshift(q); store.set(l.slice(0, 5)); renderRecent(); }
    renderRecent();

    document.addEventListener('click', e => {
        const tag = e.target.closest('.tag-btn');
        if (tag) { input.value = tag.dataset.search; selectedId = 0; hideSug(); run(); return; }
        if (e.target.id === 'clear-recent') { store.set([]); renderRecent(); }
        if (!e.target.closest('.search-input-wrapper')) hideSug();
    });
    document.addEventListener('keydown', e => { if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); input.focus(); } });

    /* ---------- debounced autocomplete: product + strength + form ---------- */
    input.addEventListener('input', () => {
        selectedId = 0; clearTimeout(sugTimer);
        const q = input.value.trim(); if (q.length < 2) return hideSug();
        sugTimer = setTimeout(() => suggest(q), 280);
    });
    input.addEventListener('keydown', e => {
        const items = [...sugBox.querySelectorAll('li')]; if (!items.length || sugBox.classList.contains('hidden')) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sugIndex = (sugIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; items.forEach((li, i) => li.classList.toggle('active', i === sugIndex)); }
        else if (e.key === 'Enter' && sugIndex > -1) { e.preventDefault(); items[sugIndex].click(); }
        else if (e.key === 'Escape') hideSug();
    });
    async function suggest(q) {
        try {
            const r = await fetch('api/search.php?mode=suggest&q=' + encodeURIComponent(q)); const d = await r.json();
            if (!d.suggestions || !d.suggestions.length) return hideSug();
            sugBox.innerHTML = d.suggestions.map(s => `<li role="option" data-id="${s.id}" data-label="${esc(s.label)}">${esc(s.label)} <small>${esc(s.ingredient_name)}</small></li>`).join('');
            sugBox.classList.remove('hidden'); input.setAttribute('aria-expanded', 'true'); sugIndex = -1;
            sugBox.querySelectorAll('li').forEach(li => li.addEventListener('click', () => { input.value = li.dataset.label; selectedId = +li.dataset.id; hideSug(); run(); }));
        } catch (e) { hideSug(); }
    }
    function hideSug() { sugBox.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); sugIndex = -1; }

    form.addEventListener('submit', e => { e.preventDefault(); hideSug(); run(); });
    [avail, fresh, sort].forEach(el => el.addEventListener('change', () => searched && run(false)));
    place.addEventListener('change', () => searched && run(false));
    $('#retry-btn').addEventListener('click', () => run(false));

    /* ---------- search ---------- */
    function show(state) {
        skeleton.classList.toggle('hidden', state !== 'loading');
        empty.classList.toggle('hidden', state !== 'empty');
        errBox.classList.toggle('hidden', state !== 'error');
        if (state !== 'ok') { toolbar.classList.add('hidden'); note.classList.add('hidden'); if (state !== 'loading') out.innerHTML = ''; else out.innerHTML = ''; }
    }
    async function run(scroll = true) {
        const q = input.value.trim(); if (q.length < 2) { input.focus(); return; }
        searched = true; if (lastCtrl) lastCtrl.abort(); lastCtrl = new AbortController(); show('loading');
        if (scroll) document.getElementById('results-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        const p = new URLSearchParams({ q, availability: avail.value, sort: sort.value });
        if (selectedId) p.set('medicine_id', selectedId);
        if (place.value.trim()) p.set('place', place.value.trim());
        if (fresh.checked) p.set('fresh', '1');
        try {
            const res = await fetch('api/search.php?' + p, { signal: lastCtrl.signal }); const d = await res.json();
            if (!res.ok) { if (res.status === 422) { show('empty'); $('#no-results-title').textContent = 'Please type a little more'; $('#no-results-text').textContent = d.error; return; } throw new Error(d.error || 'bad'); }
            saveRecent(q);
            if (!d.medicines.length) { show('empty'); $('#no-results-title').textContent = 'No matching products'; $('#no-results-text').textContent = d.message; return; }
            show('ok'); render(d);
        } catch (err) {
            if (err.name === 'AbortError') return;
            show('error'); $('#error-text').textContent = 'We could not reach the server. Check your connection and try again.';
        }
    }

    let render = function (d) {
        toolbar.classList.remove('hidden'); note.classList.remove('hidden');
        const n = d.medicines.length;
        countEl.innerHTML = `${d.total} listing${d.total === 1 ? '' : 's'}<small>across ${n} product${n === 1 ? '' : 's'}${d.place ? ' in “' + esc(d.place) + '”' : ''}</small>`;
        out.innerHTML = d.medicines.map(g => `
            <section class="med-group">
                <h3 class="med-title">${esc(g.medicine.label)} <small>${esc(g.medicine.ingredient_name)}</small></h3>
                ${g.results.length ? `<div class="cards-grid">${g.results.map(card).join('')}</div>` :
                `<p class="muted empty-line">No approved pharmacy reports this exact product${d.place ? ' in “' + esc(d.place) + '”' : ''} with the current filters. We don't suggest substitutes — ask your pharmacist or doctor.</p>`}
            </section>`).join('');
    };

    const card = function (r, i) {
        const f = r.freshness, ic = { fresh: '● ', stale: '⚠ ', never: '? ', out: '✖ ' }[f.key];
        const maps = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(`${r.pharmacy_name}, ${r.address}, ${r.locality} ${r.pin_code}`);
        const phone = (r.phone || '').replace(/[^0-9+]/g, '');
        return `
        <article class="card" style="animation-delay:${Math.min(i, 8) * 40}ms">
            <div>
                <div class="card-header"><h4 class="drug-title"><a href="pharmacy.php?id=${r.pharmacy_id}">${esc(r.pharmacy_name)}</a></h4></div>
                <p class="card-badges"><span class="badge badge-${r.status.replace(/_/g, '')}">${esc(r.status_text)}</span>
                   <span class="fresh fresh-${f.key}">${ic}${esc(f.label)}</span></p>
                <p class="verification-note"><span>🕒</span>${esc(f.detail)}</p>
                ${f.key === 'stale' || f.key === 'never' ? '<p class="uncertain-inline">Uncertain — please confirm before travelling.</p>' : ''}
                <div class="pharmacy-details">
                    <div class="info-line"><span>📍</span>${esc(r.address)}, ${esc(r.locality)} ${esc(r.pin_code)}</div>
                    <div class="info-line"><span>📞</span>${esc(r.phone)}</div>
                    <div class="info-line ${r.closed_today ? 'text-bad' : ''}"><span>⏰</span>${esc(r.hours_today)}</div>
                    <div class="info-line"><span>✅</span><span title="An administrator reviewed this pharmacy. This is not a professional licence verification.">${esc(r.approval)}</span></div>
                </div>
            </div>
            <div class="card-actions">
                <a href="tel:${phone}" class="action-btn btn-call">📞 Call</a>
                <a href="${maps}" target="_blank" rel="noopener noreferrer" class="action-btn">📍 Directions ↗</a>
                <button type="button" class="action-btn wide" data-request="${r.inventory_id}" data-product="${esc(r.__label || '')}" data-pharmacy="${esc(r.pharmacy_name)}">✉ Request confirmation</button>
                <a href="pharmacy.php?id=${r.pharmacy_id}" class="action-btn">Details</a>
                <a href="report.php?pharmacy=${r.pharmacy_id}&inventory=${r.inventory_id}" class="action-btn">⚑ Report</a>
            </div>
        </article>`;
    };

    // attach product label to each result so the request dialog can name the exact product
    const _render = render;
    render = function (d) { d.medicines.forEach(g => g.results.forEach(r => r.__label = g.medicine.label)); _render(d); };
});
