<?php
require_once __DIR__ . '/inc/layout.php';
$t = preg_match('/^[a-f0-9]{32}$/', (string)($_GET['t'] ?? '')) ? $_GET['t'] : '';
page_head('Track a confirmation request', ['desc' => 'Check the pharmacy’s reply to your availability-confirmation request.']);
page_nav('public');
page_banner('Track your request', 'Private availability-confirmation requests', 'pharmacy');
?>
    <main class="container dash-main narrow">
        <section class="panel" id="track-root" data-token="<?= h($t) ?>">
            <?php if (!$t): ?>
                <h2>Find a request</h2>
                <form id="track-form" class="add-form" style="grid-template-columns:1fr auto">
                    <div class="form-group"><label for="track-input">Private tracking token</label>
                    <input type="text" id="track-input" placeholder="Paste your 32-character token" maxlength="32" pattern="[a-fA-F0-9]{32}" required></div>
                    <button class="btn btn-primary" type="submit">Check</button>
                </form>
            <?php endif; ?>
            <div id="track-result" aria-live="polite"><?php if ($t): ?><p class="muted">Loading…</p><?php endif; ?></div>
        </section>
        <section class="panel hidden" id="recent-requests">
            <h2>Requests on this device</h2>
            <p class="muted small">Saved only in this browser so you can come back. We cannot look them up for you.</p>
            <ul class="plain-list" id="recent-requests-list"></ul>
        </section>
        <p class="uncertain-note">ⓘ A pharmacy response is not a reservation, replies are not guaranteed, and stock can change. Requests expire after <?= REQUEST_TTL_HOURS ?> hours.</p>
    </main>
<?php page_foot(['js/public.js']); ?>
