<?php
/**
 * Import inventory data from FirstAid Manager JSON database
 * 
 * Imports all 45 resources + 2 fixed assets from C:\Users\theo\Desktop\multi\data\
 * into EasyRide inventory tables with proper UTF-8 encoding.
 * 
 * Run once: http://localhost/easyride/import_firstaid_data.php
 */

require_once __DIR__ . '/../../bootstrap.php';

if (!isLoggedIn() || !isSystemAdmin()) {
    die('Πρέπει να είστε συνδεδεμένος ως System Admin.');
}

$dataPath = 'C:/Users/theo/Desktop/multi/data';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Import FirstAid Data</title></head><body>";
echo "<pre style='font-family: Consolas, monospace; font-size: 14px; line-height: 1.5;'>\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Import FirstAid Manager → EasyRide Inventory          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// =============================================
// 1. CLEAR EXISTING DATA (safe - no real data yet)
// =============================================
echo "━━━ 1. Καθαρισμός υπαρχόντων δεδομένων ━━━\n";

// Delete in correct order (FK constraints)
$d = dbExecute("DELETE FROM inventory_bookings");
echo "  Διαγράφηκαν {$d} χρεώσεις\n";

$d = dbExecute("DELETE FROM inventory_notes");
echo "  Διαγράφηκαν {$d} σημειώσεις\n";

$d = dbExecute("DELETE FROM inventory_fixed_assets");
echo "  Διαγράφηκαν {$d} πάγια\n";

$d = dbExecute("DELETE FROM inventory_items");
echo "  Διαγράφηκαν {$d} υλικά\n";

$d = dbExecute("DELETE FROM inventory_categories");
echo "  Διαγράφηκαν {$d} κατηγορίες\n";

$d = dbExecute("DELETE FROM inventory_locations");
echo "  Διαγράφηκαν {$d} τοποθεσίες\n";

// Reset auto-increment
dbExecute("ALTER TABLE inventory_items AUTO_INCREMENT = 1");
dbExecute("ALTER TABLE inventory_categories AUTO_INCREMENT = 1");
dbExecute("ALTER TABLE inventory_locations AUTO_INCREMENT = 1");
dbExecute("ALTER TABLE inventory_fixed_assets AUTO_INCREMENT = 1");

echo "\n";

// =============================================
// 2. INSERT CATEGORIES (from FirstAid Manager categories.json)
// =============================================
echo "━━━ 2. Εισαγωγή κατηγοριών ━━━\n";

// Matching the FirstAid Manager categories exactly, with proper icons
$categories = [
    // id => [name, description, icon, color, sort_order]
    1 => ['Φαρμακεία',            'Ιατρικά φαρμακεία και φάρμακα',                  '💊', '#dc3545', 1],
    2 => ['Ιατρικός Εξοπλισμός',  'Ιατρικά όργανα και συσκευές',                    '🏥', '#28a745', 2],
    3 => ['Διαφημιστικό Υλικό',   'Υλικό για δημοσιότητα και ενημέρωση',            '📢', '#17a2b8', 3],
    4 => ['Ασύρματοι',            'Εξοπλισμός επικοινωνιών για δραστηριότητες',      '📡', '#007bff', 4],
    5 => ['Φορεία',               'Φορεία μεταφοράς και σανίδες ακινητοποίησης',    '🚑', '#e83e8c', 5],
    6 => ['Μπάνερ',               'Μπάνερ διαφημιστικά',                            '📋', '#6c757d', 6],
    7 => ['Εκπαιδευτικό Υλικό',   'Κούκλες CPR και εκπαιδευτικά όργανα',            '📚', '#ffc107', 7],
];

$catIdMap = []; // old_id => new_id
foreach ($categories as $oldId => $cat) {
    $newId = dbInsert(
        "INSERT INTO inventory_categories (name, description, icon, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)",
        [$cat[0], $cat[1], $cat[2], $cat[3], $cat[4]]
    );
    $catIdMap[$oldId] = $newId;
    echo "  ✅ Κατηγορία #{$newId}: {$cat[2]} {$cat[0]}\n";
}

echo "\n";

// =============================================
// 3. INSERT LOCATIONS (extracted from resources)
// =============================================
echo "━━━ 3. Εισαγωγή τοποθεσιών ━━━\n";

