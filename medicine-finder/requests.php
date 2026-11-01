<?php
require_once __DIR__ . '/inc/layout.php';
[$u, $ph] = require_pharmacy();
$pid = (int)$ph['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $rid = int_val($_POST['request_id'] ?? 0);
    $resp = ['available' => 'confirmed_available', 'unavailable' => 'confirmed_unavailable', 'cannot_confirm' => 'cannot_confirm'][$_POST['response'] ?? ''] ?? null;
    $note = clean($_POST['note'] ?? '', 200);
    // Ownership: the request must belong to an inventory row of THIS pharmacy
    $r = q('SELECT r.* FROM availability_requests r JOIN inventory i ON i.id = r.inventory_id WHERE r.id = ? AND i.pharmacy_id = ?', [$rid, $pid])->fetch();
    if (!$r) deny(403, 'You can only respond to your own pharmacy’s requests.');
    if (!$resp) { flash('err', 'Please choose a response.'); redirect('requests.php'); }
    $eff = effective_request_status($r);
    if ($eff !== 'pending') { flash('err', $eff === 'expired' ? 'This request has expired and can no longer be answered.' : 'This request was already answered.'); redirect('requests.php'); }
    $n = q("UPDATE availability_requests SET status = ?, response_note = ?, responded_at = ? WHERE id = ? AND status = 'pending' AND expires_at > ?", [$resp, $note !== '' ? $note : null, now(), $rid, now()])->rowCount();
    if ($n) { log_stock((int)$u['id'], (int)$r['inventory_id'], 'request_response', 'pending', $resp); flash('ok', 'Response sent. Remember: this is not a reservation.'); }
    else flash('err', 'This request has just expired.');
    redirect('requests.php');
}

$all = q('SELECT r.id, r.status, r.response_note, r.created_at, r.expires_at, r.responded_at, m.product_name, m.strength, m.dosage_form
          FROM availability_requests r JOIN inventory i ON i.id = r.inventory_id JOIN medicines m ON m.id = i.medicine_id
          WHERE i.pharmacy_id = ? ORDER BY r.id DESC LIMIT 100', [$pid])->fetchAll();
$pending = []; $done = [];
foreach ($all as $r) {
    $r['eff'] = effective_request_status($r);
    if ($r['eff'] === 'pending') $pending[] = $r; else $done[] = $r;
}

page_head('Confirmation requests', ['ctx' => 'pharmacy']);
page_nav('pharmacy');
page_banner('Confirmation requests', $ph['name'], 'pharmacy');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <section class="panel">
            <h2>Pending (<?= count($pending) ?>)</h2>
            <p class="muted small">Visitors ask you to check <strong>one product</strong>. Requests contain no personal details. Your reply is not a reservation. Requests expire after <?= REQUEST_TTL_HOURS ?> hours.</p>
            <?php if (!$pending): ?><p class="muted">No pending requests right now.</p><?php endif; ?>
            <div class="cards-grid">
            <?php foreach ($pending as $r): ?>
                <article class="card">
                    <div><h3 class="drug-title"><?= h($r['product_name']) ?></h3>
                        <div class="drug-specs"><?= h($r['strength']) ?> · <?= h($r['dosage_form']) ?></div>
                        <p class="muted small">Requested <?= h(ago($r['created_at'])) ?> · expires <?= h(fmt_dt($r['expires_at'])) ?></p></div>
                    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <label for="resp<?= (int)$r['id'] ?>" class="sr-only">Response</label>
                        <select id="resp<?= (int)$r['id'] ?>" name="response" required><option value="">Choose a response…</option><option value="available">✔ Available</option><option value="unavailable">✖ Unavailable</option><option value="cannot_confirm">? Cannot confirm</option></select>
                        <input type="text" name="note" maxlength="200" placeholder="Optional note (e.g. 2 strips left)" aria-label="Optional note">
                        <button class="btn btn-primary btn-block" type="submit">Send response</button></form>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <h2>Answered &amp; expired</h2>
            <?php if (!$done): ?><p class="muted">Nothing here yet.</p><?php else: ?>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Status</th><th>Requested</th><th>Responded</th><th>Note</th></tr></thead><tbody>
            <?php foreach ($done as $r): ?>
                <tr><td data-label="Product"><?= h(med_label($r)) ?></td><td data-label="Status"><span class="badge badge-req-<?= h($r['eff']) ?>"><?= h(REQ_LABELS[$r['eff']]) ?></span></td>
                    <td data-label="Requested"><?= h(fmt_dt($r['created_at'])) ?></td><td data-label="Responded"><?= h(fmt_dt($r['responded_at'])) ?></td><td data-label="Note"><?= h($r['response_note'] ?? '—') ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
    </main>
<?php page_foot(); ?>
