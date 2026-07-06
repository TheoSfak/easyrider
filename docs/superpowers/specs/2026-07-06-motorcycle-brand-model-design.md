# Motorcycle Brand/Model Field

## Purpose

Members currently have a `vehicle_plate` (license plate) field but nothing recording what motorcycle they actually ride. This adds a searchable brand + model selector to the member form/profile, seeded with common brand names, that grows organically as members enter bikes not yet in the list — subject to admin approval before becoming visible to other members' dropdowns.

## Scope

Also removes the "Ιστορικό Εξετάσεων & Κουίζ" (Exam & Quiz History) card from `member-view.php` (already done, shipped ahead of this spec since it was a simple, independent removal — see git history).

Out of scope: multiple motorcycles per member (one bike, matching "τι μηχανή έχει"); an exhaustive real-world brand/model database (starts with a ~20-brand seed list, grows from actual member entries).

## Data Model

Two new tables, additive only:

```sql
CREATE TABLE IF NOT EXISTS motorcycle_brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brand_name (name),
    CONSTRAINT fk_brand_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`users` gets two new nullable columns: `motorcycle_brand_id` and `motorcycle_model_id`, both `INT UNSIGNED NULL`, FK to the tables above with `ON DELETE SET NULL` — if a pending brand/model is ever rejected (deleted) while a member still points at it, that member's field silently reverts to empty rather than leaving a dangling reference.

Seed data (migration inserts, `is_approved = 1`, `created_by = NULL`): Honda, Yamaha, Suzuki, Kawasaki, BMW, Ducati, KTM, Harley-Davidson, Triumph, Aprilia, Piaggio, Vespa, Benelli, Royal Enfield, Moto Guzzi, Husqvarna, MV Agusta, CFMoto, Kymco, SYM. No seeded models — every model starts empty and is populated by real member entries.

## UI: Brand/Model Input

Added to `member-form.php`, in a new row right after the existing "Αριθμός Κυκλοφορίας" field (same section as the license plate — vehicle info), and displayed in `member-view.php` in the same info block as `driving_license`/`vehicle_plate`.

Two `<input list="...">` + `<datalist>` pairs (brand, then model) — this is a native HTML control: typing filters the suggestions (the "search" behavior asked for), and typing something that doesn't match any suggestion is simply accepted as free text (the "type it manually if not found" fallback), with zero custom JS required for that part. This matches the existing vanilla-JS-only approach already used elsewhere in the app (e.g. the member-search filter in `task-form.php`) rather than introducing a new UI library like Select2.

```html
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

A small inline script embeds a JSON map of `{ "Honda": ["CBR 600", ...], ... }` built from all approved brand/model rows, and repopulates `#modelOptions` on every `#motoBrand` input event by looking up the currently-typed brand name (case-insensitive exact match) in that map. If the typed brand isn't recognized (a brand-new one), the model datalist is simply left empty — the model field still accepts free typing.

## Submission Handling

On `member-form.php` save: for each of brand/model, look up an existing row by name (case-insensitive), scoped to that brand for models. If found (approved or still pending), reuse its id — this prevents two members independently typing the same new brand/model from creating duplicate pending rows. If not found, insert a new row with `is_approved = 0`, `created_by = <current admin's user id>`. Either way, the resulting id is stored on `users.motorcycle_brand_id` / `users.motorcycle_model_id`.

A brand/model the current member is using is always shown as their value on `member-form.php`/`member-view.php` regardless of its `is_approved` state — approval only gates whether it appears in *other* members' `<datalist>` (i.e. the datalist is populated from `WHERE is_approved = 1`, but the saved value renders unconditionally via `value="..."`).

## Admin Approval

A new "Μάρκες/Μοντέλα Μηχανών" card on `settings.php` (system-admin only, matching the file's existing per-section admin gating pattern), listing every `is_approved = 0` row (brands and models together, showing which brand a pending model belongs to) with:
- **Έγκριση** — sets `is_approved = 1`.
- **Απόρριψη** — before deleting, counts how many `users` rows reference this id; if > 0, the confirm dialog says "Χρησιμοποιείται από N μέλος/μέλη — θα αδειάσει το πεδίο τους." Then deletes the row (FK `ON DELETE SET NULL` handles clearing any referencing member's field automatically).

## Testing

No automated test framework (project-wide convention). Manual verification:
1. Open a member's edit form, type "Honda" in brand — confirm the datalist suggests it, and typing "CBR" in model (with Honda selected) shows nothing yet (no seeded models).
2. Save with brand "Honda", model "CBR 600" (free-typed) — confirm a new `motorcycle_models` row is created with `is_approved = 0`, and the member's profile shows "Honda / CBR 600" immediately.
3. Open a *different* member's form — confirm "CBR 600" does NOT yet appear in the model datalist for Honda (not approved yet), but typing "Honda" + "CBR 600" again reuses the same pending model row rather than creating a duplicate.
4. As system admin, go to Ρυθμίσεις — confirm the pending "CBR 600" (under Honda) appears with Έγκριση/Απόρριψη buttons, showing "used by 1 member."
5. Approve it — confirm it now appears in the model datalist for any member who selects Honda.
6. Create a second pending entry, reject it while a member still points at it — confirm that member's brand/model field is empty afterward, with no fatal error.
7. Confirm the CSV export/import (`includes/export-functions.php`/`import-functions.php`) are unaffected (this feature is out of scope for CSV, brand/model isn't added to import/export in this phase).
