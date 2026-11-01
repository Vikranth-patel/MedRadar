<?php
define('BASE', '../');
require_once __DIR__ . '/../inc/layout.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pid = int_val($_POST['pharmacy_id'] ?? 0); $act = $_POST['action'] ?? ''; $reason = clean($_POST['reason'] ?? '', 250);
    $p = q('SELECT * FROM pharmacies WHERE id = ?', [$pid])->fetch();
    // Workflow: pending → approved|rejected ; approved → suspended ; suspended → approved (reactivate)
    $flow = ['approve' => ['pending', 'approved'], 'reject' => ['pending', 'rejected'], 'suspend' => ['approved', 'suspended'], 'reactivate' => ['suspended', 'approved']];
    if (!$p || !isset($flow[$act])) { flash('err', 'Invalid request.'); }
    elseif ($p['approval_status'] !== $flow[$act][0]) { flash('err', 'That action is not allowed from the current status (' . $p['approval_status'] . ').'); }
    elseif (in_array($act, ['reject', 'suspend'], true) && $reason === '') { flash('err', 'Please enter a reason — it is recorded and shown to the pharmacy.'); }
    else {
        $to = $flow[$act][1];
        q('UPDATE pharmacies SET approval_status = ?, review_reason = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$to, $reason !== '' ? $reason : null, $u['id'], now(), $pid]);
        q('UPDATE users SET status = ? WHERE id = ?', [$to === 'suspended' ? 'suspended' : 'active', $p['owner_user_id']]);
        log_admin((int)$u['id'], 'pharmacy', $pid, $act, $reason !== '' ? $reason : null);
        flash('ok', ucfirst($act) . ' recorded for ' . $p['name'] . '.');
    }
    redirect('pharmacies.php' . (!empty($_POST['f']) ? '?f=' . urlencode($_POST['f']) : ''));
}

$f = $_GET['f'] ?? 'pending';
$valid = ['pending', 'approved', 'rejected', 'suspended', 'all'];
if (!in_array($f, $valid, true)) $f = 'pending';
$counts = array_column(q('SELECT approval_status, COUNT(*) c FROM pharmacies GROUP BY approval_status')->fetchAll(), 'c', 'approval_status');
$rows = $f === 'all'
    ? q('SELECT p.*, u.email, u.status AS user_status FROM pharmacies p JOIN users u ON u.id = p.owner_user_id ORDER BY p.created_at DESC')->fetchAll()
    : q('SELECT p.*, u.email, u.status AS user_status FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.approval_status = ? ORDER BY p.created_at DESC', [$f])->fetchAll();

page_head('Pharmacy review', ['ctx' => 'admin']);
page_nav('admin');
page_banner('Pharmacy review', 'Approve, reject, suspend or reactivate', 'admin');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <div class="chip-row">
            <?php foreach ($valid as $k): ?><a class="chip <?= $f === $k ? 'active' : '' ?>" href="pharmacies.php?f=<?= $k ?>"><?= h(ucfirst($k)) ?><?= $k !== 'all' ? ' (' . (int)($counts[$k] ?? 0) . ')' : '' ?></a><?php endforeach; ?>
        </div>
        <?php if (!$rows): ?><section class="panel"><p class="muted">No pharmacies in this view.</p></section><?php endif; ?>
        <?php foreach ($rows as $p):
            $hist = q("SELECT e.*, a.email FROM admin_events e LEFT JOIN users a ON a.id = e.actor_id WHERE e.affected_type = 'pharmacy' AND e.affected_id = ? ORDER BY e.id DESC LIMIT 10", [$p['id']])->fetchAll();
            $invCount = (int) q('SELECT COUNT(*) FROM inventory WHERE pharmacy_id = ?', [$p['id']])->fetchColumn(); ?>
        <section class="panel">
            <div class="table-tools"><h2><?= h($p['name']) ?> <?= approval_badge($p['approval_status']) ?></h2><span class="muted small">Registered <?= h(fmt_dt($p['created_at'])) ?></span></div>
            <dl class="kv">
                <dt>Contact</dt><dd><?= h($p['contact_person']) ?> · <?= h($p['phone']) ?> · <?= h($p['email']) ?></dd>
                <dt>Address</dt><dd><?= h($p['address']) ?>, <?= h($p['locality']) ?> <?= h($p['pin_code']) ?></dd>
                <dt>Listings</dt><dd><?= $invCount ?></dd>
                <?php if ($p['review_reason']): ?><dt>Last reason</dt><dd><?= h($p['review_reason']) ?></dd><?php endif; ?>
            </dl>
            <?php
            $actions = ['pending' => ['approve', 'reject'], 'approved' => ['suspend'], 'suspended' => ['reactivate'], 'rejected' => []][$p['approval_status']];
            if ($actions): ?>
            <form method="post" class="row-actions" data-confirm="Record this decision?"><?= csrf_field() ?><input type="hidden" name="pharmacy_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>">
                <input type="text" name="reason" maxlength="250" placeholder="Reason (required for reject / suspend)" aria-label="Reason" style="flex:1;min-width:200px">
                <?php foreach ($actions as $a): ?><button class="btn <?= in_array($a, ['reject', 'suspend']) ? 'btn-danger' : 'btn-primary' ?> btn-sm" name="action" value="<?= $a ?>"><?= h(ucfirst($a)) ?></button><?php endforeach; ?>
            </form>
            <?php else: ?><p class="muted small">Rejected pharmacies can edit their profile and resubmit for review.</p><?php endif; ?>
            <?php if ($hist): ?><details class="small" style="margin-top:.8rem"><summary>Decision history</summary><ul class="plain-list">
                <?php foreach ($hist as $e): ?><li><?= h(fmt_dt($e['created_at'])) ?> — <strong><?= h($e['action']) ?></strong> by <?= h($e['email'] ?? 'system') ?><?= $e['details'] ? ' — ' . h($e['details']) : '' ?></li><?php endforeach; ?></ul></details><?php endif; ?>
        </section>
        <?php endforeach; ?>
    </main>
<?php page_foot(); ?>
