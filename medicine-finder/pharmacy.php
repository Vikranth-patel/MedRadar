<?php
require_once __DIR__ . '/inc/layout.php';
$id = int_val($_GET['id'] ?? 0);
// Only approved pharmacies with an active account are public (T05)
$p = q("SELECT p.* FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.id = ? AND p.approval_status = 'approved' AND u.status = 'active'", [$id])->fetch();
if (!$p) deny(404, 'This pharmacy page is not available.');

$items = q("SELECT i.id, i.status, i.last_confirmed_at, i.is_active, m.product_name, m.ingredient_name, m.strength, m.dosage_form
            FROM inventory i JOIN medicines m ON m.id = i.medicine_id
            WHERE i.pharmacy_id = ? AND i.is_active = 1 AND m.archived = 0 ORDER BY m.product_name, m.strength", [$id])->fetchAll();
$hours = hours_decode($p['hours']); $today = strtolower(date('D')); $t = pharmacy_today($p);
$maps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($p['name'] . ', ' . $p['address'] . ', ' . $p['locality'] . ' ' . $p['pin_code']);

page_head($p['name'], ['desc' => 'Products reported by ' . $p['name'] . ' and when each was last confirmed.']);
page_nav('public');
page_banner($p['name'], $p['locality'] . ' · ' . $p['pin_code'], 'pharmacy');
?>
    <main class="container dash-main">
        <p class="uncertain-note">ⓘ Availability is <strong>pharmacy-reported</strong> and can change. Confirm before travelling. A confirmation request is <strong>not a reservation</strong>.</p>

        <div class="grid-2">
            <section class="panel">
                <h2>Contact &amp; location</h2>
                <p><?= approval_badge('approved') ?></p>
                <p class="muted small"><strong>Admin-approved</strong> means an administrator reviewed this pharmacy’s listing. It is not a professional licence verification.</p>
                <dl class="kv">
                    <dt>Address</dt><dd><?= h($p['address']) ?>, <?= h($p['locality']) ?> <?= h($p['pin_code']) ?></dd>
                    <dt>Phone</dt><dd><?= h($p['phone']) ?></dd>
                    <dt>Today</dt><dd class="<?= $t['closed'] ? 'text-bad' : '' ?>"><?= h($t['text']) ?></dd>
                </dl>
                <div class="card-actions">
                    <a class="action-btn btn-call" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>">📞 Call</a>
                    <a class="action-btn" href="<?= h($maps) ?>" target="_blank" rel="noopener noreferrer">📍 Directions ↗</a>
                    <a class="action-btn wide" href="report.php?pharmacy=<?= (int)$p['id'] ?>">⚑ Report incorrect information</a>
                </div>
            </section>
            <section class="panel">
                <h2>Opening hours</h2>
                <table class="hours-table"><tbody>
                <?php foreach (DAYS as $k => $name): ?>
                    <tr class="<?= $k === $today ? 'today' : '' ?>"><th scope="row"><?= h($name) ?></th><td><?= h(hours_text($hours[$k])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php if (!empty($p['closed_until']) && $p['closed_until'] >= date('Y-m-d')): ?>
                    <p class="alert alert-warn" style="margin-top:1rem">Temporarily closed until <?= h(date('j M Y', strtotime($p['closed_until']))) ?><?= $p['closure_note'] ? ' — ' . h($p['closure_note']) : '' ?></p>
                <?php endif; ?>
            </section>
        </div>

        <section class="panel">
            <h2>Products listed by this pharmacy</h2>
            <?php if (!$items): ?>
                <p class="muted">This pharmacy has no active listings right now.</p>
            <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($items as $it): $f = freshness($it); ?>
                <article class="card">
                    <div>
                        <h3 class="drug-title"><?= h($it['product_name']) ?></h3>
                        <div class="drug-specs"><?= h($it['ingredient_name']) ?> · <?= h($it['strength']) ?> · <?= h($it['dosage_form']) ?></div>
                        <p><?= badges($it) ?></p>
                        <p class="verification-note"><span>🕒</span><?= h($f['detail']) ?></p>
                    </div>
                    <div class="card-actions">
                        <button type="button" class="action-btn btn-call" data-request="<?= (int)$it['id'] ?>" data-product="<?= h(med_label($it)) ?>" data-pharmacy="<?= h($p['name']) ?>">✉ Request confirmation</button>
                        <a class="action-btn" href="report.php?pharmacy=<?= (int)$p['id'] ?>&amp;inventory=<?= (int)$it['id'] ?>">⚑ Report</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
<?php request_dialog(); page_foot(['js/public.js']); ?>
