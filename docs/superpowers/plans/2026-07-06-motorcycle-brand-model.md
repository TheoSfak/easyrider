# Motorcycle Brand/Model Field Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a member's form/profile record what motorcycle they ride (brand + model), via a searchable-with-free-text field, growing an admin-curated brand/model list from real entries.

**Architecture:** Two new additive DB tables (`motorcycle_brands`, `motorcycle_models`), two new nullable FK columns on `users`. The form uses native HTML `<input list><datalist>` (no JS library). On save, brand/model names are resolved to ids — reusing an existing row (any approval state) by case-insensitive name match, or inserting a new `is_approved = 0` row. A new admin card on `settings.php` lists pending rows for approve/reject.

**Tech Stack:** PHP 8.2 procedural, MariaDB 10.4, vanilla JS, Bootstrap 5. No test framework — verification is `php -l` + manual browser walkthrough, per project convention.

## Global Constraints

- New migration must be **version 78** (last existing version is 77, in [includes/migrations.php:4478](includes/migrations.php:4478)).
- `config.php`'s `DB_SCHEMA_VERSION` must be bumped from `77` to `78` and `APP_VERSION` bumped for release (final task).
- Brand/model is explicitly **out of scope** for CSV import/export (`includes/export-functions.php`, `includes/import-functions.php`) — do not touch those files.
- Follow the existing idempotent-migration pattern: check `INFORMATION_SCHEMA` before `CREATE TABLE`/`ALTER TABLE` (tables already use `CREATE TABLE IF NOT EXISTS`, but the `users` column additions must check existence first, matching migration 77's style at [includes/migrations.php:4483-4499](includes/migrations.php:4483-4499)).
- A member's own brand/model value always displays regardless of `is_approved` state; `is_approved = 0` only hides it from *other* members' `<datalist>` suggestions.
- No automated tests exist in this project — verify via `php -l` on every touched file and a manual browser walkthrough (steps given in Task 6).

---

### Task 1: Database migration — tables, seed data, users columns

**Files:**
- Modify: `includes/migrations.php` (insert new migration entry right before the closing `];` of the `$migrations` array, i.e. after the version-77 block ending at line 4501, before line 4503 `];`)
- Modify: `config.php:15`

**Interfaces:**
- Produces: tables `motorcycle_brands(id, name, is_approved, created_by, created_at)`, `motorcycle_models(id, brand_id, name, is_approved, created_by, created_at)`; columns `users.motorcycle_brand_id`, `users.motorcycle_model_id` (both `INT UNSIGNED NULL`, FK `ON DELETE SET NULL`). Later tasks read/write these directly by name.

- [ ] **Step 1: Add the migration entry**

In `includes/migrations.php`, insert this new array element immediately after the version-77 block (after the line containing only `        ],` that closes migration 77, at line 4501, and before the line `    ];` at line 4503):

```php
        [
            'version'     => 78,
            'description' => 'Add motorcycle brand/model tables and users.motorcycle_brand_id/motorcycle_model_id columns',
            'up' => function () {
                dbExecute("
                    CREATE TABLE IF NOT EXISTS motorcycle_brands (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        is_approved TINYINT(1) NOT NULL DEFAULT 1,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_brand_name (name),
                        CONSTRAINT fk_brand_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                dbExecute("
                    CREATE TABLE IF NOT EXISTS motorcycle_models (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        brand_id INT UNSIGNED NOT NULL,
                        name VARCHAR(100) NOT NULL,
                        is_approved TINYINT(1) NOT NULL DEFAULT 1,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_brand_model (brand_id, name),
                        CONSTRAINT fk_model_brand FOREIGN KEY (brand_id) REFERENCES motorcycle_brands(id) ON DELETE CASCADE,
                        CONSTRAINT fk_model_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $seedBrands = [
                    'Honda', 'Yamaha', 'Suzuki', 'Kawasaki', 'BMW', 'Ducati', 'KTM',
                    'Harley-Davidson', 'Triumph', 'Aprilia', 'Piaggio', 'Vespa',
                    'Benelli', 'Royal Enfield', 'Moto Guzzi', 'Husqvarna', 'MV Agusta',
                    'CFMoto', 'Kymco', 'SYM',
                ];
                foreach ($seedBrands as $brandName) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM motorcycle_brands WHERE name = ?", [$brandName]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO motorcycle_brands (name, is_approved, created_by, created_at) VALUES (?, 1, NULL, NOW())",
                            [$brandName]
                        );
                    }
                }

                $brandColExists = dbFetchValue(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'motorcycle_brand_id'"
                );
                if (!$brandColExists) {
                    dbExecute("ALTER TABLE users ADD COLUMN motorcycle_brand_id INT UNSIGNED NULL AFTER club_registry_number");
                    dbExecute("ALTER TABLE users ADD CONSTRAINT fk_users_motorcycle_brand FOREIGN KEY (motorcycle_brand_id) REFERENCES motorcycle_brands(id) ON DELETE SET NULL");
                }

                $modelColExists = dbFetchValue(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'motorcycle_model_id'"
                );
                if (!$modelColExists) {
                    dbExecute("ALTER TABLE users ADD COLUMN motorcycle_model_id INT UNSIGNED NULL AFTER motorcycle_brand_id");
                    dbExecute("ALTER TABLE users ADD CONSTRAINT fk_users_motorcycle_model FOREIGN KEY (motorcycle_model_id) REFERENCES motorcycle_models(id) ON DELETE SET NULL");
                }
            },
        ],
```

- [ ] **Step 2: Bump the schema version constant**

In `config.php:15`, change:
```php
define('DB_SCHEMA_VERSION', 77);
```
to:
```php
define('DB_SCHEMA_VERSION', 78);
```

- [ ] **Step 3: Lint the changed files**

Run:
```bash
C:\xampp\php\php.exe -l includes/migrations.php
C:\xampp\php\php.exe -l config.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Trigger the migration and verify**

Load any page in the browser (e.g. `http://localhost/easyride/dashboard.php`) so `runSchemaMigrations()` fires, then verify via CLI:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "USE member_ops; SELECT db_schema_version FROM settings WHERE setting_key='db_schema_version';"
C:\xampp\mysql\bin\mysql.exe -u root -e "USE member_ops; SELECT COUNT(*) AS brand_count FROM motorcycle_brands;"
C:\xampp\mysql\bin\mysql.exe -u root -e "USE member_ops; DESCRIBE users;" | grep motorcycle
```
Expected: schema version is `78` (note: setting key access may differ — if the above SELECT errors, instead run `SELECT setting_value FROM settings WHERE setting_key='db_schema_version'`), `brand_count` is `20`, and `DESCRIBE users` shows `motorcycle_brand_id` and `motorcycle_model_id` columns.

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php
git commit -m "Add motorcycle brand/model tables (migration 78)"
```

---

### Task 2: Brand/model resolve-or-create helper functions

**Files:**
- Modify: `includes/functions.php` (append at end of file, after line 621)

**Interfaces:**
- Consumes: `dbFetchValue($sql, $params)`, `dbInsert($sql, $params)` from `includes/db.php`.
- Produces: `resolveMotorcycleBrandId(?string $brandName, int $createdBy): ?int` and `resolveMotorcycleModelId(?string $modelName, ?int $brandId, int $createdBy): ?int`. Task 3 calls both by these exact names.

- [ ] **Step 1: Append the two helper functions**

At the end of `includes/functions.php` (after the current last line, line 621), add:

```php

/**
 * Resolve a motorcycle brand name to its id, reusing any existing row
 * (approved or pending) by case-insensitive match. Creates a new pending
 * (is_approved = 0) row if no match exists. Returns null for empty input.
 */
function resolveMotorcycleBrandId(?string $brandName, int $createdBy): ?int {
    $brandName = trim((string) $brandName);
    if ($brandName === '') {
        return null;
    }

    $existingId = dbFetchValue(
        "SELECT id FROM motorcycle_brands WHERE LOWER(name) = LOWER(?)",
        [$brandName]
    );
    if ($existingId) {
        return (int) $existingId;
    }

    return (int) dbInsert(
        "INSERT INTO motorcycle_brands (name, is_approved, created_by, created_at) VALUES (?, 0, ?, NOW())",
        [$brandName, $createdBy]
    );
}

/**
 * Resolve a motorcycle model name (scoped to a brand id) to its id, reusing
 * any existing row (approved or pending) by case-insensitive match within
 * that brand. Creates a new pending (is_approved = 0) row if no match
 * exists. Returns null if the model name or brand id is missing.
 */
function resolveMotorcycleModelId(?string $modelName, ?int $brandId, int $createdBy): ?int {
    $modelName = trim((string) $modelName);
    if ($modelName === '' || !$brandId) {
        return null;
    }

    $existingId = dbFetchValue(
        "SELECT id FROM motorcycle_models WHERE brand_id = ? AND LOWER(name) = LOWER(?)",
        [$brandId, $modelName]
    );
    if ($existingId) {
        return (int) $existingId;
    }

    return (int) dbInsert(
        "INSERT INTO motorcycle_models (brand_id, name, is_approved, created_by, created_at) VALUES (?, ?, 0, ?, NOW())",
        [$brandId, $modelName, $createdBy]
    );
}
```

- [ ] **Step 2: Lint**

Run:
```bash
C:\xampp\php\php.exe -l includes/functions.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/functions.php
git commit -m "Add resolveMotorcycleBrandId/resolveMotorcycleModelId helpers"
```

---

### Task 3: member-form.php — save handling, edit-mode display, and UI fields

**Files:**
- Modify: `member-form.php`

**Interfaces:**
- Consumes: `resolveMotorcycleBrandId(?string, int): ?int`, `resolveMotorcycleModelId(?string, ?int, int): ?int` from Task 2; `getCurrentUserId(): int` (existing, `includes/auth.php:71`).
- Produces: `users.motorcycle_brand_id` / `users.motorcycle_model_id` now populated on save; `$form['moto_brand']` / `$form['moto_model']` (display names, not ids) available for the template; `$approvedBrands` (array of `['id' => ..., 'name' => ...]`) and `$brandModelMap` (assoc array `brandName => [modelName, ...]`) available for the datalist markup. Task 4 (`member-view.php`) reads the same `users.motorcycle_brand_id`/`motorcycle_model_id` columns independently via its own JOIN.

- [ ] **Step 1: Add brand/model to the `$data` array and validation block**

In `member-form.php`, the `$data` array currently ends at line 63-64:
```php
        'club_registry_number' => post('club_registry_number') ?: null,
        'custom_role_id' => post('custom_role_id') ?: null,
    ];
```
Leave this untouched — brand/model ids are resolved separately (not simple `post()` passthrough) because saving requires side-effecting inserts. Instead, add resolution logic right after line 76 (the closing `}` of the `custom_role_id` validation block, before the `// Validation` comment on line 78):

```php
    // Validation
```
becomes (insert before it):
```php
    // Resolve motorcycle brand/model names to ids (creates pending rows if new)
    $motoBrandName = trim((string) post('moto_brand'));
    $motoModelName = trim((string) post('moto_model'));
    $motorcycleBrandId = resolveMotorcycleBrandId($motoBrandName, getCurrentUserId());
    $motorcycleModelId = resolveMotorcycleModelId($motoModelName, $motorcycleBrandId, getCurrentUserId());

    // Validation
```

- [ ] **Step 2: Add the two columns to the UPDATE statement**

Replace (lines 126-140):
```php
            dbExecute(
                "UPDATE users SET
                 name = ?, email = ?, phone = ?, role = ?, custom_role_id = ?, department_id = ?, warehouse_id = ?, is_active = ?,
                 member_type = ?, cohort_year = ?, position_id = ?,
                 id_card = ?, afm = ?, amka = ?, driving_license = ?, vehicle_plate = ?,
                 club_registry_number = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['name'], $data['email'], $data['phone'],
                    $data['role'], $data['custom_role_id'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $memberType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['afm'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['club_registry_number'], $id
                ]
            );
```
with:
```php
            dbExecute(
                "UPDATE users SET
                 name = ?, email = ?, phone = ?, role = ?, custom_role_id = ?, department_id = ?, warehouse_id = ?, is_active = ?,
                 member_type = ?, cohort_year = ?, position_id = ?,
                 id_card = ?, afm = ?, amka = ?, driving_license = ?, vehicle_plate = ?,
                 club_registry_number = ?, motorcycle_brand_id = ?, motorcycle_model_id = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['name'], $data['email'], $data['phone'],
                    $data['role'], $data['custom_role_id'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $memberType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['afm'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['club_registry_number'], $motorcycleBrandId, $motorcycleModelId, $id
                ]
            );
```

- [ ] **Step 3: Add the two columns to the INSERT statement**

Replace (lines 154-166):
```php
            $id = dbInsert(
                "INSERT INTO users
                 (name, email, password, phone, role, custom_role_id, department_id, warehouse_id, is_active, member_type, cohort_year, position_id,
                  id_card, afm, amka, driving_license, vehicle_plate, club_registry_number, total_points, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())",
                [
                    $data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT),
                    $data['phone'], $data['role'], $data['custom_role_id'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $memberType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['afm'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['club_registry_number']
                ]
            );
```
with:
```php
            $id = dbInsert(
                "INSERT INTO users
                 (name, email, password, phone, role, custom_role_id, department_id, warehouse_id, is_active, member_type, cohort_year, position_id,
                  id_card, afm, amka, driving_license, vehicle_plate, club_registry_number, motorcycle_brand_id, motorcycle_model_id, total_points, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())",
                [
                    $data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT),
                    $data['phone'], $data['role'], $data['custom_role_id'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $memberType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['afm'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['club_registry_number'], $motorcycleBrandId, $motorcycleModelId
                ]
            );
```

- [ ] **Step 4: Resolve brand/model ids to display names for the form, and add defaults for the new-member case**

The block at lines 216-235 currently reads:
```php
// Form values
$form = $member ?: [
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => ROLE_MEMBER,
    'department_id' => null,
    'warehouse_id' => null,
    'is_active' => 1,
    'member_type' => VTYPE_RESCUER,
    'cohort_year' => null,
    'position_id' => null,
    'id_card' => '',
    'afm' => '',
    'amka' => '',
    'driving_license' => '',
    'vehicle_plate' => '',
    'club_registry_number' => '',
    'custom_role_id' => null,
];
```
Replace it with:
```php
// Form values
$form = $member ?: [
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => ROLE_MEMBER,
    'department_id' => null,
    'warehouse_id' => null,
    'is_active' => 1,
    'member_type' => VTYPE_RESCUER,
    'cohort_year' => null,
    'position_id' => null,
    'id_card' => '',
    'afm' => '',
    'amka' => '',
    'driving_license' => '',
    'vehicle_plate' => '',
    'club_registry_number' => '',
    'custom_role_id' => null,
];

// $member is the raw users row (numeric FK ids) — resolve to display names for the inputs
$form['moto_brand'] = '';
$form['moto_model'] = '';
if ($member && !empty($member['motorcycle_brand_id'])) {
    $form['moto_brand'] = (string) dbFetchValue("SELECT name FROM motorcycle_brands WHERE id = ?", [$member['motorcycle_brand_id']]);
}
if ($member && !empty($member['motorcycle_model_id'])) {
    $form['moto_model'] = (string) dbFetchValue("SELECT name FROM motorcycle_models WHERE id = ?", [$member['motorcycle_model_id']]);
}

// Datalist data: approved brands, and a brand -> approved models JSON map for the JS below
$approvedBrands = dbFetchAll("SELECT id, name FROM motorcycle_brands WHERE is_approved = 1 ORDER BY name ASC");
$approvedModelRows = dbFetchAll(
    "SELECT m.name AS model_name, b.name AS brand_name
     FROM motorcycle_models m
     JOIN motorcycle_brands b ON m.brand_id = b.id
     WHERE m.is_approved = 1
     ORDER BY b.name ASC, m.name ASC"
);
$brandModelMap = [];
foreach ($approvedModelRows as $row) {
    $brandModelMap[$row['brand_name']][] = $row['model_name'];
}
```

- [ ] **Step 5: Add the HTML fields after "Αριθμός Κυκλοφορίας"**

The block at lines 407-412 currently reads:
```php
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Αρ. Μητρώου Λέσχης</label>
                    <input type="text" class="form-control" name="club_registry_number" value="<?= h($form['club_registry_number'] ?? '') ?>" placeholder="Προαιρετικό">
                </div>
            </div>
```
Add a new row directly after this block (still before the `<hr>` on line 414):
```php

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Μάρκα Μηχανής</label>
                    <input type="text" class="form-control" list="brandOptions" id="motoBrand" name="moto_brand" value="<?= h($form['moto_brand'] ?? '') ?>" placeholder="π.χ. Honda">
                    <datalist id="brandOptions">
                        <?php foreach ($approvedBrands as $b): ?>
                            <option value="<?= h($b['name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Μοντέλο</label>
                    <input type="text" class="form-control" list="modelOptions" id="motoModel" name="moto_model" value="<?= h($form['moto_model'] ?? '') ?>" placeholder="π.χ. CBR 600">
                    <datalist id="modelOptions"></datalist>
                </div>
            </div>
```

- [ ] **Step 6: Add the brand→model JS behavior**

The file ends with a `<?php if (!empty($customRoles)): ?> <script> ... </script> <?php endif; ?>` block at lines 505-544, followed by the footer include at line 546. Add a new, unconditional script block right after line 544 (`<?php endif; ?>`) and before line 546 (`<?php include __DIR__ . '/includes/footer.php'; ?>`):

```php

<script>
(function () {
    const brandModelMap = <?= json_encode($brandModelMap, JSON_UNESCAPED_UNICODE) ?>;
    const brandInput  = document.getElementById('motoBrand');
    const modelInput  = document.getElementById('motoModel');
    const modelOptions = document.getElementById('modelOptions');

    function repopulateModels() {
        if (!brandInput || !modelOptions) return;
        modelOptions.innerHTML = '';
        const typedBrand = brandInput.value.trim().toLowerCase();
        const matchKey = Object.keys(brandModelMap).find(b => b.toLowerCase() === typedBrand);
        if (!matchKey) return;
        brandModelMap[matchKey].forEach(function (modelName) {
            const opt = document.createElement('option');
            opt.value = modelName;
            modelOptions.appendChild(opt);
        });
    }

    if (brandInput) {
        brandInput.addEventListener('input', repopulateModels);
        repopulateModels();
    }
})();
</script>
```

- [ ] **Step 7: Lint**

Run:
```bash
C:\xampp\php\php.exe -l member-form.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 8: Manual verification in browser**

Navigate to `http://localhost/easyride/member-form.php?id=<an existing member id>` and confirm:
- The "Μάρκα Μηχανής" / "Μοντέλο" fields appear right after "Αριθμός Κυκλοφορίας".
- Typing in the brand field shows the 20 seeded brands as suggestions.
- Save with brand "Honda", model "CBR 600" (free-typed, not in any datalist).
- Reload the same edit page — confirm both fields now show "Honda" and "CBR 600" (proves the id→name resolution in Step 4 works).

- [ ] **Step 9: Commit**

```bash
git add member-form.php
git commit -m "Add motorcycle brand/model fields to member form"
```

---

### Task 4: Display motorcycle brand/model on member-view.php

**Files:**
- Modify: `member-view.php:14-21` (main SELECT query)
- Modify: `member-view.php:723-724` (info block display)

**Interfaces:**
- Consumes: `users.motorcycle_brand_id`/`motorcycle_model_id` (Task 1), joined to get names — independent read path from Task 3's `member-form.php` resolution, same underlying columns.

- [ ] **Step 1: Extend the main SELECT query with brand/model joins**

Replace (lines 14-21):
```php
$member = dbFetchOne(
    "SELECT u.*,
            vp.name as position_name, vp.color as position_color, vp.icon as position_icon
     FROM users u 
     LEFT JOIN member_positions vp ON u.position_id = vp.id
     WHERE u.id = ?",
    [$id]
);
```
with:
```php
$member = dbFetchOne(
    "SELECT u.*,
            vp.name as position_name, vp.color as position_color, vp.icon as position_icon,
            mb.name as motorcycle_brand_name, mm.name as motorcycle_model_name
     FROM users u 
     LEFT JOIN member_positions vp ON u.position_id = vp.id
     LEFT JOIN motorcycle_brands mb ON u.motorcycle_brand_id = mb.id
     LEFT JOIN motorcycle_models mm ON u.motorcycle_model_id = mm.id
     WHERE u.id = ?",
    [$id]
);
```

- [ ] **Step 2: Add the display row next to driving_license/vehicle_plate**

Replace (lines 723-724):
```php
                        <div class="vp-info-label"><i class="bi bi-car-front me-1"></i>Άδεια Οδήγησης / Όχημα</div>
                        <div class="vp-info-value"><?= h($member['driving_license'] ?: '-') ?> <?= $member['vehicle_plate'] ? '/ ' . h($member['vehicle_plate']) : '' ?></div>
```
with:
```php
                        <div class="vp-info-label"><i class="bi bi-car-front me-1"></i>Άδεια Οδήγησης / Όχημα</div>
                        <div class="vp-info-value"><?= h($member['driving_license'] ?: '-') ?> <?= $member['vehicle_plate'] ? '/ ' . h($member['vehicle_plate']) : '' ?></div>
                        <?php if (!empty($member['motorcycle_brand_name'])): ?>
                        <div class="vp-info-label"><i class="bi bi-bicycle me-1"></i>Μηχανή</div>
                        <div class="vp-info-value"><?= h($member['motorcycle_brand_name']) ?><?= $member['motorcycle_model_name'] ? ' / ' . h($member['motorcycle_model_name']) : '' ?></div>
                        <?php endif; ?>
```

- [ ] **Step 3: Lint**

Run:
```bash
C:\xampp\php\php.exe -l member-view.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual verification in browser**

Navigate to `http://localhost/easyride/member-view.php?id=<the member id saved with Honda/CBR 600 in Task 3>` and confirm the profile shows "Μηχανή: Honda / CBR 600". Then check a member with no brand set — confirm the "Μηχανή" row doesn't appear at all (no empty label).

- [ ] **Step 5: Commit**

```bash
git add member-view.php
git commit -m "Display motorcycle brand/model on member profile"
```

---

### Task 5: Admin approval card on settings.php

**Files:**
- Modify: `settings.php:415-838` (POST action dispatch — add 4 new `elseif` branches)
- Modify: `settings.php:1255-1256` (insert new card in the "general" tab, after the existing form closes)

**Interfaces:**
- Consumes: `motorcycle_brands`/`motorcycle_models` tables (Task 1); existing `logAudit($action, $tableName, $recordId, $oldData, $newData)`, `getCurrentUserId()`, `csrfField()`, `post()`, `redirect()`, `setFlash()`.
- The whole file is already gated to system admins only via `requireRole([ROLE_SYSTEM_ADMIN])` at `settings.php:8` — no additional per-card gating needed.

- [ ] **Step 1: Add 4 new POST action branches**

In `settings.php`, the action dispatch chain ends with the `reset_data` branch (lines 795-837), followed by two closing braces at lines 838-839 (`    }` closes the `elseif`, `}` closes `if (isPost())`). Insert 4 new `elseif` branches between the `reset_data` branch and that final `}`. Concretely, replace:
```php
        } catch (Exception $e) {
            db()->rollBack();
            setFlash('error', 'Σφάλμα κατά την επαναφορά: ' . h($e->getMessage()));
            redirect('settings.php?tab=reset');
        }
    }
}
```
(lines 833-838) with:
```php
        } catch (Exception $e) {
            db()->rollBack();
            setFlash('error', 'Σφάλμα κατά την επαναφορά: ' . h($e->getMessage()));
            redirect('settings.php?tab=reset');
        }
    } elseif ($action === 'approve_moto_brand') {
        $brandId = (int) post('brand_id');
        dbExecute("UPDATE motorcycle_brands SET is_approved = 1 WHERE id = ?", [$brandId]);
        logAudit('approve', 'motorcycle_brands', $brandId);
        setFlash('success', 'Η μάρκα εγκρίθηκε.');
        redirect('settings.php?tab=general');
    } elseif ($action === 'reject_moto_brand') {
        $brandId = (int) post('brand_id');
        dbExecute("DELETE FROM motorcycle_brands WHERE id = ?", [$brandId]);
        logAudit('reject', 'motorcycle_brands', $brandId);
        setFlash('success', 'Η μάρκα απορρίφθηκε.');
        redirect('settings.php?tab=general');
    } elseif ($action === 'approve_moto_model') {
        $modelId = (int) post('model_id');
        dbExecute("UPDATE motorcycle_models SET is_approved = 1 WHERE id = ?", [$modelId]);
        logAudit('approve', 'motorcycle_models', $modelId);
        setFlash('success', 'Το μοντέλο εγκρίθηκε.');
        redirect('settings.php?tab=general');
    } elseif ($action === 'reject_moto_model') {
        $modelId = (int) post('model_id');
        dbExecute("DELETE FROM motorcycle_models WHERE id = ?", [$modelId]);
        logAudit('reject', 'motorcycle_models', $modelId);
        setFlash('success', 'Το μοντέλο απορρίφθηκε.');
        redirect('settings.php?tab=general');
    }
}
```

- [ ] **Step 2: Add data fetching for the card**

Right after the existing block at line 841-842:
```php
// Refresh notification settings
$notificationSettings = dbFetchAll("SELECT * FROM notification_settings ORDER BY name");
```
add:
```php

// Pending motorcycle brands/models for admin approval
$pendingMotoBrands = dbFetchAll("SELECT id, name, created_at FROM motorcycle_brands WHERE is_approved = 0 ORDER BY created_at ASC");
foreach ($pendingMotoBrands as &$pb) {
    $pb['usage_count'] = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE motorcycle_brand_id = ?", [$pb['id']]);
}
unset($pb);

$pendingMotoModels = dbFetchAll(
    "SELECT m.id, m.name, m.created_at, b.name AS brand_name
     FROM motorcycle_models m
     JOIN motorcycle_brands b ON m.brand_id = b.id
     WHERE m.is_approved = 0
     ORDER BY m.created_at ASC"
);
foreach ($pendingMotoModels as &$pm) {
    $pm['usage_count'] = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE motorcycle_model_id = ?", [$pm['id']]);
}
unset($pm);
```

- [ ] **Step 3: Add the card HTML**

The "general" tab's form closes at line 1255 (`</form>`), and the tab block itself closes at line 1256 (`<?php endif; ?>`). Replace:
```php
</form>
<?php endif; ?>

<!-- SMTP Settings Tab -->
```
with:
```php
</form>

<?php if (!empty($pendingMotoBrands) || !empty($pendingMotoModels)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-bicycle me-1"></i>Μάρκες/Μοντέλα Μηχανών — Εκκρεμείς Εγκρίσεις</h5>
    </div>
    <div class="card-body">
        <?php foreach ($pendingMotoBrands as $pb): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong><?= h($pb['name']) ?></strong>
                    <span class="badge bg-secondary ms-1">Μάρκα</span>
                    <br><small class="text-muted">Χρησιμοποιείται από <?= $pb['usage_count'] ?> μέλος/μέλη</small>
                </div>
                <div>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="approve_moto_brand">
                        <input type="hidden" name="brand_id" value="<?= $pb['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success">Έγκριση</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('<?= $pb['usage_count'] > 0 ? 'Χρησιμοποιείται από ' . $pb['usage_count'] . ' μέλος/μέλη — θα αδειάσει το πεδίο τους. ' : '' ?>Απόρριψη της μάρκας <?= h($pb['name']) ?>;');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reject_moto_brand">
                        <input type="hidden" name="brand_id" value="<?= $pb['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Απόρριψη</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php foreach ($pendingMotoModels as $pm): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong><?= h($pm['brand_name']) ?> / <?= h($pm['name']) ?></strong>
                    <span class="badge bg-info ms-1">Μοντέλο</span>
                    <br><small class="text-muted">Χρησιμοποιείται από <?= $pm['usage_count'] ?> μέλος/μέλη</small>
                </div>
                <div>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="approve_moto_model">
                        <input type="hidden" name="model_id" value="<?= $pm['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success">Έγκριση</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('<?= $pm['usage_count'] > 0 ? 'Χρησιμοποιείται από ' . $pm['usage_count'] . ' μέλος/μέλη — θα αδειάσει το πεδίο τους. ' : '' ?>Απόρριψη του μοντέλου <?= h($pm['name']) ?>;');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reject_moto_model">
                        <input type="hidden" name="model_id" value="<?= $pm['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Απόρριψη</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- SMTP Settings Tab -->
```

Note the two consecutive `<?php endif; ?>` at the end: the first closes the new pending-approvals `if`, the second is the original one closing the `if ($activeTab === 'general')` block — do not merge or remove either.

- [ ] **Step 4: Lint**

Run:
```bash
C:\xampp\php\php.exe -l settings.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual verification in browser**

As system admin, navigate to `http://localhost/easyride/settings.php?tab=general` and confirm:
- The "Μάρκες/Μοντέλα Μηχανών — Εκκρεμείς Εγκρίσεις" card appears, showing "Honda / CBR 600" (from Task 3's test save) under "Μοντέλο", with "Χρησιμοποιείται από 1 μέλος/μέλη".
- Click "Έγκριση" on it — confirm it disappears from the pending list (redirects back to `settings.php?tab=general`).
- Open `member-form.php?id=<a different member>`, type "Honda" in brand — confirm "CBR 600" now appears in the model datalist (proves `is_approved = 1` now shows in other members' suggestions).
- Go back to Task 3's test member, change the model to a brand-new free-typed value (e.g. "Test Model X"), save — confirm it now shows as pending again in settings. Click "Απόρριψη" — confirm the confirm() dialog mentions "Χρησιμοποιείται από 1 μέλος/μέλη", accept it, then reload that member's edit page and confirm the model field is now empty with no fatal error (proves `ON DELETE SET NULL` works).

- [ ] **Step 6: Commit**

```bash
git add settings.php
git commit -m "Add motorcycle brand/model admin approval card to settings"
```

---

### Task 6: Version bump, docs, and release

**Files:**
- Modify: `config.php:14`
- Modify: `README.md` (version badges + Current Release Highlights)
- Modify: `ROADMAP.md` (Deployed section)

**Interfaces:**
- None — this task only touches versioning/docs, no code interfaces.

- [ ] **Step 1: Bump APP_VERSION**

In `config.php:14`, change:
```php
define('APP_VERSION', '3.82.0');
```
to:
```php
define('APP_VERSION', '3.83.0');
```

- [ ] **Step 2: Update README.md version references**

In `README.md`, update:
- Line 7: `**Version:** 3.82.0` → `**Version:** 3.83.0`
- Line 14: `![Version](https://img.shields.io/badge/version-3.82.0-blue)` → `![Version](https://img.shields.io/badge/version-3.83.0-blue)`

Add a new highlights entry right after line 301 (`## Current Release Highlights`) and before the existing `### v3.82.0` entry at line 303:
```markdown

### v3.83.0

- Members can now record which motorcycle they ride: a searchable "Μάρκα Μηχανής" / "Μοντέλο" field on the member form, seeded with 20 common brands, that grows from real member entries — new brands/models are pending until a system admin approves them from Settings, after which they appear as suggestions for other members.
- Removed the "Ιστορικό Εξετάσεων & Κουίζ" (Exam & Quiz History) card from the member profile page.
```

- [ ] **Step 3: Update ROADMAP.md**

In `ROADMAP.md`, add a new entry right after line 6 (`---`) and before the existing `### v3.82.0` entry at line 9:
```markdown

### v3.83.0 — Μάρκα/Μοντέλο Μηχανής Μέλους
- Νέο πεδίο στη φόρμα/προφίλ μέλους για καταγραφή μάρκας/μοντέλου μηχανής: αναζητήσιμο dropdown (`<datalist>`) με δυνατότητα ελεύθερης καταχώρησης αν δεν υπάρχει η μάρκα/μοντέλο. Νέες καταχωρήσεις μπαίνουν σε εκκρεμότητα μέχρι έγκριση από διαχειριστή συστήματος (Ρυθμίσεις), μετά την οποία εμφανίζονται ως προτάσεις σε όλους.
- Αφαιρέθηκε η κάρτα "Ιστορικό Εξετάσεων & Κουίζ" από το προφίλ μέλους.
- Spec: [docs/superpowers/specs/2026-07-06-motorcycle-brand-model-design.md](docs/superpowers/specs/2026-07-06-motorcycle-brand-model-design.md)
```
Also update line 3's status date if it no longer matches today's date at release time.

- [ ] **Step 4: Lint config.php**

Run:
```bash
C:\xampp\php\php.exe -l config.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit the version bump**

```bash
git add config.php README.md ROADMAP.md
git commit -m "Bump version for motorcycle brand/model feature release"
```

- [ ] **Step 6: Sync to XAMPP htdocs**

Per this project's established release workflow, sync the working tree to the live XAMPP path (adjust source path if different):
```bash
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git node_modules /XF config.local.php
```
Note: `/MIR` mirrors — confirm `config.local.php` is excluded (it's excluded above via `/XF`) so local DB credentials aren't overwritten.

- [ ] **Step 7: Tag and release**

```bash
git tag -a v3.83.0 -m "v3.83.0"
git push origin main
git push origin v3.83.0
gh release create v3.83.0 --repo TheoSfak/easyrider --title "EasyRide v3.83.0" --notes "Members can now record which motorcycle they ride (brand/model, searchable dropdown with free-text fallback and admin approval workflow). Removed the Exam & Quiz History card from member profiles."
```
