/* MedRadar — public pages: request-confirmation dialog, request tracking, incorrect-info report */
(function () {
    'use strict';
    const $ = s => document.querySelector(s);
    const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
    const csrf = () => (document.querySelector('meta[name="csrf"]') || {}).content || '';
    const KEY = 'mr-requests';
    const saved = { get() { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } }, set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} } };
    async function post(url, data) {
        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(data) });
        let j = {}; try { j = await r.json(); } catch (e) {}
        if (!r.ok) throw new Error(j.error || 'Something went wrong. Please try again.');
        return j;
    }

    /* ---------- Request-confirmation dialog ---------- */
    const dlg = $('#request-dialog');
    if (dlg) {
        let current = null;
        const view = (form) => { $('#rd-form').classList.toggle('hidden', !form); $('#rd-done').classList.toggle('hidden', form); };
        document.addEventListener('click', e => {
            const b = e.target.closest('[data-request]'); if (!b) return;
            current = { id: +b.dataset.request, product: b.dataset.product, pharmacy: b.dataset.pharmacy };
            $('#rd-product').textContent = current.product || 'This product'; $('#rd-pharmacy').textContent = current.pharmacy;
            $('#rd-error').classList.add('hidden'); view(true); $('#rd-send').disabled = false;
            typeof dlg.showModal === 'function' ? dlg.showModal() : dlg.setAttribute('open', '');
        });
        dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
        $('#rd-send').addEventListener('click', async () => {
            const btn = $('#rd-send'); btn.disabled = true; btn.textContent = 'Sending…';
            try {
                const d = await post('api/request_create.php', { inventory_id: current.id });
                const url = new URL(d.track_url, location.href).href;
                $('#rd-link').value = url; $('#rd-open').href = d.track_url; view(false);
                const l = saved.get(); l.unshift({ t: d.token, label: d.product, pharmacy: d.pharmacy, at: Date.now() }); saved.set(l.slice(0, 10));
            } catch (err) { const eb = $('#rd-error'); eb.textContent = err.message; eb.classList.remove('hidden'); }
            btn.disabled = false; btn.textContent = 'Send request';
        });
        $('#rd-copy').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText($('#rd-link').value); window.toast && toast('Link copied'); } catch (e) { $('#rd-link').select(); }
        });
    }

    /* ---------- Tracking page ---------- */
    const root = $('#track-root');
    if (root) {
        const token = root.dataset.token, result = $('#track-result'); let timer = null;
        const tf = $('#track-form');
        if (tf) tf.addEventListener('submit', e => { e.preventDefault(); const v = $('#track-input').value.trim().toLowerCase(); if (/^[a-f0-9]{32}$/.test(v)) location.href = 'track.php?t=' + v; });

        const list = saved.get();
        if (list.length) {
            $('#recent-requests').classList.remove('hidden');
            $('#recent-requests-list').innerHTML = list.map(r => `<li><a href="track.php?t=${esc(r.t)}">${esc(r.label)}</a> <span class="muted small">· ${esc(r.pharmacy)} · ${new Date(r.at).toLocaleString()}</span></li>`).join('');
        }
        async function load() {
            try {
                const r = await fetch('api/request_status.php?t=' + token); const d = await r.json();
                if (!r.ok) { result.innerHTML = `<div class="alert alert-err" role="alert">${esc(d.error || 'Request not found.')}</div>`; return stop(); }
                const phone = (d.phone || '').replace(/[^0-9+]/g, '');
                result.innerHTML = `
                    <h2>${esc(d.product)}</h2><p class="muted">${esc(d.pharmacy)} · ${esc(d.address)}</p>
                    <p><span class="badge badge-req-${esc(d.status)}">${esc(d.status_label)}</span></p>
                    ${d.note ? `<p><strong>Pharmacy note:</strong> ${esc(d.note)}</p>` : ''}
                    <dl class="kv"><dt>Requested</dt><dd>${esc(d.created_at)}</dd><dt>Expires</dt><dd>${esc(d.expires_at)}</dd>${d.responded_at ? `<dt>Responded</dt><dd>${esc(d.responded_at)}</dd>` : ''}</dl>
                    ${d.status === 'pending' ? '<p class="muted small">Waiting for the pharmacy… this page refreshes automatically. They may not respond — you can call them.</p>' : ''}
                    <p class="uncertain-note">ⓘ ${esc(d.notice)}</p>
                    <div class="card-actions"><a class="action-btn btn-call" href="tel:${phone}">📞 Call ${esc(d.phone)}</a><a class="action-btn" href="index.php">New search</a></div>`;
                if (d.status !== 'pending') stop();
            } catch (e) { result.innerHTML = '<div class="alert alert-err" role="alert">Could not load the request. Retrying…</div>'; }
        }
        function stop() { clearInterval(timer); }
        if (token) { load(); timer = setInterval(() => { if (!document.hidden) load(); }, 15000); }
    }

    /* ---------- Incorrect-information report ---------- */
    const rf = $('#report-form');
    if (rf) {
        const ta = $('#explanation'), cnt = $('#exp-count');
        ta.addEventListener('input', () => cnt.textContent = ta.value.length);
        rf.addEventListener('submit', async e => {
            e.preventDefault(); const err = $('#report-error'), ok = $('#report-ok'), btn = $('#report-btn');
            err.classList.add('hidden'); ok.classList.add('hidden'); btn.disabled = true;
            try {
                const cat = rf.querySelector('input[name=category]:checked');
                const d = await post('api/report_create.php', { pharmacy_id: +rf.dataset.pharmacy, inventory_id: +rf.dataset.inventory || null, category: cat ? cat.value : '', explanation: ta.value });
                ok.textContent = d.message; ok.classList.remove('hidden'); rf.reset(); cnt.textContent = '0';
            } catch (x) { err.textContent = x.message; err.classList.remove('hidden'); btn.disabled = false; }
        });
    }
})();
