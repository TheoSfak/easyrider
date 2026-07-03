SET NAMES utf8mb4;

UPDATE settings
SET setting_value = 'EasyRide'
WHERE setting_key IN ('app_name', 'smtp_from_name');

UPDATE settings
SET setting_value = 'Λέσχη Μοτοσικλετιστών'
WHERE setting_key = 'app_description';

UPDATE settings
SET setting_value = '0'
WHERE setting_key IN ('points_enabled', 'achievements_enabled');

INSERT INTO settings (setting_key, setting_value) VALUES
('training_nav_enabled', '0'),
('inventory_nav_enabled', '0'),
('gamification_nav_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

UPDATE mission_types
SET name = 'Βόλτα',
    description = 'Οργανωμένη διαδρομή μελών',
    icon = 'bi-signpost-split',
    color = 'primary'
WHERE id = 1;

UPDATE mission_types
SET name = 'Εκδρομή',
    description = 'Μεγαλύτερη διαδρομή ή πολυήμερη εξόρμηση',
    icon = 'bi-map',
    color = 'success'
WHERE id = 2;

UPDATE mission_types
SET name = 'Συνάντηση',
    description = 'Συνάντηση λέσχης ή ενημέρωση μελών',
    icon = 'bi-people',
    color = 'info'
WHERE id = 3;

UPDATE mission_types
SET name = 'Υποστήριξη',
    description = 'Δράση με ανάγκη συντονισμού και υποστήριξης',
    icon = 'bi-tools',
    color = 'warning'
WHERE id = 4;

UPDATE mission_types
SET name = 'Εκδήλωση',
    description = 'Εκδήλωση, παρουσία ή συνεργασία λέσχης',
    icon = 'bi-calendar-event'
WHERE id = 5;

UPDATE mission_types
SET name = 'Training Ride',
    description = 'Εξάσκηση ομαδικής οδήγησης και road safety'
WHERE id = 6;
