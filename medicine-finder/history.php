<?php
require_once __DIR__ . '/inc/layout.php';
[$u, $ph] = require_pharmacy();
$ev = q('SELECT e.*, m.product_name, m.strength, m.dosage_form, a.email AS actor
        FROM stock_events e JOIN inventory i ON i.id = e.inventory_id JOIN medicines m ON m.id = i.medicine_id LEFT JOIN users a ON a.id = e.actor_id
        WHERE i.pharmacy_id = ? ORDER BY e.id DESC LIMIT 200', [$ph['id']])->fetchAll();
$names = ['add' => 'Listing added', 'status_change' => 'Status changed', 'reconfirm' => 'Reconfirmed', 'mark_out_of_stock' => 'Marked out of stock', 'deactivate' => 'Deactivated', 'activate' => 'Reactivated', 'request_response' => 'Request answered'];
page_head('Inventory history', ['ctx' => 'pharmacy']);
page_nav('pharmacy');
page_banner('Inventory history', $ph['name'], 'pharmacy');
?>
    <main class="container dash-main">
        <section class="panel">
            <h2>Latest 200 events</h2>
            <?php if (!$ev): ?><p class="muted">No activity yet.</p><?php else: ?>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>When</th><th>Product</th><th>Action</th><th>Before → After</th><th>By</th></tr></thead><tbody>
            <?php foreach ($ev as $e): ?>
                <tr><td data-label="When"><?= h(fmt_dt($e['created_at'])) ?></td><td data-label="Product"><?= h(med_label($e)) ?></td><td data-label="Action"><?= h($names[$e['action']] ?? $e['action']) ?></td>
                    <td data-label="Change"><?= h($e['before_value'] ?? '—') ?> → <?= h($e['after_value'] ?? '—') ?></td><td data-label="By" class="muted small"><?= h($e['actor'] ?? '—') ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
    </main>
<?php page_foot(); ?>
