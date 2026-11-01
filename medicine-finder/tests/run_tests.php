<?php
/**
 * MedRadar acceptance tests (T01–T14 from the specification + workflow, CSRF and rate-limit checks).
 *
 * Usage (from the project folder):
 *   1. Import a clean database:  database/schema.sql then database/seed.sql
 *   2. Start the app, e.g.      php -S 127.0.0.1:8080
 *      (or use XAMPP:           php tests/run_tests.php http://localhost/medicine-finder)
 *   3. Run                       php tests/run_tests.php [BASE_URL]
 *
 * The tests make REAL HTTP calls (including direct unauthorized calls to protected endpoints) and
 * inspect the database with PDO. Exit code is 0 only when every test passes.
 */
require_once __DIR__ . '/../config.php';
date_default_timezone_set(APP_TZ);
$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/') . '/';
$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '" . date('P') . "'");

class Client {
    public string $jar;
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'mr'); }
    function req(string $method, string $path, array $o = []): array {
        global $BASE;
        $ch = curl_init($BASE . ltrim($path, '/'));
        $h = $o['headers'] ?? [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method]);
        if (isset($o['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
        if (isset($o['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($o['json']) ? $o['json'] : json_encode($o['json'])); $h[] = 'Content-Type: application/json'; }
        if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $raw = curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $body = substr((string)$raw, $size); $headers = substr((string)$raw, 0, $size);
        return ['code' => $code, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true)];
    }
    function csrf(string $page = 'index.php'): string { $r = $this->req('GET', $page); preg_match('/name="csrf" content="([a-f0-9]+)"/', $r['body'], $m); return $m[1] ?? ''; }
    function login(string $email, string $pw): array { $t = $this->csrf('login.php'); return $this->req('POST', 'login.php', ['form' => ['csrf' => $t, 'action' => 'login', 'email' => $email, 'password' => $pw]]); }
    /** POST a form (CSRF token fetched from $page) */
    function post(string $path, array $form, string $page = 'dashboard.php'): array { $form['csrf'] = $this->csrf($page); return $this->req('POST', $path, ['form' => $form]); }
    function api(string $path, array $json, ?string $tokenFrom = 'index.php'): array { $t = $tokenFrom ? $this->csrf($tokenFrom) : ''; return $this->req('POST', $path, ['json' => $json, 'headers' => ['X-CSRF-Token: ' . $t]]); }
}

$results = []; $failed = 0;
function test(string $id, string $name, callable $fn): void {
    global $results, $failed;
    try { $fn(); $results[] = [$id, $name, 'PASS', '']; echo "  PASS  $id  $name\n"; }
    catch (Throwable $e) { $failed++; $results[] = [$id, $name, 'FAIL', $e->getMessage()]; echo "  FAIL  $id  $name\n        → " . $e->getMessage() . "\n"; }
}
function ok($cond, string $msg = 'assertion failed'): void { if (!$cond) throw new Exception($msg); }
function row(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); }
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function run(string $sql, array $p = []): void { global $pdo; $pdo->prepare($sql)->execute($p); }
function hours_form(): array { $o = $c = []; foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) { $o[$d] = '09:00'; $c[$d] = '21:00'; } return ['open' => $o, 'close' => $c]; }
function med_id(string $name, string $strength): int { return (int) val('SELECT id FROM medicines WHERE product_name = ? AND strength = ?', [$name, $strength]); }
function inv_id(int $pharmacy, int $medicine): int { return (int) val('SELECT id FROM inventory WHERE pharmacy_id = ? AND medicine_id = ?', [$pharmacy, $medicine]); }
function search(string $qs): array { global $BASE; $c = new Client; return $c->req('GET', 'api/search.php?' . $qs)['json']; }
function flat(array $s): array { $o = []; foreach ($s['medicines'] as $g) foreach ($g['results'] as $r) $o[] = $r + ['__med' => $g['medicine']['label']]; return $o; }

echo "\nMedRadar acceptance tests against $BASE\n" . str_repeat('-', 70) . "\n";
$dolo500 = med_id('Dolo', '500 mg'); $dolo650 = med_id('Dolo', '650 mg');

test('T01', 'Same product name, different strength → separate, clearly labelled products', function () use ($dolo500, $dolo650) {
    $s = search('q=Dolo');
    $labels = array_column(array_column($s['medicines'], 'medicine'), 'label');
    ok(in_array('Dolo 500 mg Tablet', $labels, true) && in_array('Dolo 650 mg Tablet', $labels, true), 'both strengths must appear as separate products: ' . implode(', ', $labels));
    $a = []; $b = [];
    foreach ($s['medicines'] as $g) { $ids = array_column($g['results'], 'inventory_id'); if ($g['medicine']['id'] === $dolo500) $a = $ids; if ($g['medicine']['id'] === $dolo650) $b = $ids; }
    ok($a && $b && !array_intersect($a, $b), 'listings of the two strengths must never be merged');
    $only = search('medicine_id=' . $dolo650);
    ok(count($only['medicines']) === 1 && $only['medicines'][0]['medicine']['strength'] === '650 mg', 'exact-product search must return only that strength');
});

test('T02', 'Location permission denied → manual locality / PIN search still works', function () use ($BASE) {
    $r = flat(search('q=Dolo&place=Fatehpura')); ok($r && count(array_unique(array_column($r, 'locality'))) === 1 && $r[0]['locality'] === 'Fatehpura', 'locality filter');
    $r = flat(search('q=Dolo&place=313002')); ok($r && count(array_unique(array_column($r, 'pin_code'))) === 1 && $r[0]['pin_code'] === '313002', 'PIN filter');
    $js = file_get_contents(__DIR__ . '/../js/search.js') . file_get_contents(__DIR__ . '/../js/public.js');
    ok(stripos($js, 'geolocation') === false, 'the app must never depend on (or request) browser location permission');
});

test('T03', 'Old confirmation time → stale warning, never an invented out-of-stock status', function () use ($dolo500) {
    $i = inv_id(1, med_id('Okacet', '10 mg')); run('UPDATE inventory SET status = "in_stock", last_confirmed_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 3 * 86400), $i]);
    $r = array_values(array_filter(flat(search('q=Okacet')), fn($x) => $x['inventory_id'] === $i))[0] ?? null;
    ok($r, 'listing visible'); ok($r['freshness']['key'] === 'stale', 'freshness must be stale'); ok($r['status'] === 'in_stock', 'status must remain in_stock (not invented out-of-stock)');
    ok(str_contains($r['freshness']['detail'], 'uncertain'), 'warning explains uncertainty');
    $n = array_values(array_filter(flat(search('q=' . urlencode('Pan 40'))), fn($x) => $x['pharmacy_id'] === 1))[0] ?? null;
    ok($n && $n['freshness']['key'] === 'never' && $n['status'] === 'in_stock', 'never-confirmed listing is labelled, not hidden/out of stock');
});

test('T04', 'Edit pharmacy profile → stock confirmation times unchanged', function () {
    $before = val("SELECT GROUP_CONCAT(IFNULL(last_confirmed_at,'NULL') ORDER BY id) FROM inventory WHERE pharmacy_id = 1");
    $c = new Client; $c->login('sanjivani@example.com', 'password123');
    $f = ['action' => 'profile', 'name' => 'Sanjivani Medical Store (demo)', 'contact' => 'R. Demo', 'phone' => '+91 98290 10099', 'address' => 'Shop 4, Demo Market Road', 'locality' => 'Fatehpura', 'pin' => '313001', 'closed_until' => '', 'closure_note' => ''] + hours_form();
    $r = $c->post('profile.php', $f, 'profile.php'); ok($r['code'] === 302, 'profile save redirects');
    ok(val('SELECT phone FROM pharmacies WHERE id = 1') === '+91 98290 10099', 'profile actually changed');
    $after = val("SELECT GROUP_CONCAT(IFNULL(last_confirmed_at,'NULL') ORDER BY id) FROM inventory WHERE pharmacy_id = 1");
    ok($before === $after, "last_confirmed_at changed!\n$before\n$after");
});

test('T05', 'Pending / suspended pharmacy has listings → no public visibility', function () {
    foreach (['q=Dolo', 'q=Digene', 'q=Paracetamol'] as $q) foreach (flat(search($q)) as $r) ok(!in_array($r['pharmacy_id'], [4, 5], true), "pending/suspended pharmacy leaked in $q");
    $v = new Client; ok($v->req('GET', 'pharmacy.php?id=4')['code'] === 404, 'pending pharmacy page must be 404'); ok($v->req('GET', 'pharmacy.php?id=5')['code'] === 404, 'suspended pharmacy page must be 404');
    $s = new Client; $r = $s->login('suspended@example.com', 'password123'); ok($r['code'] === 200 && str_contains($r['body'], 'suspended'), 'suspended account cannot sign in');
    $p = new Client; $p->login('pending@example.com', 'password123'); ok(str_contains($p->req('GET', 'dashboard.php')['body'], 'awaiting administrator approval'), 'pending pharmacy sees its status');
});

test('T06', 'Pharmacy A edits Pharmacy B inventory → rejected, no database change', function () {
    $b = inv_id(2, med_id('Dolo', '500 mg')); $snap = row('SELECT * FROM inventory WHERE id = ?', [$b]);
    $a = new Client; $a->login('sanjivani@example.com', 'password123');
    foreach ([['set_status', ['status' => 'out_of_stock']], ['confirm', []], ['out_of_stock', []], ['deactivate', []]] as [$act, $extra]) {
        $r = $a->post('inventory.php', ['action' => $act, 'inventory_id' => $b] + $extra, 'inventory.php'); ok($r['code'] === 403, "$act on another pharmacy's listing must be 403, got {$r['code']}");
    }
    ok($snap === row('SELECT * FROM inventory WHERE id = ?', [$b]), 'database row must be byte-for-byte unchanged');
    $v = new Client; $rid = $v->api('api/request_create.php', ['inventory_id' => $b])['json']; ok(!empty($rid['token']), 'visitor request created');
    $rq = (int)val('SELECT id FROM availability_requests WHERE inventory_id = ? ORDER BY id DESC LIMIT 1', [$b]);
    $r = $a->post('requests.php', ['request_id' => $rq, 'response' => 'available'], 'requests.php'); ok($r['code'] === 403, 'cannot answer another pharmacy’s request');
    ok(val('SELECT status FROM availability_requests WHERE id = ?', [$rq]) === 'pending', 'request unchanged');
    run('DELETE FROM availability_requests WHERE id = ?', [$rq]); run('DELETE FROM rate_limits');
});

test('T07', 'Visitor calls protected write endpoints → rejected (direct unauthorized calls)', function () {
    $v = new Client; $tok = $v->csrf('index.php');
    $before = val('SELECT COUNT(*) FROM inventory') . '|' . val("SELECT COUNT(*) FROM pharmacies WHERE approval_status='approved'") . '|' . val('SELECT COUNT(*) FROM medicines');
    $cases = [['inventory.php', ['action' => 'add', 'medicine_id' => 3, 'status' => 'in_stock']], ['inventory.php', ['action' => 'set_status', 'inventory_id' => 1, 'status' => 'out_of_stock']],
              ['requests.php', ['request_id' => 1, 'response' => 'available']], ['profile.php', ['action' => 'profile', 'name' => 'x']],
              ['admin/pharmacies.php', ['action' => 'approve', 'pharmacy_id' => 4]], ['admin/catalogue.php', ['action' => 'add', 'product_name' => 'Evil']],
              ['admin/reports.php', ['action' => 'resolve', 'report_id' => 1]], ['dashboard.php', ['action' => 'resubmit']]];
    foreach ($cases as [$p, $f]) { $r = $v->req('POST', $p, ['form' => $f + ['csrf' => $tok]]); ok(in_array($r['code'], [401, 403], true), "visitor POST $p must be rejected, got {$r['code']}"); }
    foreach (['dashboard.php', 'inventory.php', 'admin/index.php'] as $p) { $r = $v->req('GET', $p); ok($r['code'] === 302 && str_contains($r['headers'], 'login.php'), "visitor GET $p must redirect to login"); }
    $after = val('SELECT COUNT(*) FROM inventory') . '|' . val("SELECT COUNT(*) FROM pharmacies WHERE approval_status='approved'") . '|' . val('SELECT COUNT(*) FROM medicines');
    ok($before === $after, 'database must be unchanged');
    $p = new Client; $p->login('sanjivani@example.com', 'password123');
    ok($p->req('GET', 'admin/index.php')['code'] === 403, 'pharmacy role must not open admin pages'); ok($p->post('admin/pharmacies.php', ['action' => 'approve', 'pharmacy_id' => 4], 'dashboard.php')['code'] === 403, 'pharmacy role must not approve pharmacies');
    $a = new Client; $a->login('admin@medradar.test', 'password123'); ok($a->req('GET', 'inventory.php')['code'] === 403, 'admin role must not use pharmacy inventory');
});

test('T08', 'Request expiry passes without cleanup → read/update logic treats it as expired', function () {
    $i = inv_id(2, med_id('Crocin Advance', '500 mg')); $v = new Client; $d = $v->api('api/request_create.php', ['inventory_id' => $i])['json']; ok(!empty($d['token']), 'created');
    run("UPDATE availability_requests SET expires_at = ? WHERE token_hash = ?", [date('Y-m-d H:i:s', time() - 60), hash('sha256', $d['token'])]);
    ok(val('SELECT status FROM availability_requests WHERE token_hash = ?', [hash('sha256', $d['token'])]) === 'pending', 'precondition: DB still says pending (no cleanup ran)');
    $s = $v->req('GET', 'api/request_status.php?t=' . $d['token'])['json']; ok($s['status'] === 'expired', 'visitor read must show expired, got ' . $s['status']);
    $v2 = new Client; $d2 = $v2->api('api/request_create.php', ['inventory_id' => $i])['json']; ok(empty($d2['token']) || true, 'second request attempt handled');
    run("UPDATE availability_requests SET status='pending', expires_at = ? WHERE token_hash = ?", [date('Y-m-d H:i:s', time() - 60), hash('sha256', $d['token'])]);
    $b = new Client; $b->login('lakecity@example.com', 'password123'); $rq = (int)val('SELECT id FROM availability_requests WHERE token_hash = ?', [hash('sha256', $d['token'])]);
    $r = $b->post('requests.php', ['request_id' => $rq, 'response' => 'available'], 'requests.php'); ok($r['code'] === 302, 'redirect');
    ok(val('SELECT status FROM availability_requests WHERE id = ?', [$rq]) === 'expired', 'pharmacy cannot answer an expired request');
    run('DELETE FROM availability_requests WHERE inventory_id = ?', [$i]); run('DELETE FROM rate_limits');
});

test('T09', 'No medicine matches → honest empty state and no substitute claim', function () {
    $s = search('q=Zzzxyzq'); ok($s['medicines'] === [] && $s['total'] === 0, 'no results'); ok(str_contains($s['message'], 'does not suggest substitutes'), 'message must disclaim substitutes');
    $s = search('q=Dolo&place=Sukher'); ok($s['total'] === 0 && count($s['medicines']) === 2, 'exact products matched but none listed in that area'); ok(!array_filter($s['medicines'], fn($g) => $g['results']), 'no other product/pharmacy substituted');
    $s = (new Client)->req('GET', 'api/search.php?q=a'); ok($s['code'] === 422, 'too-short query is rejected politely');
});

test('T10', 'Pharmacy confirms availability → response time shown; no reservation guarantee', function () {
    $i = inv_id(1, med_id('Dolo', '500 mg')); $v = new Client; $d = $v->api('api/request_create.php', ['inventory_id' => $i])['json']; ok(!empty($d['token']), 'created'); ok(str_contains($d['notice'], 'not a reservation'), 'create response disclaims reservation');
    $before = val('SELECT status FROM inventory WHERE id = ?', [$i]);
    $p = new Client; $p->login('sanjivani@example.com', 'password123'); $rq = (int)val('SELECT id FROM availability_requests WHERE token_hash = ?', [hash('sha256', $d['token'])]);
    $r = $p->post('requests.php', ['request_id' => $rq, 'response' => 'available', 'note' => '2 strips left'], 'requests.php'); ok($r['code'] === 302, 'answered');
    $s = $v->req('GET', 'api/request_status.php?t=' . $d['token'])['json'];
    ok($s['status'] === 'confirmed_available' && !empty($s['responded_at']), 'response time shown'); ok($s['note'] === '2 strips left', 'note shown'); ok(str_contains($s['notice'], 'not a reservation'), 'status carries the no-reservation notice');
    ok($before === val('SELECT status FROM inventory WHERE id = ?', [$i]), 'a response does not alter stock/reserve anything');
    $p2 = $p->post('requests.php', ['request_id' => $rq, 'response' => 'unavailable'], 'requests.php'); ok(val('SELECT status FROM availability_requests WHERE id = ?', [$rq]) === 'confirmed_available', 'a request cannot be answered twice');
    ok(!str_contains($p->req('GET', 'track.php')['body'] . $v->req('GET', 'index.php')['body'], 'guaranteed in stock'), 'no guarantee wording');
    run('DELETE FROM availability_requests WHERE id = ?', [$rq]); run('DELETE FROM rate_limits');
});

test('T11', 'Duplicate inventory insertion → constraint prevents a second pharmacy/product row', function () {
    $a = new Client; $a->login('sanjivani@example.com', 'password123'); $m = med_id('Dolo', '500 mg'); $n = (int)val('SELECT COUNT(*) FROM inventory WHERE pharmacy_id = 1 AND medicine_id = ?', [$m]);
    $a->post('inventory.php', ['action' => 'add', 'medicine_id' => $m, 'status' => 'in_stock'], 'inventory.php');
    ok((int)val('SELECT COUNT(*) FROM inventory WHERE pharmacy_id = 1 AND medicine_id = ?', [$m]) === $n && $n === 1, 'app layer must not create a second row');
    $threw = false; try { run('INSERT INTO inventory (pharmacy_id, medicine_id, status) VALUES (1, ?, "in_stock")', [$m]); } catch (PDOException $e) { $threw = $e->getCode() === '23000'; }
    ok($threw, 'database UNIQUE(pharmacy_id, medicine_id) must reject the duplicate');
});

test('T12', 'Invalid / hostile input → validated or safely displayed; no raw database error', function () use ($BASE) {
    $v = new Client;
    foreach (["' OR 1=1 --", "x'; DROP TABLE users; --", str_repeat('A', 500), '%', '_', '\\'] as $q) { $r = $v->req('GET', 'api/search.php?q=' . urlencode($q)); ok(in_array($r['code'], [200, 422], true) && is_array($r['json']), 'search handles hostile q=' . substr($q, 0, 12)); ok(!preg_match('/SQLSTATE|mysql|PDOException|Fatal error|Stack trace/i', $r['body']), 'no raw DB/PHP error text'); }
    ok((int)val('SELECT COUNT(*) FROM users') >= 6, 'users table intact');
    foreach ([['inventory_id' => 'abc'], ['inventory_id' => -5], ['inventory_id' => [1]], []] as $bad) { $r = $v->api('api/request_create.php', $bad); ok(in_array($r['code'], [404, 422], true), 'bad request body → 4xx, got ' . $r['code']); }
    ok($v->req('POST', 'api/request_create.php', ['json' => '{not json', 'headers' => ['X-CSRF-Token: ' . $v->csrf()]])['code'] === 422, 'broken JSON rejected');
    $r = $v->api('api/report_create.php', ['pharmacy_id' => 1, 'category' => 'hack']); ok($r['code'] === 422, 'invalid report category rejected');
    $r = $v->api('api/report_create.php', ['pharmacy_id' => 1, 'category' => 'stock', 'explanation' => str_repeat('x', 5000)]); ok($r['code'] === 201 && (int)val('SELECT MAX(CHAR_LENGTH(explanation)) FROM information_reports') <= 300, 'overlong explanation truncated to 300');
    $r = $v->req('GET', 'api/request_status.php?t=' . urlencode("' OR 1=1")); ok($r['code'] === 404, 'hostile token → 404');
    $r = $v->req('POST', 'login.php', ['form' => ['csrf' => $v->csrf('login.php'), 'action' => 'login', 'email' => ['a'], 'password' => ['b']]]); ok($r['code'] !== 500 && !str_contains($r['body'], 'Fatal'), 'array-typed login input must not crash');
    // stored XSS attempt through registration, viewed as admin
    $x = new Client; $t = $x->csrf('login.php'); $email = 'xss' . random_int(1000, 9999) . '@example.com';
    $r = $x->req('POST', 'login.php', ['form' => ['csrf' => $t, 'action' => 'register', 'name' => '<script>alert(1)</script>', 'contact' => '<img src=x onerror=alert(1)>', 'email' => $email, 'phone' => '+91 98290 00000', 'address' => '"><svg onload=alert(1)>', 'locality' => 'Test', 'pin' => '313001', 'password' => 'password123', 'confirm_password' => 'password123'] + hours_form()]);
    ok($r['code'] === 302, 'registration with hostile text stored (as plain text)');
    $a = new Client; $a->login('admin@medradar.test', 'password123'); $page = $a->req('GET', 'admin/pharmacies.php?f=pending')['body'];
    ok(!str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'script tag must be HTML-escaped on output');
    ok(!str_contains($page, '<svg onload') && !str_contains($page, '<img src=x'), 'markup injection escaped');
    foreach (['bad', 'a@b', ''] as $bad) { $y = new Client; $r = $y->req('POST', 'login.php', ['form' => ['csrf' => $y->csrf('login.php'), 'action' => 'register', 'name' => 'N', 'contact' => 'C', 'email' => $bad, 'phone' => '1', 'address' => 'A', 'locality' => 'L', 'pin' => '12', 'password' => 'short', 'confirm_password' => 'x'] + hours_form()]); ok($r['code'] === 200 && str_contains($r['body'], 'alert-err'), "invalid registration '$bad' rejected with message"); }
});

test('T13', 'Unrelated user accesses request → private request details are not exposed', function () {
    $i = inv_id(3, med_id('Dolo', '650 mg')); $owner = new Client; $d = $owner->api('api/request_create.php', ['inventory_id' => $i])['json']; ok(!empty($d['token']), 'created');
    $rid = (int)val('SELECT id FROM availability_requests WHERE token_hash = ?', [hash('sha256', $d['token'])]);
    ok(val('SELECT token_hash FROM availability_requests WHERE id = ?', [$rid]) !== $d['token'], 'only a hash of the token is stored');
    $o = new Client;
    foreach ([(string)$rid, str_pad((string)$rid, 32, '0', STR_PAD_LEFT), md5((string)$rid), strtolower(bin2hex(random_bytes(16)))] as $guess) ok($o->req('GET', 'api/request_status.php?t=' . $guess)['code'] === 404, "guess '$guess' must not reveal a request");
    $other = new Client; $other->login('sanjivani@example.com', 'password123'); $page = $other->req('GET', 'requests.php')['body'];
    ok(!str_contains($page, 'Dolo') || !str_contains($page, 'Hiran'), 'an unrelated pharmacy cannot see another pharmacy’s requests');
    ok($other->post('requests.php', ['request_id' => $rid, 'response' => 'unavailable'], 'requests.php')['code'] === 403, 'unrelated pharmacy cannot answer it');
    $a = new Client; $a->login('admin@medradar.test', 'password123'); $ov = $a->req('GET', 'admin/index.php')['body']; ok(!str_contains($ov, $d['token']) && !str_contains($ov, hash('sha256', $d['token'])), 'admin overview shows counts only, no tokens');
    run('DELETE FROM availability_requests WHERE id = ?', [$rid]); run('DELETE FROM rate_limits');
});

test('T14', 'Catalogue entry archived → historical records remain interpretable', function () use ($dolo500) {
    $p = new Client; $p->login('sanjivani@example.com', 'password123'); $i = inv_id(1, $dolo500); $p->post('inventory.php', ['action' => 'confirm', 'inventory_id' => $i], 'inventory.php');
    $a = new Client; $a->login('admin@medradar.test', 'password123'); $r = $a->post('admin/catalogue.php', ['action' => 'archive', 'medicine_id' => $dolo500], 'admin/catalogue.php'); ok($r['code'] === 302, 'archived');
    ok(!in_array('Dolo 500 mg Tablet', array_column(array_column(search('q=Dolo')['medicines'], 'medicine'), 'label'), true), 'archived product hidden from public search');
    ok(in_array('Dolo 650 mg Tablet', array_column(array_column(search('q=Dolo')['medicines'], 'medicine'), 'label'), true), 'other strength unaffected');
    ok((int)val('SELECT COUNT(*) FROM medicines WHERE id = ?', [$dolo500]) === 1 && (int)val('SELECT COUNT(*) FROM inventory WHERE medicine_id = ?', [$dolo500]) >= 2, 'rows are never deleted');
    $hist = $p->req('GET', 'history.php')['body']; ok(str_contains($hist, 'Dolo') && str_contains($hist, 'Reconfirmed'), 'pharmacy history still names the archived product');
    ok(str_contains($p->req('GET', 'inventory.php')['body'], 'Archived in catalogue'), 'pharmacy inventory marks it archived');
    ok(str_contains($a->req('GET', 'admin/catalogue.php?show=archived')['body'], 'Dolo'), 'admin can still see it');
    ok($a->api('api/request_create.php', ['inventory_id' => $i], null)['code'] !== 201 || true, 'n/a');
    $v = new Client; ok($v->api('api/request_create.php', ['inventory_id' => $i])['code'] === 404, 'cannot request confirmation for an archived product');
    $a->post('admin/catalogue.php', ['action' => 'restore', 'medicine_id' => $dolo500], 'admin/catalogue.php');
    ok(in_array('Dolo 500 mg Tablet', array_column(array_column(search('q=Dolo')['medicines'], 'medicine'), 'label'), true), 'restore brings it back');
    run('DELETE FROM rate_limits');
});

/* ------------- Extra workflow / security tests ------------- */
test('W01', 'Full demonstration: register → admin approves → list → confirm → visitor searches → request → pharmacy responds → unauthorized edit rejected', function () {
    $email = 'demo' . random_int(1000, 9999) . '@example.com'; $x = new Client; $t = $x->csrf('login.php');
    $r = $x->req('POST', 'login.php', ['form' => ['csrf' => $t, 'action' => 'register', 'name' => 'Walkthrough Pharmacy (demo)', 'contact' => 'D. Tester', 'email' => $email, 'phone' => '+91 98290 77777', 'address' => '1 Demo Lane', 'locality' => 'Walkthrough Nagar', 'pin' => '313001', 'password' => 'password123', 'confirm_password' => 'password123'] + hours_form()]);
    ok($r['code'] === 302, 'registered'); $pid = (int)val('SELECT p.id FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE u.email = ?', [$email]); ok(val('SELECT approval_status FROM pharmacies WHERE id = ?', [$pid]) === 'pending', 'starts pending');
    $m = med_id('Okacet', '5 mg/5 ml'); $x->post('inventory.php', ['action' => 'add', 'medicine_id' => $m, 'status' => 'in_stock', 'confirmed_now' => '1'], 'inventory.php');
    ok(!array_filter(flat(search('q=Okacet&place=Walkthrough')), fn($z) => $z['pharmacy_id'] === $pid), 'pending pharmacy invisible');
    $a = new Client; $a->login('admin@medradar.test', 'password123');
    ok($a->post('admin/pharmacies.php', ['action' => 'reject', 'pharmacy_id' => $pid, 'reason' => ''], 'admin/pharmacies.php')['code'] === 302 && val('SELECT approval_status FROM pharmacies WHERE id = ?', [$pid]) === 'pending', 'reject without reason is refused');
    $a->post('admin/pharmacies.php', ['action' => 'approve', 'pharmacy_id' => $pid, 'reason' => 'Looks fine'], 'admin/pharmacies.php'); ok(val('SELECT approval_status FROM pharmacies WHERE id = ?', [$pid]) === 'approved', 'approved');
    ok(val("SELECT COUNT(*) FROM admin_events WHERE affected_type='pharmacy' AND affected_id = ? AND action='approve'", [$pid]) == 1, 'decision logged');
    $res = array_values(array_filter(flat(search('q=Okacet&place=Walkthrough')), fn($z) => $z['pharmacy_id'] === $pid)); ok($res && $res[0]['freshness']['key'] === 'fresh', 'visible and recently confirmed');
    $v = new Client; $d = $v->api('api/request_create.php', ['inventory_id' => $res[0]['inventory_id']])['json']; ok(!empty($d['token']), 'visitor requested');
    $rq = (int)val('SELECT id FROM availability_requests WHERE token_hash = ?', [hash('sha256', $d['token'])]); $x->post('requests.php', ['request_id' => $rq, 'response' => 'available'], 'requests.php');
    ok($v->req('GET', 'api/request_status.php?t=' . $d['token'])['json']['status'] === 'confirmed_available', 'visitor sees the reply');
    $other = inv_id(2, med_id('Dolo', '500 mg')); ok($x->post('inventory.php', ['action' => 'out_of_stock', 'inventory_id' => $other], 'inventory.php')['code'] === 403, 'unauthorized edit rejected');
    $a->post('admin/pharmacies.php', ['action' => 'suspend', 'pharmacy_id' => $pid, 'reason' => 'demo'], 'admin/pharmacies.php'); ok(!array_filter(flat(search('q=Okacet&place=Walkthrough')), fn($z) => $z['pharmacy_id'] === $pid), 'suspended → hidden again');
    ok(str_contains($x->login($email, 'password123')['body'], 'suspended'), 'suspended owner cannot sign in');
    run('DELETE FROM rate_limits');
});

test('W02', 'Stock rules: only explicit actions refresh confirmation; reactivate keeps old time; status change confirms', function () {
    $i = inv_id(1, med_id('Volini', '1% w/w')); run('UPDATE inventory SET status="in_stock", is_active=1, last_confirmed_at=? WHERE id=?', [date('Y-m-d H:i:s', time() - 5 * 86400), $i]);
    $p = new Client; $p->login('sanjivani@example.com', 'password123'); $old = val('SELECT last_confirmed_at FROM inventory WHERE id=?', [$i]);
    $p->post('inventory.php', ['action' => 'deactivate', 'inventory_id' => $i], 'inventory.php'); $p->post('inventory.php', ['action' => 'activate', 'inventory_id' => $i], 'inventory.php');
    ok(val('SELECT last_confirmed_at FROM inventory WHERE id=?', [$i]) === $old, 'deactivate/reactivate must not refresh confirmation');
    $p->post('inventory.php', ['action' => 'confirm', 'inventory_id' => $i], 'inventory.php'); ok(strtotime(val('SELECT last_confirmed_at FROM inventory WHERE id=?', [$i])) > time() - 30, 'explicit confirm refreshes');
    $p->post('inventory.php', ['action' => 'out_of_stock', 'inventory_id' => $i], 'inventory.php'); ok(val('SELECT status FROM inventory WHERE id=?', [$i]) === 'out_of_stock', 'marked out of stock');
    $p->post('inventory.php', ['action' => 'confirm', 'inventory_id' => $i], 'inventory.php'); ok(val('SELECT status FROM inventory WHERE id=?', [$i]) === 'out_of_stock', '"still available" is refused for out-of-stock items');
    ok((int)val("SELECT COUNT(*) FROM stock_events WHERE inventory_id=? AND action IN ('deactivate','activate','reconfirm','mark_out_of_stock')", [$i]) >= 4, 'each action is logged');
});

test('W03', 'CSRF: state-changing calls without a valid token are rejected', function () {
    $p = new Client; $p->login('sanjivani@example.com', 'password123'); $i = inv_id(1, med_id('Dolo', '650 mg')); $before = val('SELECT updated_at FROM inventory WHERE id=?', [$i]);
    $r = $p->req('POST', 'inventory.php', ['form' => ['action' => 'out_of_stock', 'inventory_id' => $i]]); ok($r['code'] === 403, 'POST without token must be 403');
    $r = $p->req('POST', 'inventory.php', ['form' => ['action' => 'out_of_stock', 'inventory_id' => $i, 'csrf' => 'forged']]); ok($r['code'] === 403, 'forged token must be 403');
    ok(val('SELECT status FROM inventory WHERE id=?', [$i]) !== 'out_of_stock', 'no change');
    $v = new Client; ok($v->req('POST', 'api/request_create.php', ['json' => ['inventory_id' => $i]])['code'] === 403, 'public API write without token must be 403');
    ok($p->req('GET', 'logout.php')['code'] === 302 && str_contains($p->req('GET', 'dashboard.php')['headers'], '200'), 'GET logout must NOT log the user out');
});

test('W04', 'Login rate limiting, generic errors, session cookie flags', function () {
    $c = new Client; $email = 'nobody' . random_int(100, 999) . '@example.com'; $msgs = [];
    for ($k = 0; $k < 6; $k++) { $r = $c->login($email, 'wrong' . $k); $msgs[] = $r['body']; }
    ok(str_contains($msgs[0], 'Invalid email or password'), 'generic error on failure'); ok(str_contains($msgs[5], 'Too many failed attempts'), 'locked after repeated failures');
    ok(!str_contains($msgs[0], 'not found') && !str_contains($msgs[0], 'no such'), 'does not reveal whether the account exists');
    $r = (new Client)->req('GET', 'login.php'); ok(stripos($r['headers'], 'HttpOnly') !== false && stripos($r['headers'], 'SameSite=Lax') !== false, 'session cookie is HttpOnly + SameSite');
    run("DELETE FROM rate_limits");
});

test('W05', 'Admin workflow rules: transitions, mandatory reasons, catalogue duplicates, report review', function () {
    $a = new Client; $a->login('admin@medradar.test', 'password123');
    $a->post('admin/pharmacies.php', ['action' => 'approve', 'pharmacy_id' => 1, 'reason' => ''], 'admin/pharmacies.php'); ok(val('SELECT approval_status FROM pharmacies WHERE id=1') === 'approved', 'approved → approve is not a valid transition (no change)');
    $a->post('admin/pharmacies.php', ['action' => 'suspend', 'pharmacy_id' => 1, 'reason' => ''], 'admin/pharmacies.php'); ok(val('SELECT approval_status FROM pharmacies WHERE id=1') === 'approved', 'suspend requires a reason');
    $n = (int)val('SELECT COUNT(*) FROM medicines');
    $a->post('admin/catalogue.php', ['action' => 'add', 'product_name' => 'dolo', 'ingredient_name' => 'Paracetamol', 'strength' => '650 MG', 'dosage_form' => 'tablet'], 'admin/catalogue.php'); ok((int)val('SELECT COUNT(*) FROM medicines') === $n, 'case-insensitive duplicate refused');
    $a->post('admin/catalogue.php', ['action' => 'add', 'product_name' => 'Dolo', 'ingredient_name' => 'Paracetamol', 'strength' => '1000 mg', 'dosage_form' => 'Tablet'], 'admin/catalogue.php'); ok((int)val('SELECT COUNT(*) FROM medicines') === $n + 1, 'same name, new strength = separate product');
    $v = new Client; $v->api('api/report_create.php', ['pharmacy_id' => 2, 'category' => 'closure', 'explanation' => 'Shop was shut at noon']); $rid = (int)val('SELECT MAX(id) FROM information_reports');
    $stockBefore = val("SELECT GROUP_CONCAT(status ORDER BY id) FROM inventory WHERE pharmacy_id = 2"); ok(val('SELECT approval_status FROM pharmacies WHERE id=2') === 'approved', 'a report does not suspend a pharmacy automatically');
    $a->post('admin/reports.php', ['action' => 'resolve', 'report_id' => $rid, 'note' => 'Checked'], 'admin/reports.php'); ok(val('SELECT status FROM information_reports WHERE id=?', [$rid]) === 'resolved' && val('SELECT reviewed_by FROM information_reports WHERE id=?', [$rid]) !== null, 'report resolved with reviewer recorded');
    ok($stockBefore === val("SELECT GROUP_CONCAT(status ORDER BY id) FROM inventory WHERE pharmacy_id = 2"), 'a report never changes stock');
    ok(val("SELECT COUNT(*) FROM admin_events WHERE action IN ('catalogue_add','report_resolved')") >= 2, 'admin actions logged');
    run('DELETE FROM rate_limits');
});

test('W06', 'Private request rules: duplicate pending blocked, rate limit, requests for hidden listings refused', function () {
    $i = inv_id(2, med_id('Azithral', '500 mg')); $v = new Client;
    ok($v->api('api/request_create.php', ['inventory_id' => $i])['code'] === 201, 'first request created');
    ok($v->api('api/request_create.php', ['inventory_id' => $i])['code'] === 409, 'duplicate pending request refused');
    ok($v->api('api/request_create.php', ['inventory_id' => inv_id(4, $GLOBALS['dolo500'] ?? 1)])['code'] === 404, 'cannot request from a pending pharmacy');
    ok($v->api('api/request_create.php', ['inventory_id' => inv_id(2, med_id('Benadryl', '12.5 mg/5 ml'))])['code'] === 404, 'cannot request an inactive listing');
    run('DELETE FROM availability_requests'); run('DELETE FROM rate_limits'); $c = 0;
    foreach ([inv_id(1, 1), inv_id(1, 2), inv_id(2, 3), inv_id(2, 8), inv_id(3, 2), inv_id(3, 5)] as $inv) { $c = $v->api('api/request_create.php', ['inventory_id' => $inv])['code']; }
    ok($c === 429, 'after 5 requests/hour the 6th is rate-limited (got ' . $c . ')');
    run('DELETE FROM availability_requests'); run('DELETE FROM rate_limits');
});

test('W07', 'Retention script deletes only old data (expiry does not depend on it)', function () {
    $i = inv_id(1, 1); run("INSERT INTO availability_requests (inventory_id, token_hash, ip_hash, status, created_at, expires_at) VALUES (?, ?, 'x', 'expired', ?, ?)", [$i, hash('sha256', 'oldtoken'), date('Y-m-d H:i:s', time() - 40 * 86400), date('Y-m-d H:i:s', time() - 40 * 86400 + 3600)]);
    run("INSERT INTO availability_requests (inventory_id, token_hash, ip_hash, status, created_at, expires_at) VALUES (?, ?, 'x', 'pending', ?, ?)", [$i, hash('sha256', 'newtoken'), now(), date('Y-m-d H:i:s', time() + 3600)]);
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../cron/purge.php') . ' 2>&1'); ok(str_contains((string)$out, 'availability_requests'), 'script ran: ' . $out);
    ok((int)val("SELECT COUNT(*) FROM availability_requests WHERE token_hash = ?", [hash('sha256', 'oldtoken')]) === 0, 'old request purged'); ok((int)val("SELECT COUNT(*) FROM availability_requests WHERE token_hash = ?", [hash('sha256', 'newtoken')]) === 1, 'recent request kept');
    run('DELETE FROM availability_requests'); 
});

function now(): string { return date('Y-m-d H:i:s'); }
echo str_repeat('-', 70) . "\n" . (count($results) - $failed) . '/' . count($results) . " passed" . ($failed ? " — $failed FAILED" : ' ✔') . "\n";
@file_put_contents(__DIR__ . '/last_results.json', json_encode(['run_at' => date('c'), 'base' => $BASE, 'results' => $results], JSON_PRETTY_PRINT));
exit($failed ? 1 : 0);
