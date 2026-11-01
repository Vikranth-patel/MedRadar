<?php
define('BASE', '../');
require_once __DIR__ . '/../inc/layout.php';
$u = require_role('admin');
$t = ($_GET['t'] ?? 'admin') === 'stock' ? 'stock' : 'admin';
if ($t === 'admin') {
    $rows = q('SELECT e.*, a.email FROM admin_events e LEFT JOIN users a ON a.id = e.actor_id ORDER BY e.id DESC LIMIT 200')->fetchAll();
} else {
    $rows = q('SELECT e.*, a.email, p.name AS pharmacy_name, m.product_name, m.strength, m.dosage_form
               FROM stock_events e LEFT JOIN users a ON a.id = e.actor_id LEFT JOIN inventory i ON i.id = e.inventory_id
               LEFT JOIN pharmacies p ON p.id = i.pharmacy_id LEFT JOIN medicines m ON m.id = i.medicine_id ORDER BY e.id DESC LIMIT 200')->fetchAll();
}
page_head('Administrative history', ['ctx' => 'admin']);
page_nav('admin');
page_banner('Administrative history', 'Who did what, and when', 'admin');
?>
    <main class="container dash-main">
        <div class="chip-row"><a class="chip <?= $t === 'admin' ? 'active' : '' ?>" href="history.php?t=admin">Admin decisions &amp; catalogue</a><a class="chip <?= $t === 'stock' ? 'active' : '' ?>" href="history.php?t=stock">Inventory &amp; request events</a></div>
        <section class="panel"><h2>Latest 200 events</h2>
        <div class="table-wrap"><table class="data-table"><thead>
            <?php if ($t === 'admin'): ?><tr><th>When</th><th>Actor</th><th>Record</th><th>Action</th><th>Details</th></tr>
            <?php else: ?><tr><th>When</th><th>Actor</th><th>Pharmacy / product</th><th>Action</th><th>Before → After</th></tr><?php endif; ?></thead><tbody>
        <?php foreach ($rows as $e): ?>
            <?php if ($t === 'admin'): ?>
                <tr><td data-label="When"><?= h(fmt_dt($e['created_at'])) ?></td><td data-label="Actor"><?= h($e['email'] ?? 'system') ?></td><td data-label="Record"><?= h($e['affected_type']) ?> #<?= (int)$e['affected_id'] ?></td><td data-label="Action"><?= h($e['action']) ?></td><td data-label="Details"><?= h($e['details'] ?? '—') ?></td></tr>
            <?php else: ?>
                <tr><td data-label="When"><?= h(fmt_dt($e['created_at'])) ?></td><td data-label="Actor"><?= h($e['email'] ?? 'visitor/system') ?></td><td data-label="Item"><?= h($e['pharmacy_name'] ?? '—') ?><br><span class="muted small"><?= $e['product_name'] ? h(med_label($e)) : '' ?></span></td><td data-label="Action"><?= h($e['action']) ?></td><td data-label="Change"><?= h($e['before_value'] ?? '—') ?> → <?= h($e['after_value'] ?? '—') ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody></table></div></section>
        <p class="muted small">Logs never contain passwords, session identifiers or private request tokens.</p>
    </main>
<?php page_foot(); ?>
