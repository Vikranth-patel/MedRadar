<?php
// api/report_create.php — POST {pharmacy_id, inventory_id?, category, explanation?}
// A report never changes stock or suspends a pharmacy; an administrator reviews it.
define('IS_API', true);
require_once __DIR__ . '/../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Method not allowed.'], 405);
require_csrf(true);

$b = body();
$pid = int_val($b['pharmacy_id'] ?? 0);
$inv = int_val($b['inventory_id'] ?? 0);
$cat = (string)($b['category'] ?? '');
$exp = clean($b['explanation'] ?? '', 300);

if (!in_array($cat, ['stock', 'contact', 'address', 'closure', 'misleading'], true)) json_out(['error' => 'Please choose what is incorrect.'], 422);
if (rate_blocked('report', client_ip(), 5, 3600)) json_out(['error' => 'Too many reports from your connection. Please try again later.'], 429);

$ph = q("SELECT p.id FROM pharmacies p JOIN users u ON u.id = p.owner_user_id WHERE p.id = ? AND p.approval_status = 'approved' AND u.status = 'active'", [$pid])->fetch();
if (!$ph) json_out(['error' => 'Pharmacy not found.'], 404);
if ($inv > 0 && !q('SELECT 1 FROM inventory WHERE id = ? AND pharmacy_id = ?', [$inv, $pid])->fetch()) json_out(['error' => 'That product is not listed by this pharmacy.'], 422);

q('INSERT INTO information_reports (pharmacy_id, inventory_id, category, explanation, ip_hash, created_at) VALUES (?,?,?,?,?,?)',
  [$pid, $inv ?: null, $cat, $exp !== '' ? $exp : null, ip_hash(), now()]);
rate_hit('report', client_ip());
json_out(['ok' => true, 'message' => 'Thank you. An administrator will review your report. Reports do not change stock automatically.'], 201);
