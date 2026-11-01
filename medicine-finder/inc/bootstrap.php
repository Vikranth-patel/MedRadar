<?php
// inc/bootstrap.php — shared helpers: errors, sessions, CSRF, auth, rate limits, freshness, hours.
if (!defined('BASE')) define('BASE', '');
require_once __DIR__ . '/../config.php';
date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');          // never show PHP/DB errors to visitors
error_reporting(E_ALL);
ob_start();
if (!headers_sent()) { header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: strict-origin-when-cross-origin'); }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Safe error handling (no database traces) ---------- */
set_exception_handler(function (Throwable $e) {
    error_log('MedRadar error: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) http_response_code(500);
    if (defined('IS_API')) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
    } else {
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error</title>'
           . '<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:520px;margin:auto"><h1>Something went wrong</h1>'
           . '<p>Please try again in a moment.</p><p><a href="' . h(BASE) . 'index.php">Back to MedRadar</a></p></body>';
    }
    exit;
});

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");   // keep NOW() consistent with PHP time
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement { $st = db()->prepare($sql); $st->execute($params); return $st; }
function now(): string { return date('Y-m-d H:i:s'); }

/* ---------- Responses ---------- */
function json_out(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function deny(int $code, string $msg, bool $json = false): void {
    if ($json || defined('IS_API')) json_out(['error' => $msg], $code);
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $code . '</title>'
       . '<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:520px;margin:auto"><h1>' . $code . '</h1><p>' . h($msg)
       . '</p><p><a href="' . h(BASE) . 'index.php">Back to MedRadar</a> · <a href="' . h(BASE) . 'login.php">Sign in</a></p></body>';
    exit;
}
function body(): array {
    static $b = null;
    if ($b !== null) return $b;
    $j = json_decode((string) file_get_contents('php://input'), true);
    return $b = is_array($j) ? $j : $_POST;
}
function redirect(string $to): void { while (ob_get_level()) ob_end_clean(); header('Location: ' . $to); exit; }

/* ---------- Sessions (hardened, idle + absolute expiry) ---------- */
session_name('medradar_sid');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
(function () {
    $t = time();
    if (isset($_SESSION['uid'])) {
        $idle = $t - ($_SESSION['last'] ?? $t);
        $age  = $t - ($_SESSION['created'] ?? $t);
        if ($idle > SESSION_IDLE_MIN * 60 || $age > SESSION_MAX_HOURS * 3600) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = [['warn', 'Your session expired. Please sign in again.']];
        }
    }
    $_SESSION['last'] = $t;
})();

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_render(): void {
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $cls = $type === 'ok' ? 'alert-ok' : ($type === 'warn' ? 'alert-warn' : 'alert-err');
        echo '<div class="alert ' . $cls . '" role="' . ($type === 'ok' ? 'status' : 'alert') . '"><span>' . h($msg) . '</span><button type="button" aria-label="Dismiss">×</button></div>';
    }
    unset($_SESSION['flash']);
}

/* ---------- CSRF ---------- */
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(): bool {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}
function require_csrf(bool $json = false): void { if (!csrf_ok()) deny(403, 'Security check failed. Please reload the page and try again.', $json); }

/* ---------- Auth ---------- */
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (empty($_SESSION['uid'])) return $u = null;
    $row = q('SELECT id, email, role, status FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
    if (!$row || $row['status'] !== 'active') { logout_user(); return $u = null; }
    return $u = $row;
}
function login_user(int $id): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $id; $_SESSION['created'] = time(); $_SESSION['last'] = time();
}
function logout_user(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);   // old session id is destroyed; a clean one keeps working this request
}
/** Role gate used by every protected page/endpoint. Unauthenticated writes get 401, wrong role gets 403. */
function require_role(string $role, bool $json = false): array {
    $u = current_user();
    if (!$u) {
        if ($json || $_SERVER['REQUEST_METHOD'] === 'POST') deny(401, 'Please sign in to continue.', $json);
        redirect(BASE . 'login.php');
    }
    if ($u['role'] !== $role) deny(403, 'You do not have access to this page.', $json);
    return $u;
}
function require_pharmacy(): array {
    $u = require_role('pharmacy');
    $p = q('SELECT * FROM pharmacies WHERE owner_user_id = ?', [$u['id']])->fetch();
    if (!$p) deny(403, 'No pharmacy profile is linked to this account.');
    return [$u, $p];
}

