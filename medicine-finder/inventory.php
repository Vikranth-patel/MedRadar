<?php
require_once __DIR__ . '/inc/layout.php';
[$u, $ph] = require_pharmacy();
$pid = (int)$ph['id']; $uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $back = ($_POST['back'] ?? '') === 'dashboard.php' ? 'dashboard.php' : 'inventory.php';

    if ($act === 'add') {
        $mid = int_val($_POST['medicine_id'] ?? 0); $st = $_POST['status'] ?? '';
        $med = q('SELECT id FROM medicines WHERE id = ? AND archived = 0', [$mid])->fetch();
        if (!$med || !isset(STATUSES[$st])) { flash('err', 'Please choose a catalogue product and a status.'); redirect('inventory.php'); }
        $confirmed = !empty($_POST['confirmed_now']) ? now() : null;
        try {
            q('INSERT INTO inventory (pharmacy_id, medicine_id, status, is_active, last_confirmed_at, created_at) VALUES (?,?,?,1,?,?)', [$pid, $mid, $st, $confirmed, now()]);
            log_stock($uid, (int)db()->lastInsertId(), 'add', null, $st . ($confirmed ? ' (confirmed)' : ' (unconfirmed)'));
            flash('ok', 'Listing added.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') flash('err', 'You already list this product. Edit the existing listing instead.');   // unique(pharmacy_id, medicine_id) — T11
            else throw $e;
        }
        redirect('inventory.php');
    }

    // Every other action targets ONE listing: it must belong to THIS pharmacy (ownership check — T06/T07)
    $iid = int_val($_POST['inventory_id'] ?? 0);
    $row = q('SELECT i.* FROM inventory i WHERE i.id = ? AND i.pharmacy_id = ?', [$iid, $pid])->fetch();
    if (!$row) deny(403, 'You can only change your own pharmacy’s listings.');

    switch ($act) {
        case 'set_status':
            $st = $_POST['status'] ?? '';
            if (!isset(STATUSES[$st])) { flash('err', 'Invalid status.'); break; }
            if ($st !== $row['status']) {
                q('UPDATE inventory SET status = ?, last_confirmed_at = ? WHERE id = ? AND pharmacy_id = ?', [$st, now(), $iid, $pid]);
                log_stock($uid, $iid, 'status_change', $row['status'], $st);
                flash('ok', 'Status updated and confirmed now.');
            }
            break;
        case 'confirm':
            if ($row['status'] === 'out_of_stock') { flash('err', 'This item is marked out of stock. Change its status first.'); break; }
            q('UPDATE inventory SET last_confirmed_at = ? WHERE id = ? AND pharmacy_id = ?', [now(), $iid, $pid]);
            log_stock($uid, $iid, 'reconfirm', $row['last_confirmed_at'], now());
            flash('ok', 'Confirmed as still available.');
            break;
        case 'out_of_stock':
            q("UPDATE inventory SET status = 'out_of_stock', last_confirmed_at = ? WHERE id = ? AND pharmacy_id = ?", [now(), $iid, $pid]);
            log_stock($uid, $iid, 'mark_out_of_stock', $row['status'], 'out_of_stock');
            flash('ok', 'Marked out of stock.');
            break;
        case 'deactivate':
        case 'activate':   // does NOT refresh the confirmation time
            $on = $act === 'activate' ? 1 : 0;
            q('UPDATE inventory SET is_active = ? WHERE id = ? AND pharmacy_id = ?', [$on, $iid, $pid]);
            log_stock($uid, $iid, $act, (string)$row['is_active'], (string)$on);
            flash('ok', $on ? 'Listing reactivated (confirmation time unchanged).' : 'Listing deactivated and hidden from search.');
            break;
        default: flash('err', 'Unknown action.');
    }
    redirect($back);
}

$f = $_GET['f'] ?? 'all';
$all = q('SELECT i.*, m.product_name, m.ingredient_name, m.strength, m.dosage_form, m.archived FROM inventory i JOIN medicines m ON m.id = i.medicine_id WHERE i.pharmacy_id = ? ORDER BY m.product_name, m.strength', [$pid])->fetchAll();
$rows = array_values(array_filter($all, function ($r) use ($f) {
    return match ($f) {
        'needs'    => needs_reconfirm($r),
        'in'       => $r['is_active'] && $r['status'] === 'in_stock',
        'low'      => $r['is_active'] && $r['status'] === 'low_stock',
        'out'      => $r['is_active'] && $r['status'] === 'out_of_stock',
        'inactive' => !$r['is_active'],
        default    => true,
    };
}));
$avail = q('SELECT * FROM medicines WHERE archived = 0 AND id NOT IN (SELECT medicine_id FROM inventory WHERE pharmacy_id = ?) ORDER BY product_name, strength', [$pid])->fetchAll();
$filters = ['all' => 'All', 'needs' => 'Needs reconfirming', 'in' => '✔ In stock', 'low' => '◐ Low', 'out' => '✖ Out', 'inactive' => 'Inactive'];

