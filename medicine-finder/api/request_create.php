<?php
// api/request_create.php — POST {inventory_id}: visitor asks ONE pharmacy to confirm ONE product.
// Returns a private tracking token (only its SHA-256 hash is stored). Not a reservation.
define('IS_API', true);
require_once __DIR__ . '/../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Method not allowed.'], 405);
require_csrf(true);

$in = int_val(body()['inventory_id'] ?? 0);
if ($in <= 0) json_out(['error' => 'Please choose a product listing.'], 422);

if (rate_blocked('request', client_ip(), 5, 3600)) json_out(['error' => 'Too many requests from your connection. Please try again later.'], 429);

$inv = q("SELECT i.id, i.status, m.product_name, m.strength, m.dosage_form, p.name AS pharmacy_name
          FROM inventory i JOIN pharmacies p ON p.id = i.pharmacy_id JOIN users u ON u.id = p.owner_user_id JOIN medicines m ON m.id = i.medicine_id
          WHERE i.id = ? AND i.is_active = 1 AND m.archived = 0 AND p.approval_status = 'approved' AND u.status = 'active'", [$in])->fetch();
if (!$inv) json_out(['error' => 'This listing is not available for requests.'], 404);

$dup = q("SELECT COUNT(*) FROM availability_requests WHERE inventory_id = ? AND ip_hash = ? AND status = 'pending' AND expires_at > ?", [$in, ip_hash(), now()])->fetchColumn();
if ((int)$dup > 0) json_out(['error' => 'You already have a pending request for this product at this pharmacy. Please wait for the reply or check your tracking page.'], 409);

$token = bin2hex(random_bytes(16));                       // 128-bit unpredictable token
q('INSERT INTO availability_requests (inventory_id, token_hash, ip_hash, status, created_at, expires_at) VALUES (?,?,?,?,?,?)',
  [$in, hash('sha256', $token), ip_hash(), 'pending', now(), date('Y-m-d H:i:s', time() + REQUEST_TTL_HOURS * 3600)]);
rate_hit('request', client_ip());

json_out([
    'ok' => true, 'token' => $token, 'track_url' => 'track.php?t=' . $token,
    'expires_in_hours' => REQUEST_TTL_HOURS,
    'product' => med_label($inv), 'pharmacy' => $inv['pharmacy_name'],
    'notice' => 'Keep this link private. A confirmation is not a reservation and a reply is not guaranteed.',
], 201);
