<?php
require_once __DIR__ . '/inc/layout.php';
$pid = int_val($_GET['pharmacy'] ?? 0); $iid = int_val($_GET['inventory'] ?? 0);
$p = q("SELECT p.id, p.name FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.id = ? AND p.approval_status = 'approved' AND u.status = 'active'", [$pid])->fetch();
$item = null;
if ($p && $iid) $item = q('SELECT i.id, m.product_name, m.strength, m.dosage_form FROM inventory i JOIN medicines m ON m.id = i.medicine_id WHERE i.id = ? AND i.pharmacy_id = ?', [$iid, $pid])->fetch();

page_head('Report incorrect information');
page_nav('public');
page_banner('Report incorrect information', 'Help keep listings honest', 'pharmacy');
?>
    <main class="container dash-main narrow">
    <?php if (!$p): ?>
        <section class="panel"><h2>Choose a pharmacy first</h2><p class="muted">Open a pharmacy from the search results and use its “Report” button.</p><a class="btn btn-primary" href="index.php">Back to search</a></section>
    <?php else: ?>
        <section class="panel">
            <h2>Report about <?= h($p['name']) ?></h2>
            <?php if ($item): ?><p class="muted">Product: <strong><?= h(med_label($item)) ?></strong></p><?php endif; ?>
            <form id="report-form" data-pharmacy="<?= (int)$p['id'] ?>" data-inventory="<?= $item ? (int)$item['id'] : 0 ?>">
                <fieldset class="radio-group"><legend>What is incorrect?</legend>
                    <?php foreach (['stock' => 'Stock status is wrong', 'contact' => 'Phone / contact is wrong', 'address' => 'Address is wrong', 'closure' => 'Closed when listed open', 'misleading' => 'Misleading or duplicate listing'] as $k => $l): ?>
                        <label class="check"><input type="radio" name="category" value="<?= $k ?>" required> <?= h($l) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="form-group"><label for="explanation">Short explanation (optional)</label>
                    <textarea id="explanation" name="explanation" maxlength="300" rows="3" placeholder="Please don't include personal details."></textarea>
                    <small class="muted"><span id="exp-count">0</span>/300</small></div>
                <div class="alert alert-err hidden" id="report-error" role="alert"></div>
                <div class="alert alert-ok hidden" id="report-ok" role="status"></div>
                <button class="btn btn-primary" type="submit" id="report-btn">Submit report</button>
            </form>
            <p class="muted small" style="margin-top:1rem">A report does not change stock or suspend a pharmacy automatically. An administrator reviews it.</p>
        </section>
    <?php endif; ?>
    </main>
<?php page_foot(['js/public.js']); ?>
