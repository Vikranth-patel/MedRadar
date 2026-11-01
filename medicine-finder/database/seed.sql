-- MedRadar demo data — ALL FICTIONAL. Pharmacies, people and phone numbers are invented.
-- Every demo account uses the password:  password123   (change before any real use!)
USE medradar;
-- Use the same timezone as the app (APP_TZ, default Asia/Kolkata) so the relative timestamps below line up.
SET time_zone = '+05:30';

SET @pw = '$2y$10$QWqEZo40gjK/LzCiE53Fqe7pUFxEVJCHhfrrdAS0rFyu6Zx3etAsO';

INSERT INTO users (id, email, password_hash, role, status) VALUES
 (1, 'admin@medradar.test',      @pw, 'admin',    'active'),
 (2, 'sanjivani@example.com',    @pw, 'pharmacy', 'active'),
 (3, 'lakecity@example.com',     @pw, 'pharmacy', 'active'),
 (4, 'hiranmagri@example.com',   @pw, 'pharmacy', 'active'),
 (5, 'pending@example.com',      @pw, 'pharmacy', 'active'),
 (6, 'suspended@example.com',    @pw, 'pharmacy', 'suspended');

SET @hrs = '{"mon":"09:00-21:00","tue":"09:00-21:00","wed":"09:00-21:00","thu":"09:00-21:00","fri":"09:00-21:00","sat":"09:00-21:00","sun":"10:00-14:00"}';
SET @hrs2 = '{"mon":"08:00-22:00","tue":"08:00-22:00","wed":"08:00-22:00","thu":"08:00-22:00","fri":"08:00-22:00","sat":"08:00-22:00","sun":"09:00-20:00"}';

INSERT INTO pharmacies (id, owner_user_id, name, contact_person, phone, address, locality, pin_code, hours, closed_until, closure_note, approval_status, review_reason, reviewed_by, reviewed_at) VALUES
 (1, 2, 'Sanjivani Medical Store (demo)', 'R. Demo',   '+91 98290 10001', 'Shop 4, Demo Market Road',      'Fatehpura',   '313001', @hrs,  NULL, NULL, 'approved',  'Demo data reviewed', 1, NOW()),
 (2, 3, 'Lake City Pharmacy (demo)',      'S. Sample', '+91 98290 10002', '12 Sample Lake View Marg',      'Bhupalpura',  '313001', @hrs2, NULL, NULL, 'approved',  'Demo data reviewed', 1, NOW()),
 (3, 4, 'Hiran Magri Chemist (demo)',     'K. Example','+91 98290 10003', 'Plot 9, Example Sector Road',   'Hiran Magri', '313002', @hrs,  DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Closed for maintenance (demo)', 'approved', 'Demo data reviewed', 1, NOW()),
 (4, 5, 'New Dawn Pharmacy (demo)',       'A. Pending','+91 98290 10004', 'Unit 2, Pending Plaza',         'Sukher',      '313004', @hrs,  NULL, NULL, 'pending',   NULL, NULL, NULL),
 (5, 6, 'Old Town Medicals (demo)',       'M. Hold',   '+91 98290 10005', '7 Suspended Street',            'Fatehpura',   '313001', @hrs,  NULL, NULL, 'suspended', 'Demo: suspended after review', 1, NOW());

INSERT INTO medicines (id, product_name, ingredient_name, strength, dosage_form, manufacturer) VALUES
 (1,  'Dolo',            'Paracetamol',         '500 mg',   'Tablet',   'Demo Pharma'),
 (2,  'Dolo',            'Paracetamol',         '650 mg',   'Tablet',   'Demo Pharma'),
 (3,  'Crocin Advance',  'Paracetamol',         '500 mg',   'Tablet',   'Demo Health'),
 (4,  'Okacet',          'Cetirizine',          '10 mg',    'Tablet',   'Demo Labs'),
 (5,  'Okacet',          'Cetirizine',          '5 mg/5 ml','Syrup',    'Demo Labs'),
 (6,  'Pan 40',          'Pantoprazole',        '40 mg',    'Tablet',   'Demo Pharma'),
 (7,  'Glycomet',        'Metformin',           '500 mg',   'Tablet',   'Demo Labs'),
 (8,  'Azithral',        'Azithromycin',        '500 mg',   'Tablet',   'Demo Health'),
 (9,  'Brufen',          'Ibuprofen',           '400 mg',   'Tablet',   'Demo Pharma'),
 (10, 'Electral',        'Oral rehydration salts','21.8 g', 'Powder sachet','Demo Health'),
 (11, 'Volini',          'Diclofenac',          '1% w/w',   'Gel 30 g', 'Demo Labs'),
 (12, 'Betadine',        'Povidone-iodine',     '5% w/v',   'Solution 100 ml', 'Demo Labs'),
 (13, 'Shelcal',         'Calcium + Vitamin D3','500 mg',   'Tablet',   'Demo Pharma'),
 (14, 'Benadryl',        'Diphenhydramine',     '12.5 mg/5 ml','Syrup 100 ml','Demo Health'),
 (15, 'Digene',          'Antacid combination', '—',        'Chewable tablet', 'Demo Labs'),
 (16, 'Old Brand Cough', 'Dextromethorphan',    '10 mg/5 ml','Syrup',   'Demo Labs');
UPDATE medicines SET archived = 1 WHERE id = 16;   -- archived example (T14)

-- Inventory with mixed freshness: fresh (<24h), stale (>24h), never confirmed (NULL), out of stock
INSERT INTO inventory (pharmacy_id, medicine_id, status, is_active, last_confirmed_at) VALUES
 (1, 1,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
 (1, 2,  'low_stock',    1, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
 (1, 4,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (1, 6,  'in_stock',     1, NULL),
 (1, 10, 'out_of_stock', 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
 (1, 11, 'in_stock',     1, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
 (2, 1,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
 (2, 3,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
 (2, 7,  'low_stock',    1, DATE_SUB(NOW(), INTERVAL 40 HOUR)),
 (2, 8,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
 (2, 12, 'in_stock',     1, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
 (2, 14, 'in_stock',     0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),   -- deactivated listing: hidden
 (3, 2,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
 (3, 5,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
 (3, 9,  'low_stock',    1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (3, 13, 'in_stock',     1, NULL),
 (4, 1,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),   -- pending pharmacy: hidden (T05)
 (5, 1,  'in_stock',     1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),   -- suspended pharmacy: hidden (T05)
 (5, 15, 'in_stock',     1, DATE_SUB(NOW(), INTERVAL 1 HOUR));

INSERT INTO admin_events (actor_id, affected_type, affected_id, action, details) VALUES
 (1, 'pharmacy', 1, 'approve', 'Demo data reviewed'),
 (1, 'pharmacy', 2, 'approve', 'Demo data reviewed'),
 (1, 'pharmacy', 3, 'approve', 'Demo data reviewed'),
 (1, 'pharmacy', 5, 'suspend', 'Demo: suspended after review');