$locations = [
    // [name, location_type, notes]
    ['Κεντρική Αποθήκη',          'warehouse', 'Κύρια αποθήκη υλικών κεντρικού κτιρίου'],
    ['Αποθήκη Ορειβασίας',        'warehouse', 'Αποθήκη εξοπλισμού τμήμα ορειβασίας'],
    ['Αποθήκη Οχημάτων',          'vehicle',   'Αποθήκη εντός οχημάτων'],
    ['Αποθήκη Εκτάκτων Αναγκών',  'warehouse', 'Αποθήκη εξοπλισμού εκτάκτων αναγκών'],
    ['Ιατρικό Τμήμα',             'room',      'Ιατρείο και χώρος ιατρικού εξοπλισμού'],
    ['Κέντρο Υποδοχής',           'room',      'Κέντρο υποδοχής μεταναστών'],
    ['Μονάδα Διάσωσης',           'room',      'Μονάδα διάσωσης και εξοπλισμού'],
    ['Ιατρείο',                   'room',      'Ιατρείο - προσωπικός χώρος ιατρού'],
    ['Κεντρικός Διάδρομος',       'room',      'Κεντρικός διάδρομος κτιρίου'],
    ['Αποθήκη Διάσωσης',          'warehouse', 'Αποθήκη εξοπλισμού διάσωσης'],
    ['Αποθήκη Εξοπλισμού',        'warehouse', 'Γενική αποθήκη εξοπλισμού και επικοινωνιών'],
    ['Τμήμα Ηρακλείου',           'room',      'Τμήμα Ηρακλείου - αποθήκη εξοπλισμού'],
];

$locMap = []; // location_name => new_id
foreach ($locations as $loc) {
    $newId = dbInsert(
        "INSERT INTO inventory_locations (name, location_type, notes) VALUES (?, ?, ?)",
        [$loc[0], $loc[1], $loc[2]]
    );
    $locMap[$loc[0]] = $newId;
    echo "  ✅ Τοποθεσία #{$newId}: {$loc[0]}\n";
}

echo "\n";

// =============================================
// 4. IMPORT RESOURCES (main inventory items)
// =============================================
echo "━━━ 4. Εισαγωγή υλικών από resources.json ━━━\n";

$resourcesJson = file_get_contents($dataPath . '/resources.json');
if ($resourcesJson === false) {
    die("❌ Αδυναμία ανάγνωσης resources.json!\n");
}
$resources = json_decode($resourcesJson, true);
if ($resources === null) {
    die("❌ Σφάλμα JSON: " . json_last_error_msg() . "\n");
}

$itemCount = 0;
$bookedCount = 0;

foreach ($resources as $res) {
    // Map category_id
    $categoryId = isset($catIdMap[$res['category_id']]) ? $catIdMap[$res['category_id']] : null;
    
    // Map location name to location_id
    $locationId = null;
    $locationName = isset($res['location']) ? trim($res['location']) : '';
    if (!empty($locationName) && isset($locMap[$locationName])) {
        $locationId = $locMap[$locationName];
    }
    
    // Map status
    $status = $res['status'] ?? 'available';
    // Ensure valid ENUM value
    if (!in_array($status, ['available', 'booked', 'maintenance', 'damaged'])) {
        $status = 'available';
    }
    // If booked_by is set but status was left as 'available' in source data (inconsistency in multi project),
    // force status to 'booked' so the item shows correctly in inventory.php
    if (!empty($res['booked_by']) && $status === 'available') {
        $status = 'booked';
    }
    
    // Booked info (denormalized)
    $bookedByName = null;
    $bookingDate = null;
    if ($status === 'booked' && !empty($res['booked_by'])) {
        $bookedByName = $res['booked_by'];
        $bookingDate = $res['booking_date'] ?? null;
        $bookedCount++;
    }
    
    // Location notes
    $locationNotes = $res['location_notes'] ?? null;
    
    // Insert item
    $newId = dbInsert(
        "INSERT INTO inventory_items 
            (barcode, name, description, category_id, department_id, location_id, location_notes, 
             status, booked_by_name, booking_date, quantity, is_active, created_at) 
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, 1, ?)",
        [
            $res['barcode'],
            $res['name'],
            $res['description'] ?? null,
            $categoryId,
            $locationId,
            $locationNotes,
            $status,
            $bookedByName,
            $bookingDate,
            $res['created_at'] ?? date('Y-m-d H:i:s')
        ]
    );
    
    $statusIcon = match($status) {
        'available'   => '🟢',
        'booked'      => '🔵',
        'maintenance' => '🟡',
        'damaged'     => '🔴',
        default       => '⚪',
    };
    
    echo "  {$statusIcon} #{$newId} [{$res['barcode']}] {$res['name']}";
    if ($bookedByName) {
        echo " → {$bookedByName}";
    }
    echo "\n";
    
    $itemCount++;
}

