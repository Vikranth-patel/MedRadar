<?php
// api/request_status.php — GET ?t=<token>. Only the holder of the private token can read a request (T13).
define('IS_API', true);
require_once __DIR__ . '/../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_out(['error' => 'Method not allowed.'], 405);

$t = str($_GET['t'] ?? '');
if (rate_blocked('status', client_ip(), 120, 600)) json_out(['error' => 'Too many checks. Please wait a few minutes.'], 429);
rate_hit('status', client_ip());
if (!preg_match('/^[a-f0-9]{32}$/', $t)) json_out(['error' => 'Request not found.'], 404);

$r = q("SELECT r.id, r.status, r.response_note, r.created_at, r.expires_at, r.responded_at,
               m.product_name, m.strength, m.dosage_form, p.name AS pharmacy_name, p.phone, p.address
        FROM availability_requests r JOIN inventory i ON i.id = r.inventory_id JOIN medicines m ON m.id = i.medicine_id JOIN pharmacies p ON p.id = i.pharmacy_id
        WHERE r.token_hash = ?", [hash('sha256', $t)])->fetch();
if (!$r) json_out(['error' => 'Request not found.'], 404);

$status = effective_request_status($r);     // expiry evaluated on read (T08)
json_out([
    'status' => $status, 'status_label' => REQ_LABELS[$status],
    'product' => med_label($r), 'pharmacy' => $r['pharmacy_name'], 'phone' => $r['phone'], 'address' => $r['address'],
    'created_at' => fmt_dt($r['created_at']), 'expires_at' => fmt_dt($r['expires_at']),
    'responded_at' => $r['responded_at'] ? fmt_dt($r['responded_at']) : null,
    'note' => $r['response_note'],
    'notice' => 'This is a pharmacy response, not a reservation. Stock can change — call the pharmacy before travelling.',
]);
