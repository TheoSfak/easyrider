<?php
/**
 * EasyRide - Road Partner Form
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$partnerTypes = [
    'workshop' => 'Συνεργείο',
    'parts' => 'Κατάστημα Ανταλλακτικών',
    'tires' => 'Ελαστικά',
    'roadside' => 'Οδική Βοήθεια',
];

$subscriptionStatuses = [
    'none' => 'Δεν ζητήθηκε',
    'requested' => 'Ζητήθηκε συνδρομή',
    'active' => 'Ενεργός συνδρομητής',
    'declined' => 'Δεν ενδιαφέρεται',
];

$id = (int)get('id');
$isEdit = $id > 0;
$partner = null;

if ($isEdit) {
    $partner = dbFetchOne("SELECT * FROM partner_locations WHERE id = ?", [$id]);
    if (!$partner) {
        setFlash('error', 'Ο συνεργάτης δεν βρέθηκε.');
        redirect('partners.php');
    }
}

$pageTitle = $isEdit ? 'Επεξεργασία Συνεργάτη' : 'Νέος Συνεργάτης';
$errors = [];

$form = [
    'type' => $partner['type'] ?? 'workshop',
    'name' => $partner['name'] ?? '',
    'description' => $partner['description'] ?? '',
    'area' => $partner['area'] ?? '',
    'address' => $partner['address'] ?? '',
    'phone' => $partner['phone'] ?? '',
    'email' => $partner['email'] ?? '',
    'website' => $partner['website'] ?? '',
    'maps_link' => $partner['maps_link'] ?? '',
    'latitude' => $partner['latitude'] ?? '',
    'longitude' => $partner['longitude'] ?? '',
    'subscription_status' => $partner['subscription_status'] ?? 'none',
    'subscription_notes' => $partner['subscription_notes'] ?? '',
    'is_active' => isset($partner['is_active']) ? (int)$partner['is_active'] : 1,
];

if (isPost()) {
    verifyCsrf();
    foreach (array_keys($form) as $key) {
        if ($key === 'is_active') {
            $form[$key] = isset($_POST['is_active']) ? 1 : 0;
        } else {
            $form[$key] = trim((string)post($key, ''));
        }
    }

    if (!isset($partnerTypes[$form['type']])) {
        $errors[] = 'Επιλέξτε έγκυρο τύπο συνεργάτη.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Το email δεν είναι έγκυρο.';
    }
    if ($form['website'] !== '' && !filter_var($form['website'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Το website δεν είναι έγκυρο URL.';
    }
    if ($form['maps_link'] !== '' && !filter_var($form['maps_link'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Ο σύνδεσμος Google Maps δεν είναι έγκυρος.';
    }
    if (!isset($subscriptionStatuses[$form['subscription_status']])) {
        $form['subscription_status'] = 'none';
    }

    $lat = $form['latitude'] !== '' ? (float)$form['latitude'] : null;
    $lng = $form['longitude'] !== '' ? (float)$form['longitude'] : null;
    if ($lat !== null && ($lat < -90 || $lat > 90)) {
        $errors[] = 'Το γεωγραφικό πλάτος δεν είναι έγκυρο.';
    }
    if ($lng !== null && ($lng < -180 || $lng > 180)) {
        $errors[] = 'Το γεωγραφικό μήκος δεν είναι έγκυρο.';
    }

    if (empty($errors)) {
        $subscriptionRequestedAt = null;
        if ($form['subscription_status'] === 'requested') {
            $subscriptionRequestedAt = $partner['subscription_requested_at'] ?? date('Y-m-d H:i:s');
        } elseif ($isEdit) {
            $subscriptionRequestedAt = $partner['subscription_requested_at'];
        }

        $params = [
            $form['type'],
            $form['name'],
            $form['description'] ?: null,
            $form['address'] ?: null,
            $form['area'] ?: null,
            $form['phone'] ?: null,
            $form['email'] ?: null,
            $form['website'] ?: null,
            $form['maps_link'] ?: null,
            $lat,
            $lng,
            $form['subscription_status'],
            $subscriptionRequestedAt,
            $form['subscription_notes'] ?: null,
            $form['is_active'],
        ];

        if ($isEdit) {
            $params[] = $id;
            dbExecute(
                "UPDATE partner_locations
                 SET type = ?, name = ?, description = ?, address = ?, area = ?, phone = ?, email = ?, website = ?,
                     maps_link = ?, latitude = ?, longitude = ?, subscription_status = ?, subscription_requested_at = ?,
                     subscription_notes = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?",
                $params
            );
            logAudit('update_partner_location', 'partner_locations', $id);
            setFlash('success', 'Ο συνεργάτης ενημερώθηκε.');
        } else {
            $params[] = getCurrentUserId();
            $newId = dbInsert(
                "INSERT INTO partner_locations
                 (type, name, description, address, area, phone, email, website, maps_link, latitude, longitude,
                  subscription_status, subscription_requested_at, subscription_notes, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                $params
            );
            logAudit('create_partner_location', 'partner_locations', $newId);
            setFlash('success', 'Ο συνεργάτης δημιουργήθηκε.');
        }
        redirect('partners.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="partners.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h3 mb-0"><i class="bi bi-tools me-2"></i><?= h($pageTitle) ?></h1>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post">
    <?= csrfField() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent fw-semibold">Στοιχεία Συνεργάτη</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Τύπος</label>
                            <select class="form-select" name="type" required>
                                <?php foreach ($partnerTypes as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= $form['type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Όνομα *</label>
                            <input type="text" class="form-control" name="name" value="<?= h($form['name']) ?>" required maxlength="160">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Περιοχή</label>
                            <input type="text" class="form-control" name="area" value="<?= h($form['area']) ?>" maxlength="120">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Διεύθυνση</label>
                            <input type="text" class="form-control" name="address" value="<?= h($form['address']) ?>" maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Περιγραφή</label>
                            <textarea class="form-control" name="description" rows="3"><?= h($form['description']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent fw-semibold">Χάρτης</div>
                <div class="card-body">
                    <label class="form-label">Σύνδεσμος Google Maps</label>
                    <div class="input-group">
                        <input type="url" class="form-control" id="maps_link" name="maps_link" value="<?= h($form['maps_link']) ?>" placeholder="https://maps.app.goo.gl/...">
                        <button type="button" class="btn btn-outline-secondary" id="parseMapsBtn"><i class="bi bi-crosshair"></i></button>
                    </div>
                    <div id="mapsParseStatus" class="mt-1"></div>
                    <input type="hidden" id="latitude" name="latitude" value="<?= h($form['latitude']) ?>">
                    <input type="hidden" id="longitude" name="longitude" value="<?= h($form['longitude']) ?>">
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent fw-semibold">Επικοινωνία</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Τηλέφωνο</label>
                        <input type="text" class="form-control" name="phone" value="<?= h($form['phone']) ?>" maxlength="60">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= h($form['email']) ?>" maxlength="160">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website</label>
                        <input type="url" class="form-control" name="website" value="<?= h($form['website']) ?>" maxlength="255">
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent fw-semibold">Συνδρομή</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Κατάσταση</label>
                        <select class="form-select" name="subscription_status">
                            <?php foreach ($subscriptionStatuses as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $form['subscription_status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις συνδρομής</label>
                        <textarea class="form-control" name="subscription_notes" rows="3"><?= h($form['subscription_notes']) ?></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $form['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Εμφάνιση στα μέλη</label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
        </div>
    </div>
</form>

<script>
(function() {
    function extractLatLng(url) {
        if (!url) return null;
        var m = url.match(/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/);
        if (m) return { lat: m[1], lng: m[2] };
        m = url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };
        m = url.match(/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };
        m = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (m) return { lat: m[1], lng: m[2] };
        return null;
    }
    function applyCoords(coords) {
        document.getElementById('latitude').value = coords.lat;
        document.getElementById('longitude').value = coords.lng;
        document.getElementById('mapsParseStatus').innerHTML =
            '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Η τοποθεσία εντοπίστηκε.</small>';
    }
    function handleMapsInput(url) {
        url = url.trim();
        if (!url) return;
        var coords = extractLatLng(url);
        if (coords) {
            applyCoords(coords);
            return;
        }
        if (/goo\.gl|maps\.app\.goo\.gl/.test(url)) {
            var status = document.getElementById('mapsParseStatus');
            status.innerHTML = '<small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Επεξεργασία συνδέσμου...</small>';
            fetch('api-resolve-maps.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: 'url=' + encodeURIComponent(url)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.lat && data.lng) applyCoords({ lat: data.lat, lng: data.lng });
                else status.innerHTML = '<small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' + (data.error || 'Δεν βρέθηκε τοποθεσία.') + '</small>';
            })
            .catch(function() {
                status.innerHTML = '<small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Αποτυχία επεξεργασίας συνδέσμου.</small>';
            });
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('maps_link');
        document.getElementById('parseMapsBtn').addEventListener('click', function() { handleMapsInput(input.value); });
        input.addEventListener('paste', function() { setTimeout(function() { handleMapsInput(input.value); }, 50); });
        <?php if (!empty($form['latitude']) && !empty($form['longitude'])): ?>
        document.getElementById('mapsParseStatus').innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Η τοποθεσία έχει εντοπιστεί.</small>';
        <?php endif; ?>
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
