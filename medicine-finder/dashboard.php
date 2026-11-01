<?php
require_once __DIR__ . '/inc/layout.php';
[$u, $ph] = require_pharmacy();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'resubmit' && $ph['approval_status'] === 'rejected') {
        q("UPDATE pharmacies SET approval_status = 'pending', review_reason = NULL WHERE id = ? AND owner_user_id = ?", [$ph['id'], $u['id']]);
        log_admin((int)$u['id'], 'pharmacy', (int)$ph['id'], 'resubmit', 'Resubmitted for review');
        flash('ok', 'Resubmitted. An administrator will review your pharmacy again.');
    }
    redirect('dashboard.php');
}

$rows = q('SELECT i.*, m.product_name, m.strength, m.dosage_form FROM inventory i JOIN medicines m ON m.id = i.medicine_id WHERE i.pharmacy_id = ? ORDER BY m.product_name', [$ph['id']])->fetchAll();
$c = ['total' => 0, 'in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'needs' => 0];
$needs = [];
foreach ($rows as $r) {
    if (!$r['is_active']) continue;
    $c['total']++; $c[$r['status']]++;
    if (needs_reconfirm($r)) { $c['needs']++; $needs[] = $r; }
}
$pend = (int) q("SELECT COUNT(*) FROM availability_requests r JOIN inventory i ON i.id = r.inventory_id WHERE i.pharmacy_id = ? AND r.status = 'pending' AND r.expires_at > ?", [$ph['id'], now()])->fetchColumn();

page_head('Dashboard', ['ctx' => 'pharmacy']);
page_nav('pharmacy');
page_banner($ph['name'], 'Pharmacy dashboard', 'pharmacy', $ph['approval_status'] === 'approved' ? '<a href="pharmacy.php?id=' . (int)$ph['id'] . '" target="_blank" rel="noopener" class="btn btn-light btn-sm">View public page ↗</a>' : '');
?>
    <main class="container dash-main">
        <?php flash_render(); approval_notice($ph); ?>
        <div class="stat-row">
            <div class="stat"><strong data-count="<?= $c['total'] ?>">0</strong><span>Active listings</span></div>
            <div class="stat ok"><strong data-count="<?= $c['in_stock'] ?>">0</strong><span>✔ In stock</span></div>
            <div class="stat warn"><strong data-count="<?= $c['low_stock'] ?>">0</strong><span>◐ Low stock</span></div>
            <div class="stat bad"><strong data-count="<?= $c['out_of_stock'] ?>">0</strong><span>✖ Out of stock</span></div>
            <div class="stat warn"><strong data-count="<?= $c['needs'] ?>">0</strong><span>Need reconfirming</span></div>
            <div class="stat"><strong data-count="<?= $pend ?>">0</strong><span><a href="requests.php">Pending requests</a></span></div>
        </div>

        <section class="panel">
            <h2>Items needing reconfirmation <small class="muted">(not confirmed in the last <?= FRESH_HOURS ?> h)</small></h2>
            <?php if (!$needs): ?>
                <p class="muted">✔ All active items are recently confirmed. Nice work.</p>
            <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Medicine</th><th>Status</th><th>Last confirmed</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($needs as $r): ?>
                    <tr>
                        <td data-label="Medicine"><strong><?= h($r['product_name']) ?></strong> <span class="muted"><?= h($r['strength']) ?> · <?= h($r['dosage_form']) ?></span></td>
                        <td data-label="Status"><span class="badge badge-<?= h(str_replace('_', '', $r['status'])) ?>"><?= h(status_text($r['status'])) ?></span></td>
                        <td data-label="Last confirmed"><?= $r['last_confirmed_at'] ? h(ago($r['last_confirmed_at'])) : 'Never' ?></td>
                        <td class="actions-cell" data-label="Action">
                            <div class="row-actions">
                                <form method="post" action="inventory.php"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="dashboard.php"><button class="btn btn-primary btn-sm">✔ Still available</button></form>
                                <form method="post" action="inventory.php" data-confirm="Mark this item as out of stock?"><?= csrf_field() ?><input type="hidden" name="action" value="out_of_stock"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="dashboard.php"><button class="btn btn-danger btn-sm">✖ Out of stock</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <p class="muted small">“Confirm still available” and “Mark out of stock” are the only actions that refresh an item’s confirmation time. Profile edits never do.</p>
    </main>
<?php page_foot(); ?>
