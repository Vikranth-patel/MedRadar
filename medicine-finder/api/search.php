<?php
// api/search.php — public, read-only.
//   GET ?mode=suggest&q=dol                       -> autocomplete (product, strength, form)
//   GET ?q=dolo | ?medicine_id=2  [&place=..&availability=..&fresh=1&sort=..]  -> grouped results
define('IS_API', true);
require_once __DIR__ . '/../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_out(['error' => 'Method not allowed.'], 405);

$qs = clean($_GET['q'] ?? '', 80);
$like = '%' . addcslashes($qs, '%_\\') . '%';

if (($_GET['mode'] ?? '') === 'suggest') {
    if (mb_strlen($qs) < 2) json_out(['suggestions' => []]);
    $rows = q('SELECT id, product_name, ingredient_name, strength, dosage_form FROM medicines
               WHERE archived = 0 AND (product_name LIKE ? OR ingredient_name LIKE ?)
               ORDER BY product_name, strength LIMIT 8', [$like, $like])->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['label'] = med_label($r); } unset($r);
    json_out(['suggestions' => $rows]);
}

// ---- find matching catalogue products (distinct products stay separate) ----
$mid = int_val($_GET['medicine_id'] ?? 0);
if ($mid > 0) {
    $meds = q('SELECT * FROM medicines WHERE id = ? AND archived = 0', [$mid])->fetchAll();
} else {
    if (mb_strlen($qs) < 2) json_out(['error' => 'Please type at least 2 characters.'], 422);
    $meds = q('SELECT * FROM medicines WHERE archived = 0 AND (product_name LIKE ? OR ingredient_name LIKE ?)
               ORDER BY product_name, strength LIMIT 12', [$like, $like])->fetchAll();
}
if (!$meds) json_out(['query' => $qs, 'medicines' => [], 'total' => 0,
    'message' => 'No product in the catalogue matches your search. MedRadar does not suggest substitutes — please check the spelling or ask your pharmacist or doctor.']);

// ---- filters ----
$place = clean($_GET['place'] ?? '', 60);
$avail = $_GET['availability'] ?? 'any';
$onlyFresh = ($_GET['fresh'] ?? '') === '1';
$sort = ($_GET['sort'] ?? 'confirmed') === 'name' ? 'name' : 'confirmed';

$ids = array_map(fn($m) => (int)$m['id'], $meds);
$ph = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
$sql = "SELECT i.id AS inventory_id, i.medicine_id, i.status, i.last_confirmed_at, i.is_active,
               p.id AS pharmacy_id, p.name AS pharmacy_name, p.address, p.locality, p.pin_code, p.phone, p.hours, p.closed_until, p.closure_note
        FROM inventory i JOIN pharmacies p ON p.id = i.pharmacy_id JOIN users u ON u.id = p.owner_user_id JOIN medicines m ON m.id = i.medicine_id
        WHERE i.medicine_id IN ($ph) AND i.is_active = 1 AND m.archived = 0 AND p.approval_status = 'approved' AND u.status = 'active'";
if ($place !== '') {
    if (valid_pin($place)) { $sql .= ' AND p.pin_code = ?'; $params[] = $place; }
    else { $sql .= ' AND p.locality LIKE ?'; $params[] = '%' . addcslashes($place, '%_\\') . '%'; }
}
if (in_array($avail, ['in_stock', 'low_stock', 'out_of_stock'], true)) { $sql .= ' AND i.status = ?'; $params[] = $avail; }
$sql .= $sort === 'name'
    ? ' ORDER BY p.name'
    : " ORDER BY (i.status = 'out_of_stock'), (i.last_confirmed_at IS NULL), i.last_confirmed_at DESC, p.name";

$byMed = [];
foreach (q($sql, $params)->fetchAll() as $r) {
    $f = freshness($r);
    if ($onlyFresh && $f['key'] !== 'fresh') continue;
    $today = pharmacy_today($r);
    $byMed[(int)$r['medicine_id']][] = [
        'inventory_id' => (int)$r['inventory_id'], 'pharmacy_id' => (int)$r['pharmacy_id'], 'pharmacy_name' => $r['pharmacy_name'],
        'address' => $r['address'], 'locality' => $r['locality'], 'pin_code' => $r['pin_code'], 'phone' => $r['phone'],
        'status' => $r['status'], 'status_text' => status_text($r['status']),
        'freshness' => $f, 'hours_today' => $today['text'], 'closed_today' => $today['closed'],
        'approval' => approval_label('approved'),
    ];
}
$out = []; $total = 0;
foreach ($meds as $m) {
    $res = $byMed[(int)$m['id']] ?? []; $total += count($res);
    $out[] = ['medicine' => ['id' => (int)$m['id'], 'product_name' => $m['product_name'], 'ingredient_name' => $m['ingredient_name'],
              'strength' => $m['strength'], 'dosage_form' => $m['dosage_form'], 'label' => med_label($m)], 'results' => $res];
}
json_out(['query' => $qs, 'place' => $place, 'medicines' => $out, 'total' => $total, 'fresh_hours' => FRESH_HOURS]);