/* ---------- Rate limiting ---------- */
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '?'; }
function ip_hash(): string { return hash('sha256', client_ip() . APP_KEY); }
function rate_blocked(string $bucket, string $key, int $max, int $window): bool {
    $n = q('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND key_hash = ? AND created_at > ?',
           [$bucket, hash('sha256', $key . APP_KEY), date('Y-m-d H:i:s', time() - $window)])->fetchColumn();
    return (int)$n >= $max;
}
function rate_hit(string $bucket, string $key): void {
    q('INSERT INTO rate_limits (bucket, key_hash, created_at) VALUES (?, ?, ?)', [$bucket, hash('sha256', $key . APP_KEY), now()]);
    if (random_int(1, 40) === 1) q('DELETE FROM rate_limits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

/* ---------- Audit logs (never store passwords, tokens or session ids) ---------- */
function log_stock(?int $actor, ?int $inv, string $action, ?string $before, ?string $after): void {
    q('INSERT INTO stock_events (actor_id, inventory_id, action, before_value, after_value, created_at) VALUES (?,?,?,?,?,?)', [$actor, $inv, $action, $before, $after, now()]);
}
function log_admin(?int $actor, string $type, ?int $id, string $action, ?string $details = null): void {
    q('INSERT INTO admin_events (actor_id, affected_type, affected_id, action, details, created_at) VALUES (?,?,?,?,?,?)', [$actor, $type, $id, $action, $details !== null ? mb_substr($details, 0, 250) : null, now()]);
}

/* ---------- Stock status + freshness (documented policy) ---------- */
const STATUSES = ['in_stock' => ['✔', 'In stock'], 'low_stock' => ['◐', 'Low stock'], 'out_of_stock' => ['✖', 'Out of stock']];
function status_text(string $s): string { return (STATUSES[$s][0] ?? '?') . ' ' . (STATUSES[$s][1] ?? $s); }

function ago(?string $ts): string {
    if (!$ts) return 'never';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) { $m = intdiv($d, 60); return $m . ' min ago'; }
    if ($d < 86400) { $x = intdiv($d, 3600); return $x . ' hour' . ($x > 1 ? 's' : '') . ' ago'; }
    $x = intdiv($d, 86400); return $x . ' day' . ($x > 1 ? 's' : '') . ' ago';
}
function fmt_dt(?string $ts): string { return $ts ? date('j M Y, g:i A', strtotime($ts)) : '—'; }

/** Freshness of one listing. "Stale" means UNCERTAIN, never automatically "out of stock". */
function freshness(array $r): array {
    $at = $r['last_confirmed_at'] ?? null;
    if (($r['status'] ?? '') === 'out_of_stock') {
        return ['key' => 'out', 'label' => 'Reported out of stock', 'detail' => $at ? 'Reported ' . ago($at) . ' · ' . fmt_dt($at) : 'Reported by the pharmacy', 'at' => $at];
    }
    if (!$at) return ['key' => 'never', 'label' => 'Never confirmed', 'detail' => 'This pharmacy has not confirmed this item yet — availability is uncertain', 'at' => null];
    if (time() - strtotime($at) <= FRESH_HOURS * 3600) {
        return ['key' => 'fresh', 'label' => 'Recently confirmed', 'detail' => 'Confirmed ' . ago($at) . ' · ' . fmt_dt($at), 'at' => $at];
    }
    return ['key' => 'stale', 'label' => 'Stale — may have changed', 'detail' => 'Last confirmed ' . ago($at) . ' (' . fmt_dt($at) . ') — availability is uncertain, not necessarily out of stock', 'at' => $at];
}
function needs_reconfirm(array $r): bool {
    return (int)$r['is_active'] === 1 && $r['status'] !== 'out_of_stock'
        && (empty($r['last_confirmed_at']) || time() - strtotime($r['last_confirmed_at']) > FRESH_HOURS * 3600);
}
function fresh_cutoff(): string { return date('Y-m-d H:i:s', time() - FRESH_HOURS * 3600); }

/* ---------- Availability requests ---------- */
const REQ_LABELS = ['pending' => 'Pending', 'confirmed_available' => 'Confirmed available', 'confirmed_unavailable' => 'Confirmed unavailable', 'cannot_confirm' => 'Cannot confirm', 'expired' => 'Expired'];
/** Expiry is evaluated on every read/update, so it never depends on a background job. */
function effective_request_status(array $r): string {
    if ($r['status'] === 'pending' && strtotime($r['expires_at']) <= time()) {
        q("UPDATE availability_requests SET status = 'expired' WHERE id = ? AND status = 'pending'", [$r['id']]);
        return 'expired';
    }
    return $r['status'];
}

/* ---------- Catalogue labels ---------- */
function med_label(array $m): string { return trim($m['product_name'] . ' ' . $m['strength'] . ' ' . $m['dosage_form']); }

/* ---------- Opening hours ---------- */
const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
function hours_decode(?string $json): array {
    $d = json_decode((string)$json, true) ?: []; $out = [];
    foreach (DAYS as $k => $_) { $v = $d[$k] ?? ''; $out[$k] = preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $v) ? $v : ''; }
    return $out;
}
function hours_text(string $v): string { return $v === '' ? 'Closed' : date('g:i A', strtotime(substr($v, 0, 5))) . ' – ' . date('g:i A', strtotime(substr($v, 6, 5))); }
/** @return array{0:?string,1:?string} [json, error] from the hours form */
function hours_from_post(array $p): array {
    $out = [];
    foreach (DAYS as $k => $_) {
        if (!empty($p['closed'][$k])) { $out[$k] = ''; continue; }
        $o = $p['open'][$k] ?? ''; $c = $p['close'][$k] ?? '';
        if (!preg_match('/^\d{2}:\d{2}$/', $o) || !preg_match('/^\d{2}:\d{2}$/', $c) || $c <= $o) return [null, 'Please check the opening hours: closing time must be after opening time (' . DAYS[$k] . ').'];
        $out[$k] = "$o-$c";
    }
    return [json_encode($out), null];
}
function pharmacy_today(array $p): array {
    if (!empty($p['closed_until']) && $p['closed_until'] >= date('Y-m-d')) {
        return ['closed' => true, 'text' => 'Temporarily closed until ' . date('j M', strtotime($p['closed_until'])) . (!empty($p['closure_note']) ? ' — ' . $p['closure_note'] : '')];
    }
    $v = hours_decode($p['hours'] ?? '')[strtolower(date('D'))] ?? '';
    return ['closed' => $v === '', 'text' => 'Today: ' . hours_text($v)];
}

/* ---------- Validation helpers ---------- */
/** Strict integer from user input: only real ints or digit-only strings count (arrays/odd types → 0). */
function int_val($v): int { return (is_int($v) || (is_string($v) && ctype_digit($v) && strlen($v) < 10)) ? (int)$v : 0; }
function str($v): string { return is_string($v) ? $v : ''; }   // arrays / odd types from hostile input become ''
function clean($s, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', str($s))), 0, $max); }
function valid_phone(string $p): bool { return (bool) preg_match('/^[0-9+\-\s()]{7,20}$/', $p); }
function valid_pin(string $p): bool { return (bool) preg_match('/^\d{6}$/', $p); }
function approval_label(string $s): string { return ['pending' => 'Pending review', 'approved' => 'Admin-approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'][$s] ?? $s; }
