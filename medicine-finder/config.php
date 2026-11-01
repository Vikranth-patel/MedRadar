<?php
// config.php — settings. Secrets come from a local ".env" file (not committed to Git).
function env(string $key, $default = null) {
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = __DIR__ . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    if (isset($vars[$key])) return $vars[$key];
    $e = getenv($key);
    return ($e !== false && $e !== '') ? $e : $default;
}

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'medradar'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', 'root'));
define('APP_KEY', env('APP_KEY', 'dev-only-change-me'));
define('APP_TZ', env('APP_TZ', 'Asia/Kolkata'));

// ---- Documented policies (prototype policies, not guarantees) ----
define('FRESH_HOURS',       (int) env('FRESH_HOURS', 24));        // stock older than this = "stale" (uncertain)
define('REQUEST_TTL_HOURS', (int) env('REQUEST_TTL_HOURS', 4));   // confirmation request validity
define('SESSION_IDLE_MIN',  (int) env('SESSION_IDLE_MINUTES', 30));
define('SESSION_MAX_HOURS', 8);
define('REQUEST_RETENTION_DAYS', 30);
define('REPORT_RETENTION_DAYS', 180);
define('EVENT_RETENTION_DAYS', 365);
