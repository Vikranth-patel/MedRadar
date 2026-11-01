<?php
// inc/layout.php — shared page chrome. ctx = public | pharmacy | admin
require_once __DIR__ . '/bootstrap.php';

function page_head(string $title, array $o = []): void {
    $desc = $o['desc'] ?? 'MedRadar helps you find exact medicines at admin-approved pharmacies, see when stock was last confirmed, and request confirmation before you travel.';
    $ctx  = $o['ctx'] ?? 'public';
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= h($desc) ?>">
    <meta name="theme-color" content="#0f8286">
    <meta name="csrf" content="<?= h(csrf_token()) ?>">
    <?php if ($ctx !== 'public'): ?><meta name="robots" content="noindex"><?php endif; ?>
    <title><?= h($title) ?> | MedRadar</title>
    <link rel="icon" type="image/png" href="<?= BASE ?>assets/favicon.png">
    <link rel="apple-touch-icon" href="<?= BASE ?>assets/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE ?>css/style.css">
    <script>try{var t=localStorage.getItem('rx-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body class="<?= h($o['body'] ?? '') ?>">
<?php if ($ctx === 'public' && empty($o['no_strip'])): ?>
    <div class="emergency-strip">🚑 MedRadar is not an emergency service. In a medical emergency call <a href="tel:108">108</a> (ambulance) or <a href="tel:112">112</a>.</div>
<?php endif;
}

function page_nav(string $ctx = 'public'): void {
    $u = current_user();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $pfx = $ctx === 'admin' ? '' : BASE;   // admin pages live in /admin, so their own links stay relative to it
    $link = function (string $href, string $label, ?string $badge = null) use ($cur, $pfx) {
        $active = basename($href) === $cur ? ' aria-current="page"' : '';
        return '<li><a href="' . $pfx . $href . '"' . $active . '>' . h($label) . ($badge ? ' <span class="nav-badge">' . h($badge) . '</span>' : '') . '</a></li>';
    };
    $links = '';
    if ($ctx === 'pharmacy') {
        $pending = (int) q("SELECT COUNT(*) FROM availability_requests r JOIN inventory i ON i.id = r.inventory_id JOIN pharmacies p ON p.id = i.pharmacy_id
                            WHERE p.owner_user_id = ? AND r.status = 'pending' AND r.expires_at > ?", [$u['id'] ?? 0, now()])->fetchColumn();
        $links = $link('dashboard.php', 'Dashboard') . $link('inventory.php', 'Inventory') . $link('requests.php', 'Requests', $pending ? (string)$pending : null)
               . $link('history.php', 'History') . $link('profile.php', 'Profile');
    } elseif ($ctx === 'admin') {
        $links = $link('index.php', 'Overview') . $link('pharmacies.php', 'Pharmacies') . $link('catalogue.php', 'Catalogue') . $link('reports.php', 'Reports') . $link('history.php', 'History');
    } else {
        $links = $link('index.php#top-search', 'Search') . $link('track.php', 'Track request') . $link('about.php', 'About & limits');
        $links .= $u ? $link($u['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php', 'My dashboard') : $link('login.php', 'Chemist login');
    }
    $count = null;
    if ($ctx === 'public') {
        $count = (int) q("SELECT COUNT(*) FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.approval_status = 'approved' AND u.status = 'active'")->fetchColumn();
    }
    $home = $ctx === 'admin' ? 'index.php' : ($ctx === 'public' ? BASE . 'index.php' : BASE . 'dashboard.php');
    ?>
    <header class="navbar">
        <div class="container nav-content">
            <a href="<?= $home ?>" class="brand" aria-label="MedRadar home">
                <img class="brand-logo logo-light" src="<?= BASE ?>assets/logo-light.png" alt="MedRadar" width="405" height="132">
                <img class="brand-logo logo-dark" src="<?= BASE ?>assets/logo-dark.png" alt="" aria-hidden="true" width="477" height="132">
                <?php if ($ctx !== 'public'): ?><span class="brand-tag"><?= $ctx === 'admin' ? 'Admin' : 'Pharmacy' ?></span><?php endif; ?>
            </a>
            <nav class="nav-right" aria-label="Main">
                <?php if ($count !== null): ?><span class="network-pulse"><span class="pulse-dot"></span> <?= $count ?> approved pharmac<?= $count === 1 ? 'y' : 'ies' ?></span><?php endif; ?>
                <ul class="nav-links" id="nav-links">
                    <?= $links ?>
                    <?php if ($u && $ctx !== 'public'): ?>
                    <li><form method="post" action="<?= BASE ?>logout.php" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm">Log out</button></form></li>
                    <?php endif; ?>
                </ul>
                <button type="button" class="icon-btn" id="theme-toggle" aria-label="Toggle dark mode">
                    <svg class="i-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    <svg class="i-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                </button>
                <button type="button" class="icon-btn menu-toggle" id="menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="nav-links">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </nav>
        </div>
    </header>
<?php
}

/** Image banner used at the top of pharmacy/admin/info pages. $variant picks the background photo. */
function page_banner(string $title, string $sub = '', string $variant = 'pharmacy', string $action = ''): void { ?>
    <section class="dash-banner dash-banner--<?= h($variant) ?>">
        <div class="container dash-banner-inner">
            <div><?php if ($sub): ?><p><?= h($sub) ?></p><?php endif; ?><h1><?= h($title) ?></h1></div>
            <?= $action ?>
        </div>
    </section>
<?php }

function page_foot(array $scripts = [], string $ctx = 'public'): void { ?>
    <footer class="footer">
        <div class="container">
            <div class="footer-inner">
                <img class="footer-logo" src="<?= BASE ?>assets/logo-dark.png" alt="MedRadar" width="477" height="132">
                <div>
                    <?php if ($ctx === 'public'): ?>
                        <a href="<?= BASE ?>index.php#top-search">Search</a><a href="<?= BASE ?>track.php">Track request</a><a href="<?= BASE ?>about.php">About &amp; limits</a><a href="<?= BASE ?>login.php">Chemist login</a>
                    <?php endif; ?>
                </div>
            </div>
            <p class="disclaimer">© <span id="year"></span> MedRadar — academic prototype with <strong>fictional demonstration data</strong>. Availability is reported by pharmacies and can change; confirm before travelling. Confirmation does not reserve stock. MedRadar gives no diagnosis, treatment advice, automatic substitution or emergency response.</p>
        </div>
    </footer>
    <button type="button" class="icon-btn to-top" id="to-top" aria-label="Back to top">↑</button>
    <div class="toast-wrap" id="toast-wrap" aria-live="polite"></div>
    <script src="<?= BASE ?>js/app.js"></script>
<?php foreach ($scripts as $s): ?>    <script src="<?= BASE . h($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php }

/** Status + freshness badges (text AND icon, never colour alone). */
function badges(array $row): string {
    $f = freshness($row);
    return '<span class="badge badge-' . h(str_replace('_', '', $row['status'])) . '">' . h(status_text($row['status'])) . '</span> '
         . '<span class="fresh fresh-' . $f['key'] . '" title="' . h($f['detail']) . '">' . ['fresh' => '● ', 'stale' => '⚠ ', 'never' => '? ', 'out' => '✖ '][$f['key']] . h($f['label']) . '</span>';
}
function approval_badge(string $s): string {
    return '<span class="badge badge-ap-' . h($s) . '">' . h(approval_label($s)) . '</span>';
}

/** Weekly opening-hours editor (used by registration and profile). */
function hours_fields(array $hours): void { ?>
    <fieldset class="hours-editor"><legend>Opening hours</legend>
    <?php foreach (DAYS as $k => $name):
        $v = $hours[$k] ?? ''; [$o, $c] = $v ? explode('-', $v) : ['09:00', '21:00']; ?>
        <div class="hours-row">
            <span class="hours-day"><?= h($name) ?></span>
            <label class="check"><input type="checkbox" name="closed[<?= $k ?>]" value="1" <?= $v === '' ? 'checked' : '' ?>> Closed</label>
            <input type="time" name="open[<?= $k ?>]" value="<?= h($o) ?>" aria-label="<?= h($name) ?> opens">
            <span>to</span>
            <input type="time" name="close[<?= $k ?>]" value="<?= h($c) ?>" aria-label="<?= h($name) ?> closes">
        </div>
    <?php endforeach; ?>
    </fieldset>
<?php }

/** Modal used by search results and pharmacy pages to send an availability-confirmation request. */
function request_dialog(): void { ?>
    <dialog id="request-dialog" class="dialog" aria-labelledby="rd-title">
        <form method="dialog" class="dialog-close"><button class="icon-btn" aria-label="Close">×</button></form>
        <div id="rd-form">
            <h2 id="rd-title">Request confirmation</h2>
            <p class="dialog-target"><strong id="rd-product"></strong><br><span id="rd-pharmacy"></span></p>
            <ul class="notes">
                <li>Asks this pharmacy to check <strong>this one product</strong>. No personal details are needed.</li>
                <li>It is <strong>not a reservation</strong>, and a reply is not guaranteed.</li>
                <li>The request expires after <?= REQUEST_TTL_HOURS ?> hours. You get a private tracking link.</li>
            </ul>
            <div class="alert alert-err hidden" id="rd-error" role="alert"></div>
            <button type="button" class="btn btn-primary btn-block" id="rd-send">Send request</button>
        </div>
        <div id="rd-done" class="hidden">
            <h2>Request sent ✔</h2>
            <p>Keep this private link to see the pharmacy's reply. The pharmacy may not respond — call them if it's urgent.</p>
            <input type="text" id="rd-link" readonly aria-label="Your private tracking link">
            <div class="dialog-actions">
                <button type="button" class="btn btn-ghost" id="rd-copy">Copy link</button>
                <a class="btn btn-primary" id="rd-open" href="#">Open tracking page</a>
            </div>
        </div>
    </dialog>
<?php }

function approval_notice(array $ph): void {
    $s = $ph['approval_status'];
    $msg = [
        'pending'   => ['alert-warn', '⏳ Your pharmacy is awaiting administrator approval. You can prepare your inventory, but it is not visible in public search yet.'],
        'approved'  => ['alert-ok',   '✔ Your pharmacy is admin-approved and visible in public search.'],
        'rejected'  => ['alert-err',  '✖ Your registration was rejected' . ($ph['review_reason'] ? ': ' . $ph['review_reason'] : '.') . ' Update your profile, then resubmit for review.'],
        'suspended' => ['alert-err',  '⛔ Your pharmacy is suspended and hidden from public search.' . ($ph['review_reason'] ? ' Reason: ' . $ph['review_reason'] : '')],
    ][$s];
    echo '<div class="alert ' . $msg[0] . '" role="status"><span>' . h($msg[1]) . '</span></div>';
    if ($s === 'rejected') {
        echo '<form method="post" action="dashboard.php" class="inline-form" style="margin-bottom:1rem">' . csrf_field()
           . '<input type="hidden" name="action" value="resubmit"><button class="btn btn-primary btn-sm" type="submit">Resubmit for review</button></form>';
    }
}
