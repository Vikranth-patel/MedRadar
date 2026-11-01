<?php
// cron/purge.php — data-retention clean-up. Run from the command line (e.g. daily via Task Scheduler / cron):
//   php cron/purge.php
// Expiry of requests does NOT depend on this script (it is evaluated on every read), this only deletes old data.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('IS_API', true);
require_once __DIR__ . '/../inc/bootstrap.php';

$d = fn(int $days) => date('Y-m-d H:i:s', time() - $days * 86400);
$jobs = [
    'availability_requests' => ["DELETE FROM availability_requests WHERE created_at < ?", $d(REQUEST_RETENTION_DAYS)],
    'information_reports (closed)' => ["DELETE FROM information_reports WHERE status <> 'open' AND reviewed_at < ?", $d(REPORT_RETENTION_DAYS)],
    'stock_events' => ["DELETE FROM stock_events WHERE created_at < ?", $d(EVENT_RETENTION_DAYS)],
    'admin_events' => ["DELETE FROM admin_events WHERE created_at < ?", $d(EVENT_RETENTION_DAYS)],
    'rate_limits' => ["DELETE FROM rate_limits WHERE created_at < ?", $d(1)],
];
foreach ($jobs as $name => [$sql, $cut]) { echo str_pad($name, 32) . q($sql, [$cut])->rowCount() . " rows deleted\n"; }
