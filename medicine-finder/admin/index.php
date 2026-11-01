<?php
define('BASE', '../');
require_once __DIR__ . '/../inc/layout.php';
$u = require_role('admin');

$n = fn(string $sql, array $p = []) => (int) q($sql, $p)->fetchColumn();
$s = [
  'approved'  => $n("SELECT COUNT(*) FROM pharmacies p JOIN users u ON u.id=p.owner_user_id WHERE p.approval_status='approved' AND u.status='active'"),
  'pending'   => $n("SELECT COUNT(*) FROM pharmacies WHERE approval_status='pending'"),
  'suspended' => $n("SELECT COUNT(*) FROM pharmacies WHERE approval_status='suspended'"),
  'active'    => $n("SELECT COUNT(*) FROM inventory i JOIN pharmacies p ON p.id=i.pharmacy_id WHERE i.is_active=1 AND p.approval_status='approved'"),
  'stale'     => $n("SELECT COUNT(*) FROM inventory i JOIN pharmacies p ON p.id=i.pharmacy_id WHERE i.is_active=1 AND p.approval_status='approved' AND i.status<>'out_of_stock' AND (i.last_confirmed_at IS NULL OR i.last_confirmed_at < ?)", [fresh_cutoff()]),
  'req_pending'  => $n("SELECT COUNT(*) FROM availability_requests WHERE status='pending' AND expires_at > ?", [now()]),
  'req_answered' => $n("SELECT COUNT(*) FROM availability_requests WHERE status IN ('confirmed_available','confirmed_unavailable','cannot_confirm')"),
  'req_expired'  => $n("SELECT COUNT(*) FROM availability_requests WHERE status='expired' OR (status='pending' AND expires_at <= ?)", [now()]),
  'reports'   => $n("SELECT COUNT(*) FROM information_reports WHERE status='open'"),
  'products'  => $n("SELECT COUNT(*) FROM medicines WHERE archived=0"),
];
page_head('Admin overview', ['ctx' => 'admin']);
page_nav('admin');
page_banner('Administrator overview', 'Signed in as ' . $u['email'], 'admin');
?>
    <main class="container dash-main">
        <?php flash_render(); ?>
        <?php if ($s['pending'] || $s['reports']): ?>
        <div class="alert alert-warn"><span>
            <?php if ($s['pending']): ?>⏳ <a href="pharmacies.php?f=pending"><strong><?= $s['pending'] ?></strong> pharmac<?= $s['pending'] === 1 ? 'y' : 'ies' ?> awaiting review</a>. <?php endif; ?>
            <?php if ($s['reports']): ?>⚑ <a href="reports.php"><strong><?= $s['reports'] ?></strong> open report<?= $s['reports'] === 1 ? '' : 's' ?></a>.<?php endif; ?></span></div>
        <?php endif; ?>
        <h2 class="section-title">Pharmacies &amp; catalogue</h2>
        <div class="stat-row">
            <div class="stat ok"><strong data-count="<?= $s['approved'] ?>">0</strong><span>Approved pharmacies</span></div>
            <div class="stat warn"><strong data-count="<?= $s['pending'] ?>">0</strong><span>Pending review</span></div>
            <div class="stat bad"><strong data-count="<?= $s['suspended'] ?>">0</strong><span>Suspended</span></div>
            <div class="stat"><strong data-count="<?= $s['products'] ?>">0</strong><span>Catalogue products</span></div>
        </div>
        <h2 class="section-title">Listings (approved pharmacies)</h2>
        <div class="stat-row">
            <div class="stat"><strong data-count="<?= $s['active'] ?>">0</strong><span>Active listings</span></div>
            <div class="stat warn"><strong data-count="<?= $s['stale'] ?>">0</strong><span>Stale / unconfirmed (&gt;<?= FRESH_HOURS ?> h)</span></div>
        </div>
        <h2 class="section-title">Confirmation requests &amp; reports</h2>
        <div class="stat-row">
            <div class="stat"><strong data-count="<?= $s['req_pending'] ?>">0</strong><span>Pending requests</span></div>
            <div class="stat ok"><strong data-count="<?= $s['req_answered'] ?>">0</strong><span>Answered</span></div>
            <div class="stat"><strong data-count="<?= $s['req_expired'] ?>">0</strong><span>Expired</span></div>
            <div class="stat warn"><strong data-count="<?= $s['reports'] ?>">0</strong><span><a href="reports.php">Open reports</a></span></div>
        </div>
        <p class="muted small">Counts only — private request details are never shown here and request tokens are stored as hashes.</p>
    </main>
<?php page_foot(); ?>
