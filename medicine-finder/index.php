<?php
require_once __DIR__ . '/inc/layout.php';

$stats = q("SELECT (SELECT COUNT(*) FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.approval_status='approved' AND u.status='active') AS pharmacies,
                   (SELECT COUNT(*) FROM inventory i JOIN pharmacies p ON p.id=i.pharmacy_id JOIN users u ON u.id=p.owner_user_id JOIN medicines m ON m.id=i.medicine_id
                      WHERE i.is_active=1 AND m.archived=0 AND p.approval_status='approved' AND u.status='active') AS listings,
                   (SELECT COUNT(*) FROM medicines WHERE archived=0) AS products")->fetch();
$places = q("SELECT DISTINCT p.locality, p.pin_code FROM pharmacies p JOIN users u ON u.id=p.owner_user_id WHERE p.approval_status='approved' AND u.status='active' ORDER BY p.locality")->fetchAll();

page_head('Find exact medicines at nearby pharmacies');
page_nav('public');
?>
    <main>
        <!-- ================= HERO + SEARCH ================= -->
        <section class="hero" id="top-search">
            <div class="container">
                <div class="hero-inner">
                    <div class="hero-badge"><span class="pulse-dot"></span> Pharmacy-reported stock · No sign-up needed</div>
                    <h1 class="hero-title">Find the <span>exact medicine</span> before you travel.</h1>
                    <p class="hero-subtext">Search a product, pick your locality, and see which admin-approved pharmacies report it — and how recently they confirmed it.</p>

                    <form id="search-form" class="search-form" role="search" autocomplete="off">
                        <div class="search-grid">
                            <div class="search-input-wrapper">
                                <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <label for="search-input" class="sr-only">Medicine or ingredient</label>
                                <input type="text" id="search-input" maxlength="80" placeholder="Product or ingredient, e.g. Dolo, Cetirizine…" required autocomplete="off" role="combobox" aria-expanded="false" aria-controls="suggestions">
                                <span class="kbd-hint" aria-hidden="true">/</span>
                                <ul class="suggestions hidden" id="suggestions" role="listbox"></ul>
                            </div>
                            <div class="search-input-wrapper place-wrap">
                                <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                <label for="place-input" class="sr-only">Locality or PIN code</label>
                                <input type="text" id="place-input" list="localities" maxlength="60" placeholder="Locality or PIN (optional)" autocomplete="off">
                                <datalist id="localities">
                                    <?php foreach ($places as $pl): ?><option value="<?= h($pl['locality']) ?>"><?= h($pl['pin_code']) ?></option><?php endforeach; ?>
                                    <?php foreach (array_unique(array_column($places, 'pin_code')) as $pin): ?><option value="<?= h($pin) ?>"></option><?php endforeach; ?>
                                </datalist>
                            </div>
                            <button type="submit" class="btn btn-primary search-btn">Search</button>
                        </div>
                    </form>

                    <div class="quick-tags">
                        <span class="quick-label">Try:</span>
                        <button type="button" class="tag-btn" data-search="Dolo">Dolo</button>
                        <button type="button" class="tag-btn" data-search="Cetirizine">Cetirizine</button>
                        <button type="button" class="tag-btn" data-search="Paracetamol">Paracetamol</button>
                        <button type="button" class="tag-btn" data-search="Pantoprazole">Pantoprazole</button>
                    </div>
                    <div class="recent-row hidden" id="recent-row"></div>

                    <div class="hero-stats">
                        <div><strong data-count="<?= (int)$stats['pharmacies'] ?>">0</strong><span>Approved pharmacies</span></div>
                        <div><strong data-count="<?= (int)$stats['listings'] ?>">0</strong><span>Live listings</span></div>
                        <div><strong data-count="<?= (int)$stats['products'] ?>">0</strong><span>Catalogue products</span></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= RESULTS ================= -->
        <section id="results-section" class="container" aria-live="polite">
            <div id="results-toolbar" class="results-toolbar hidden">
                <div class="results-count" id="results-count"></div>
                <div class="filter-bar">
                    <label for="avail-select" class="sr-only">Availability</label>
                    <select id="avail-select" class="select">
                        <option value="any">Any availability</option>
                        <option value="in_stock">✔ In stock</option>
                        <option value="low_stock">◐ Low stock</option>
                        <option value="out_of_stock">✖ Reported out of stock</option>
                    </select>
                    <label class="chip-check"><input type="checkbox" id="fresh-check"> Recently confirmed only</label>
                    <label for="sort-select" class="sr-only">Sort</label>
                    <select id="sort-select" class="select">
                        <option value="confirmed">Sort: Latest confirmation</option>
                        <option value="name">Sort: Pharmacy A–Z</option>
                    </select>
                </div>
            </div>
            <p class="uncertain-note hidden" id="uncertain-note">ⓘ Availability is <strong>pharmacy-reported</strong> and can change. “Stale” means <em>uncertain</em>, not out of stock. Call or request confirmation before travelling — confirmation is not a reservation.</p>

            <div id="skeleton-loader" class="cards-grid hidden"><div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div></div>

            <div id="no-results" class="empty-state hidden">
                <div class="empty-icon">🔍</div>
                <h3 id="no-results-title">No matching products</h3>
                <p id="no-results-text"></p>
            </div>
            <div id="error-state" class="empty-state hidden" role="alert">
                <div class="empty-icon">⚠️</div>
                <h3>We couldn't load results</h3>
                <p id="error-text">Please check your connection and try again.</p>
                <button type="button" class="btn btn-primary" id="retry-btn" style="margin-top:1rem">Retry</button>
            </div>

            <div id="results"></div>
        </section>

        <!-- ================= HOW IT WORKS ================= -->
        <section class="section how" id="how" style="margin-top: var(--space);">
            <div class="container">
                <div class="section-head reveal">
                    <span class="eyebrow">How it works</span>
                    <h2>Check first, travel second</h2>
                    <p>Know which pharmacy reports your exact product — and how fresh that report is — before you leave home.</p>
                </div>
                <div class="steps">
                    <article class="step reveal"><h3>Search the exact product</h3><p>Pick a brand, strength and form from the catalogue. Different strengths are never mixed up or swapped.</p></article>
                    <article class="step reveal"><h3>Read the freshness</h3><p>Every listing shows when that item was last confirmed. Old reports are flagged as uncertain, not guessed as out of stock.</p></article>
                    <article class="step reveal"><h3>Call or request confirmation</h3><p>Call directly, or send a private confirmation request and check the pharmacy’s reply on a tracking page.</p></article>
                </div>
            </div>
        </section>

        <!-- ================= TRUST ================= -->
        <section class="section" id="trust">
            <div class="container split">
                <div class="split-media reveal">
                    <img src="assets/pexels-cottonbro-8657374-sm.jpg"
                         srcset="assets/pexels-cottonbro-8657374-sm.jpg 900w, assets/pexels-cottonbro-8657374.jpg 2000w"
                         sizes="(max-width: 960px) 92vw, 560px" alt="Pharmacist helping a customer read a medicine label" loading="lazy" width="900" height="600">
                    <div class="float-card"><span class="pulse-dot"></span> Admin-approved pharmacies only</div>
                </div>
                <div class="split-copy reveal">
                    <span class="eyebrow">Why MedRadar</span>
                    <h2>Honest about what we know — and what we don't.</h2>
                    <p>MedRadar shows what pharmacies report. We never promise stock, never substitute products for you, and we make uncertainty visible.</p>
                    <ul class="feature-list">
                        <li><span class="ico">🕒</span><div><strong>Item-level freshness</strong><span>Each listing has its own last-confirmed time, set only when the pharmacy confirms that item.</span></div></li>
                        <li><span class="ico">🔒</span><div><strong>Private requests</strong><span>No account or phone number needed. Your tracking link is your private key.</span></div></li>
                        <li><span class="ico">✅</span><div><strong>Approved pharmacies</strong><span>“Admin-approved” means an administrator reviewed the listing — not a professional licence check.</span></div></li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- ================= FAQ ================= -->
        <section class="section" id="faq" style="padding-top:0">
            <div class="container">
                <div class="section-head reveal" style="margin-inline:auto;text-align:center"><span class="eyebrow">FAQ</span><h2>Quick answers</h2></div>
                <div class="faq reveal">
                    <details><summary>What does “stale” mean?</summary><p>The pharmacy last confirmed the item more than <?= FRESH_HOURS ?> hours ago. It may still be in stock — it is simply uncertain. Call to check.</p></details>
                    <details><summary>Does a confirmation reserve my medicine?</summary><p>No. A pharmacy's reply is a statement at that moment. It does not hold stock, and replies are not guaranteed.</p></details>
                    <details><summary>Will MedRadar suggest a substitute?</summary><p>Never. Different strengths, forms or ingredients are shown separately. Ask a pharmacist or doctor before switching.</p></details>
                    <details><summary>Is this for emergencies?</summary><p>No. In an emergency call 108 or 112. MedRadar is an academic prototype with fictional demonstration data.</p></details>
                    <details><summary>I run a pharmacy. How do I list stock?</summary><p><a href="login.php#register" style="color:var(--primary);font-weight:700">Register your pharmacy</a>. After an administrator approves it, you can list catalogue products and confirm stock.</p></details>
                </div>
            </div>
        </section>

        <!-- ================= PHARMACY CTA ================= -->
        <section class="cta">
            <div class="container"><div class="cta-inner reveal">
                <span class="eyebrow" style="color:#fda4af">For pharmacies</span>
                <h2>Own a pharmacy? List your stock honestly.</h2>
                <p>Register, get approved, and keep each item's confirmation fresh so patients know what to trust.</p>
                <a href="login.php#register" class="btn btn-emergency">Register your pharmacy →</a>
                <a href="login.php" class="btn btn-light">Pharmacy sign in</a>
            </div></div>
        </section>
    </main>
<?php request_dialog(); page_foot(['js/public.js', 'js/search.js']); ?>