page_head('Inventory', ['ctx' => 'pharmacy']);
page_nav('pharmacy');
page_banner('Inventory', $ph['name'], 'pharmacy');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <section class="panel">
            <h2>+ List a catalogue product</h2>
            <?php if (!$avail): ?><p class="muted">You already list every product in the catalogue. Ask an administrator to add more.</p>
            <?php else: ?>
            <form method="post" class="add-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <div class="form-group" style="grid-column: span 2"><label for="medicine_id">Catalogue product</label>
                    <select id="medicine_id" name="medicine_id" required><option value="">Select a product…</option>
                        <?php foreach ($avail as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h(med_label($m)) ?> — <?= h($m['ingredient_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label for="status">Status</label>
                    <select id="status" name="status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= h($v[0] . ' ' . $v[1]) ?></option><?php endforeach; ?></select></div>
                <label class="check" style="align-self:center"><input type="checkbox" name="confirmed_now" value="1" checked> I checked the shelf just now</label>
                <button type="submit" class="btn btn-primary">Add listing</button>
            </form>
            <p class="muted small" style="margin-top:.6rem">Can't find a product? Only administrators can add catalogue entries — this keeps names, strengths and forms consistent.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="table-tools">
                <h2>Your listings (<?= count($rows) ?>)</h2>
                <input type="search" id="table-filter" placeholder="Filter by name…" aria-label="Filter listings">
            </div>
            <div class="chip-row"><?php foreach ($filters as $k => $l): ?><a class="chip <?= $f === $k ? 'active' : '' ?>" href="inventory.php?f=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?></div>
            <div class="table-wrap"><table class="data-table" id="stock-table">
                <thead><tr><th>Medicine</th><th>Status</th><th>Freshness</th><th>Timestamps</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="5" class="muted" style="text-align:center">No listings match this view.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): $fr = freshness($r); ?>
                    <tr class="<?= $r['is_active'] ? '' : 'row-inactive' ?>" data-search="<?= h(strtolower($r['product_name'] . ' ' . $r['ingredient_name'] . ' ' . $r['strength'])) ?>">
                        <td data-label="Medicine"><strong><?= h($r['product_name']) ?></strong><br><span class="muted small"><?= h($r['ingredient_name']) ?> · <?= h($r['strength']) ?> · <?= h($r['dosage_form']) ?></span><?= $r['archived'] ? '<br><span class="badge badge-ap-rejected">Archived in catalogue</span>' : '' ?><?= $r['is_active'] ? '' : '<br><span class="badge badge-ap-pending">Inactive</span>' ?></td>
                        <td data-label="Status"><span class="badge badge-<?= h(str_replace('_', '', $r['status'])) ?>"><?= h(status_text($r['status'])) ?></span></td>
                        <td data-label="Freshness"><span class="fresh fresh-<?= $fr['key'] ?>"><?= h($fr['label']) ?></span><br><span class="muted small"><?= h($r['last_confirmed_at'] ? ago($r['last_confirmed_at']) : '—') ?></span></td>
                        <td data-label="Timestamps" class="small muted">Created <?= h(fmt_dt($r['created_at'])) ?><br>Updated <?= h(fmt_dt($r['updated_at'])) ?><br>Confirmed <?= h(fmt_dt($r['last_confirmed_at'])) ?></td>
                        <td class="actions-cell" data-label="Actions">
                            <div class="row-actions">
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>">
                                    <select name="status" aria-label="New status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($v[0] . ' ' . $v[1]) ?></option><?php endforeach; ?></select>
                                    <button class="btn btn-ghost btn-sm" title="Changing the status also confirms it now">Save</button></form>
                                <?php if ($r['is_active'] && $r['status'] !== 'out_of_stock'): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-primary btn-sm">✔ Still available</button></form>
                                    <form method="post" data-confirm="Mark this item as out of stock?"><?= csrf_field() ?><input type="hidden" name="action" value="out_of_stock"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger btn-sm">✖ Out of stock</button></form>
                                <?php endif; ?>
                                <form method="post" <?= $r['is_active'] ? 'data-confirm="Deactivate this listing? It will be hidden from search."' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $r['is_active'] ? 'deactivate' : 'activate' ?>"><input type="hidden" name="inventory_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm"><?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </section>
    </main>
<?php page_foot(); ?>
