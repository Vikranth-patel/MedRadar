<?php
require_once __DIR__ . '/inc/layout.php';
page_head('About, limitations & privacy');
page_nav('public');
page_banner('About, limits & privacy', 'What MedRadar is — and is not', 'about');
?>
    <main class="container dash-main narrow prose">
        <section class="panel emergency-box">
            <h2>🚑 In an emergency</h2>
            <p>MedRadar is <strong>not</strong> an emergency service. Call <a href="tel:108"><strong>108</strong></a> (ambulance) or <a href="tel:112"><strong>112</strong></a> immediately.</p>
        </section>

        <section class="panel"><h2>What MedRadar is</h2>
            <p>An academic prototype that helps visitors find an <strong>exact</strong> medicine product at participating, admin-approved pharmacies, see <strong>how recently</strong> that availability was confirmed, and contact the pharmacy or request confirmation before travelling.</p>
            <p><strong>All data is fictional demonstration data</strong> unless a real pharmacy has given permission.</p></section>

        <section class="panel"><h2>Limitations</h2>
            <ul>
                <li>Availability is <strong>pharmacy-reported</strong>, can change at any time, and must be confirmed before travelling.</li>
                <li>A confirmation request is <strong>not a reservation</strong> and a reply is not guaranteed.</li>
                <li>No diagnosis, treatment advice, <strong>automatic substitution</strong> or emergency response. Different strengths, forms or ingredients are always shown separately.</li>
                <li>No online sales, payments or delivery.</li>
            </ul></section>

        <section class="panel"><h2>How freshness works</h2>
            <ul>
                <li><strong>Recently confirmed</strong> — the pharmacy confirmed that item within the last <?= FRESH_HOURS ?> hours.</li>
                <li><strong>Stale</strong> — confirmed more than <?= FRESH_HOURS ?> hours ago. Availability is <em>uncertain</em>; it is not assumed to be out of stock.</li>
                <li><strong>Never confirmed</strong> — listed but never confirmed by the pharmacy.</li>
                <li><strong>Reported out of stock</strong> — the pharmacy marked the item out of stock (with a timestamp).</li>
            </ul>
            <p class="muted small">The <?= FRESH_HOURS ?>-hour window is a documented prototype policy, not a stock guarantee. Each item has its own timestamp; editing a pharmacy profile never refreshes it.</p></section>

        <section class="panel"><h2>What “Admin-approved” means</h2>
            <p>An administrator reviewed the pharmacy’s registration and allowed it to appear in search. It is <strong>not</strong> a professional or regulatory verification.</p></section>

        <section class="panel"><h2>Privacy &amp; data retention</h2>
            <ul>
                <li>No visitor account is needed. Requests contain <strong>no name, phone or email</strong>; you hold a private random tracking link.</li>
                <li>We store only a hash of your tracking token and a hash of your connection, used for rate limiting.</li>
                <li>Location permission is never required.</li>
                <li>Retention: requests <?= REQUEST_RETENTION_DAYS ?> days · resolved reports <?= REPORT_RETENTION_DAYS ?> days · audit events <?= EVENT_RETENTION_DAYS ?> days · rate-limit records 1 day.</li>
            </ul></section>
        <p><a class="btn btn-primary" href="index.php">Back to search</a></p>
    </main>
<?php page_foot(); ?>
