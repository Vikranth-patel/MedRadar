<?php
define('BASE', '../');
require_once __DIR__ . '/../inc/layout.php';
$u = require_role('admin');

function med_input(): array {
    return ['product_name' => clean($_POST['product_name'] ?? '', 120), 'ingredient_name' => clean($_POST['ingredient_name'] ?? '', 120),
            'strength' => clean($_POST['strength'] ?? '', 40), 'dosage_form' => clean($_POST['dosage_form'] ?? '', 40), 'manufacturer' => clean($_POST['manufacturer'] ?? '', 100)];
}
function med_duplicate(array $d, int $exceptId = 0): bool {
    return (bool) q('SELECT 1 FROM medicines WHERE LOWER(product_name) = LOWER(?) AND LOWER(strength) = LOWER(?) AND LOWER(dosage_form) = LOWER(?) AND id <> ?',
                    [$d['product_name'], $d['strength'], $d['dosage_form'], $exceptId])->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? ''; $mid = int_val($_POST['medicine_id'] ?? 0);
    try {
        if ($act === 'add' || $act === 'edit') {
            $d = med_input();
            if ($d['product_name'] === '' || $d['ingredient_name'] === '' || $d['strength'] === '' || $d['dosage_form'] === '') flash('err', 'Product, ingredient, strength and form are required.');
            elseif (med_duplicate($d, $act === 'edit' ? $mid : 0)) flash('err', 'This exact product (name + strength + form) already exists in the catalogue.');
            elseif ($act === 'add') {
                q('INSERT INTO medicines (product_name, ingredient_name, strength, dosage_form, manufacturer, created_at) VALUES (?,?,?,?,?,?)', [$d['product_name'], $d['ingredient_name'], $d['strength'], $d['dosage_form'], $d['manufacturer'] ?: null, now()]);
                log_admin((int)$u['id'], 'medicine', (int)db()->lastInsertId(), 'catalogue_add', med_label($d));
                flash('ok', 'Catalogue entry added.');
            } else {
                if (!q('SELECT 1 FROM medicines WHERE id = ?', [$mid])->fetch()) { flash('err', 'Entry not found.'); }
                else {
                    q('UPDATE medicines SET product_name = ?, ingredient_name = ?, strength = ?, dosage_form = ?, manufacturer = ? WHERE id = ?', [$d['product_name'], $d['ingredient_name'], $d['strength'], $d['dosage_form'], $d['manufacturer'] ?: null, $mid]);
                    log_admin((int)$u['id'], 'medicine', $mid, 'catalogue_edit', med_label($d));
                    flash('ok', 'Catalogue entry updated.');
                }
            }
        } elseif ($act === 'archive' || $act === 'restore') {
            $a = $act === 'archive' ? 1 : 0;
            q('UPDATE medicines SET archived = ? WHERE id = ?', [$a, $mid]);   // never deleted: history & listings stay interpretable
            log_admin((int)$u['id'], 'medicine', $mid, 'catalogue_' . $act, null);
            flash('ok', $a ? 'Archived: hidden from search and new listings; history preserved.' : 'Restored to the catalogue.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') flash('err', 'This exact product already exists in the catalogue.'); else throw $e;
    }
    redirect('catalogue.php' . (!empty($_POST['show']) ? '?show=' . urlencode($_POST['show']) : ''));
}

$show = $_GET['show'] ?? 'active';
$where = $show === 'archived' ? 'WHERE m.archived = 1' : ($show === 'all' ? '' : 'WHERE m.archived = 0');
$rows = q("SELECT m.*, (SELECT COUNT(*) FROM inventory i WHERE i.medicine_id = m.id) AS listings FROM medicines m $where ORDER BY m.product_name, m.strength")->fetchAll();
$edit = isset($_GET['edit']) ? q('SELECT * FROM medicines WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;

page_head('Medicine catalogue', ['ctx' => 'admin']);
page_nav('admin');
page_banner('Medicine catalogue', 'One consistent list pharmacies choose from', 'admin');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <section class="panel">
            <h2><?= $edit ? 'Edit entry #' . (int)$edit['id'] : '+ Add catalogue entry' ?></h2>
            <form method="post" class="add-form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>"><input type="hidden" name="medicine_id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="form-group"><label for="c-name">Product / brand</label><input type="text" id="c-name" name="product_name" value="<?= h($edit['product_name'] ?? '') ?>" maxlength="120" required></div>
                <div class="form-group"><label for="c-ing">Ingredient</label><input type="text" id="c-ing" name="ingredient_name" value="<?= h($edit['ingredient_name'] ?? '') ?>" maxlength="120" required></div>
                <div class="form-group"><label for="c-str">Strength</label><input type="text" id="c-str" name="strength" value="<?= h($edit['strength'] ?? '') ?>" maxlength="40" placeholder="e.g. 650 mg" required></div>
                <div class="form-group"><label for="c-form">Dosage form</label><input type="text" id="c-form" name="dosage_form" value="<?= h($edit['dosage_form'] ?? '') ?>" maxlength="40" placeholder="e.g. Tablet" required></div>
                <div class="form-group"><label for="c-man">Manufacturer (optional)</label><input type="text" id="c-man" name="manufacturer" value="<?= h($edit['manufacturer'] ?? '') ?>" maxlength="100"></div>
                <div class="row-actions"><button class="btn btn-primary" type="submit"><?= $edit ? 'Save changes' : 'Add entry' ?></button><?php if ($edit): ?><a class="btn btn-ghost" href="catalogue.php">Cancel</a><?php endif; ?></div>
            </form>
            <p class="muted small" style="margin-top:.6rem">Same name with a different strength or form is a <strong>separate</strong> product. Exact duplicates are blocked.</p>
        </section>
        <section class="panel">
            <div class="table-tools"><h2>Entries (<?= count($rows) ?>)</h2><input type="search" id="table-filter" placeholder="Filter…" aria-label="Filter catalogue"></div>
            <div class="chip-row"><?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $l): ?><a class="chip <?= $show === $k ? 'active' : '' ?>" href="catalogue.php?show=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
            <div class="table-wrap"><table class="data-table" id="stock-table"><thead><tr><th>Product</th><th>Ingredient</th><th>Strength</th><th>Form</th><th>Listings</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($rows as $m): ?>
                <tr data-search="<?= h(strtolower($m['product_name'] . ' ' . $m['ingredient_name'] . ' ' . $m['strength'])) ?>" class="<?= $m['archived'] ? 'row-inactive' : '' ?>">
                    <td data-label="Product"><strong><?= h($m['product_name']) ?></strong><?= $m['archived'] ? ' <span class="badge badge-ap-rejected">Archived</span>' : '' ?></td>
                    <td data-label="Ingredient"><?= h($m['ingredient_name']) ?></td><td data-label="Strength"><?= h($m['strength']) ?></td><td data-label="Form"><?= h($m['dosage_form']) ?></td>
                    <td data-label="Listings"><?= (int)$m['listings'] ?></td>
                    <td class="actions-cell" data-label="Actions"><div class="row-actions">
                        <a class="btn btn-ghost btn-sm" href="catalogue.php?edit=<?= (int)$m['id'] ?>">Edit</a>
                        <form method="post" <?= $m['archived'] ? '' : 'data-confirm="Archive this entry? It will be hidden from search; existing history stays."' ?>><?= csrf_field() ?><input type="hidden" name="medicine_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="show" value="<?= h($show) ?>">
                            <button class="btn <?= $m['archived'] ? 'btn-primary' : 'btn-danger' ?> btn-sm" name="action" value="<?= $m['archived'] ? 'restore' : 'archive' ?>"><?= $m['archived'] ? 'Restore' : 'Archive' ?></button></form>
                    </div></td></tr>
            <?php endforeach; ?></tbody></table></div>
        </section>
    </main>
<?php page_foot(); ?>
