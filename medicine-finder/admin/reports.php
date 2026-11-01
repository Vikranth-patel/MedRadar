<?php
define('BASE', '../');
require_once __DIR__ . '/../inc/layout.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $rid = int_val($_POST['report_id'] ?? 0); $note = clean($_POST['note'] ?? '', 250);
    $to = ['resolve' => 'resolved', 'dismiss' => 'dismissed', 'reopen' => 'open'][$_POST['action'] ?? ''] ?? null;
    if (!$to || !q('SELECT 1 FROM information_reports WHERE id = ?', [$rid])->fetch()) flash('err', 'Invalid request.');
    else {
        // A report never changes stock or suspends a pharmacy by itself — it only records the admin's review.
        q('UPDATE information_reports SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$to, $note !== '' ? $note : null, $u['id'], now(), $rid]);
        log_admin((int)$u['id'], 'report', $rid, 'report_' . $to, $note !== '' ? $note : null);
        flash('ok', 'Report marked ' . $to . '.');
    }
    redirect('reports.php' . (!empty($_POST['f']) ? '?f=' . urlencode($_POST['f']) : ''));
}
$f = $_GET['f'] ?? 'open'; if (!in_array($f, ['open', 'resolved', 'dismissed', 'all'], true)) $f = 'open';
$sql = "SELECT r.*, p.name AS pharmacy_name, m.product_name, m.strength, m.dosage_form
        FROM information_reports r JOIN pharmacies p ON p.id = r.pharmacy_id LEFT JOIN inventory i ON i.id = r.inventory_id LEFT JOIN medicines m ON m.id = i.medicine_id"
     . ($f === 'all' ? '' : ' WHERE r.status = ?') . ' ORDER BY r.id DESC LIMIT 200';
$rows = q($sql, $f === 'all' ? [] : [$f])->fetchAll();
$cat = ['stock' => 'Incorrect stock', 'contact' => 'Incorrect contact', 'address' => 'Incorrect address', 'closure' => 'Closure', 'misleading' => 'Misleading / duplicate'];

page_head('Reports', ['ctx' => 'admin']);
page_nav('admin');
page_banner('Incorrect-information reports', 'Review, resolve or dismiss', 'admin');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <div class="chip-row"><?php foreach (['open', 'resolved', 'dismissed', 'all'] as $k): ?><a class="chip <?= $f === $k ? 'active' : '' ?>" href="reports.php?f=<?= $k ?>"><?= h(ucfirst($k)) ?></a><?php endforeach; ?></div>
        <?php if (!$rows): ?><section class="panel"><p class="muted">No reports in this view.</p></section><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <section class="panel">
            <div class="table-tools"><h2><?= h($cat[$r['category']]) ?> — <?= h($r['pharmacy_name']) ?></h2><span class="badge badge-rep-<?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span></div>
            <p class="muted small">Submitted <?= h(fmt_dt($r['created_at'])) ?><?= $r['product_name'] ? ' · Product: ' . h(med_label($r)) : '' ?></p>
            <p><?= $r['explanation'] ? h($r['explanation']) : '<em class="muted">No explanation given.</em>' ?></p>
            <?php if ($r['admin_note']): ?><p class="muted small">Admin note: <?= h($r['admin_note']) ?> (<?= h(fmt_dt($r['reviewed_at'])) ?>)</p><?php endif; ?>
            <form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>">
                <input type="text" name="note" maxlength="250" placeholder="Note (optional)" aria-label="Note" style="flex:1;min-width:200px">
                <?php if ($r['status'] === 'open'): ?>
                    <button class="btn btn-primary btn-sm" name="action" value="resolve">Resolve</button><button class="btn btn-ghost btn-sm" name="action" value="dismiss">Dismiss</button>
                <?php else: ?><button class="btn btn-ghost btn-sm" name="action" value="reopen">Reopen</button><?php endif; ?>
            </form>
        </section>
        <?php endforeach; ?>
    </main>
<?php page_foot(); ?>
