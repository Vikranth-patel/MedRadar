CREATE DATABASE IF NOT EXISTS `medicine_finder`;
USE `medicine_finder`;

-- 1. Pharmacies Table
CREATE TABLE IF NOT EXISTS `pharmacies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_open` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Medicines Master Catalog
CREATE TABLE IF NOT EXISTS `medicines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `drug_name` VARCHAR(120) NOT NULL UNIQUE,
    `category` VARCHAR(80) NOT NULL,
    `dosage_form` VARCHAR(50) DEFAULT 'Injection/Tablet'
);

-- 3. Inventory Availability Mapping
CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pharmacy_id` INT NOT NULL,
    `medicine_id` INT NOT NULL,
    `status` ENUM('In Stock', 'Low Stock', 'Out of Stock') NOT NULL DEFAULT 'In Stock',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`pharmacy_id`) REFERENCES `pharmacies`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`medicine_id`) REFERENCES `medicines`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_stock` (`pharmacy_id`, `medicine_id`)
);

-- Seed Sample Data (Password for testing: 'password123')
INSERT INTO `pharmacies` (`id`, `name`, `address`, `phone`, `email`, `password_hash`, `is_open`) VALUES
(1, 'Sanjivani Chemist', 'Near City Station, Udaipur', '+91 98290 11223', 'sanjivani@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1),
(2, 'Apollo Pharmacy', 'Chetak Circle, Udaipur', '+91 98290 33445', 'apollo@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1);

INSERT INTO `medicines` (`id`, `drug_name`, `category`, `dosage_form`) VALUES
(1, 'Anti-Snake Venom (Polyvalent)', 'Emergency Critical', 'Vial (Lyophilized)'),
(2, 'Human Albumin 20%', 'Critical Care', 'Infusion Bottle'),
(3, 'Insulin Glargine (Lantus)', 'Diabetic Emergency', 'Cartridge/Pen'),
(4, 'Rabies Antiserum (Equine)', 'Emergency Critical', 'Vial');

INSERT INTO `inventory` (`pharmacy_id`, `medicine_id`, `status`, `last_updated`) VALUES
(1, 1, 'In Stock', NOW()),
(1, 3, 'Low Stock', NOW()),
(2, 2, 'In Stock', NOW()),
(2, 3, 'In Stock', NOW());



USE `medicine_finder`;

-- 1. Insert Essential Emergency Medicines
INSERT IGNORE INTO `medicines` (`id`, `drug_name`, `category`, `dosage_form`) VALUES
(1, 'Anti-Snake Venom (Polyvalent)', 'Emergency Critical', 'Vial (Lyophilized)'),
(2, 'Human Albumin 20%', 'Critical Care', 'Infusion Bottle 100ml'),
(3, 'Insulin Glargine (Lantus)', 'Diabetic Emergency', 'Cartridge / Pen'),
(4, 'Rabies Antiserum (Equine)', 'Emergency Critical', 'Vial 5ml'),
(5, 'Adrenaline (Epinephrine) 1mg', 'Emergency Anaphylaxis', 'Ampoule 1ml'),
(6, 'Atropine Sulphate 0.6mg/ml', 'Emergency Critical / Antidote', 'Ampoule 2ml'),
(7, 'Hydrocortisone Sodium Succinate 100mg', 'Severe Allergic / Shock', 'Vial'),
(8, 'Noradrenaline (Norepinephrine) 4mg', 'ICU Vasopressor', 'Ampoule 2ml'),
(9, 'Amiodarone 150mg/3ml', 'Cardiac Emergency / Arrhythmia', 'Ampoule'),
(10, 'Furosemide (Lasix) 20mg/2ml', 'Acute Pulmonary Edema', 'Ampoule'),
(11, 'Sodium Bicarbonate 7.5% w/v', 'Metabolic Acidosis / ICU', 'Ampoule 10ml'),
(12, 'Dextrose 25% / 50%', 'Hypoglycemic Shock', 'IV Infusion 100ml'),
(13, 'Morphine Sulphate 10mg/ml', 'Severe Trauma / Analgesic', 'Ampoule 1ml'),
(14, 'Tranexamic Acid (Pause) 500mg', 'Acute Hemorrhage / Trauma', 'Ampoule 5ml'),
(15, 'Tetanus Immunoglobulin (Human) 250 IU', 'Prophylaxis / Trauma', 'Prefilled Syringe');

-- 2. Add More Local Udaipur Pharmacies for Realistic Geographical Spread
INSERT IGNORE INTO `pharmacies` (`id`, `name`, `address`, `phone`, `email`, `password_hash`, `is_open`) VALUES
(1, 'Sanjivani Chemist', 'Near City Station, Udaipur', '+91 98290 11223', 'sanjivani@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1),
(2, 'Apollo Pharmacy', 'Chetak Circle, Udaipur', '+91 98290 33445', 'apollo@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1),
(3, 'MedPlus Pharmacy', 'Surajpole, Udaipur', '+91 98291 55667', 'medplus@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1),
(4, 'Geetanjali Hospital Pharmacy', 'Hiran Magri Sector 4, Udaipur', '+91 98292 77889', 'geetanjali@example.com', '$2y$10$4y9pBhyJk46Gf8k6lBfK1OQ5B2Fq4pB0Gf8k6lBfK1OQ5B2Fq4pB0', 1);

-- 3. Map Diverse Inventory Availability Across Pharmacies
INSERT INTO `inventory` (`pharmacy_id`, `medicine_id`, `status`, `last_updated`) VALUES
-- Sanjivani Chemist
(1, 1, 'In Stock', NOW()),
(1, 3, 'Low Stock', NOW()),
(1, 5, 'In Stock', NOW()),
(1, 7, 'In Stock', NOW()),
(1, 10, 'In Stock', NOW()),
(1, 14, 'Low Stock', NOW()),

-- Apollo Pharmacy
(2, 2, 'In Stock', NOW()),
(2, 3, 'In Stock', NOW()),
(2, 6, 'In Stock', NOW()),
(2, 8, 'Low Stock', NOW()),
(2, 9, 'In Stock', NOW()),
(2, 12, 'In Stock', NOW()),

-- MedPlus Surajpole
(3, 1, 'Out of Stock', NOW()),
(3, 4, 'In Stock', NOW()),
(3, 5, 'In Stock', NOW()),
(3, 11, 'Low Stock', NOW()),
(3, 13, 'In Stock', NOW()),
(3, 15, 'In Stock', NOW()),

-- Geetanjali Hospital Pharmacy (Comprehensive emergency stock)
(4, 1, 'In Stock', NOW()),
(4, 2, 'In Stock', NOW()),
(4, 3, 'In Stock', NOW()),
(4, 4, 'In Stock', NOW()),
(4, 6, 'In Stock', NOW()),
(4, 8, 'In Stock', NOW()),
(4, 14, 'In Stock', NOW())
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `last_updated` = NOW();