echo "\n  📊 Σύνολο: {$itemCount} υλικά ({$bookedCount} χρεωμένα)\n\n";

// =============================================
// 5. IMPORT FIXED ASSETS (CPR dummy, choking vest)
// =============================================
echo "━━━ 5. Εισαγωγή παγίων (fixed assets) ━━━\n";

$fixedAssetsJson = file_get_contents($dataPath . '/fixed_assets.json');
if ($fixedAssetsJson !== false) {
    $fixedAssets = json_decode($fixedAssetsJson, true);
    if ($fixedAssets && is_array($fixedAssets)) {
        foreach ($fixedAssets as $fa) {
            // Map status: checked_out → booked
            $status = ($fa['status'] ?? 'available') === 'checked_out' ? 'booked' : ($fa['status'] ?? 'available');
            if (!in_array($status, ['available', 'booked', 'maintenance', 'damaged'])) {
                $status = 'available';
            }
            
            $bookedByName = null;
            $bookingDate = null;
            if ($status === 'booked' && !empty($fa['checked_out_to'])) {
                $bookedByName = $fa['checked_out_to'];
                $bookingDate = $fa['checked_out_at'] ?? null;
            }
            
            // Find or map location
            $locationId = null;
            $locationName = $fa['location'] ?? '';
            if (!empty($locationName) && isset($locMap[$locationName])) {
                $locationId = $locMap[$locationName];
            }
            
            // Category: Εκπαιδευτικό Υλικό (7)
            $categoryId = $catIdMap[7] ?? null;
            
            $newId = dbInsert(
                "INSERT INTO inventory_items 
                    (barcode, name, description, category_id, department_id, location_id, location_notes,
                     status, booked_by_name, booking_date, quantity, is_active, created_at)
                 VALUES (?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, 1, 1, ?)",
                [
                    $fa['barcode'],
                    $fa['name'],
                    'Πάγιο εξοπλισμού - ' . $fa['name'],
                    $categoryId,
                    $locationId,
                    $status,
                    $bookedByName,
                    $bookingDate,
                    $fa['created_at'] ?? date('Y-m-d H:i:s')
                ]
            );
            
            // Also add to fixed_assets table
            dbInsert(
                "INSERT INTO inventory_fixed_assets 
                    (item_id, serial_number, purchase_date, warranty_until, supplier) 
                 VALUES (?, ?, ?, NULL, NULL)",
                [$newId, $fa['barcode'], date('Y-m-d')]
            );
            
            $statusIcon = $status === 'booked' ? '🔵' : '🟢';
            echo "  {$statusIcon} #{$newId} [{$fa['barcode']}] {$fa['name']}";
            if ($bookedByName) {
                echo " → {$bookedByName}";
            }
            echo "\n";
            $itemCount++;
        }
        echo "\n  📊 Πάγια: " . count($fixedAssets) . " εισαγόμενα\n\n";
    }
}

// =============================================
// 6. SUMMARY
// =============================================
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  ✅ ΕΙΣΑΓΩΓΗ ΟΛΟΚΛΗΡΩΘΗΚΕ!                                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

$totalItems = dbFetchValue("SELECT COUNT(*) FROM inventory_items");
$totalCats  = dbFetchValue("SELECT COUNT(*) FROM inventory_categories");
$totalLocs  = dbFetchValue("SELECT COUNT(*) FROM inventory_locations");
$totalAvail = dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status = 'available'");
$totalBooked = dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status = 'booked'");
$totalMaint = dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status = 'maintenance'");
$totalDmg   = dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status = 'damaged'");

echo "║  Κατηγορίες:  {$totalCats}                                       ║\n";
echo "║  Τοποθεσίες:  {$totalLocs}                                      ║\n";
echo "║  Υλικά:       {$totalItems}                                      ║\n";
echo "║                                                              ║\n";
echo "║  🟢 Διαθέσιμα:    {$totalAvail}                                   ║\n";
echo "║  🔵 Χρεωμένα:     {$totalBooked}                                   ║\n";
echo "║  🟡 Συντήρηση:    {$totalMaint}                                    ║\n";
echo "║  🔴 Κατεστραμμένα: {$totalDmg}                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

echo "</pre>\n";
echo "<p style='font-size:16px; margin:20px;'>";
echo "<a href='inventory.php'>→ Δείτε τα υλικά</a> | ";
echo "<a href='inventory-categories.php'>→ Κατηγορίες</a>";
echo "</p>\n";
echo "</body></html>";
