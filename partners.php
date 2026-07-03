<?php
/**
 * EasyRide - Road Partners
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Συνεργάτες';
$isAdminUser = isSystemAdmin();

$partnerTypes = [
    'workshop' => ['label' => 'Συνεργείο', 'icon' => 'bi-tools', 'color' => 'primary'],
    'parts' => ['label' => 'Ανταλλακτικά', 'icon' => 'bi-gear', 'color' => 'success'],
    'tires' => ['label' => 'Ελαστικά', 'icon' => 'bi-circle', 'color' => 'warning'],
    'roadside' => ['label' => 'Οδική Βοήθεια', 'icon' => 'bi-truck', 'color' => 'danger'],
];

$subscriptionLabels = [
    'none' => ['label' => 'Δεν ζητήθηκε', 'class' => 'bg-light text-dark border'],
    'requested' => ['label' => 'Ζητήθηκε συνδρομή', 'class' => 'bg-warning text-dark'],
    'active' => ['label' => 'Ενεργός συνδρομητής', 'class' => 'bg-success'],
    'declined' => ['label' => 'Δεν ενδιαφέρεται', 'class' => 'bg-secondary'],
];

try {
    if (empty(dbFetchAll("SHOW COLUMNS FROM citizen_certificate_types LIKE 'duration_months'"))) {
        dbExecute("ALTER TABLE citizen_certificate_types ADD COLUMN duration_months INT UNSIGNED NOT NULL DEFAULT 12 AFTER name");
    }
    if (empty(dbFetchAll("SHOW COLUMNS FROM citizen_certificates LIKE 'source'"))) {
        dbExecute("ALTER TABLE citizen_certificates ADD COLUMN source VARCHAR(80) NULL AFTER email");
    }
    if (empty(dbFetchAll("SHOW COLUMNS FROM citizen_certificates LIKE 'partner_id'"))) {
        dbExecute("ALTER TABLE citizen_certificates ADD COLUMN partner_id INT UNSIGNED NULL AFTER source");
        dbExecute("CREATE INDEX idx_cc_partner ON citizen_certificates(partner_id)");
    }
} catch (Exception $e) {
    // Migrations normally handle this; keep the page usable if the index already exists.
}

$certTypes = $isAdminUser
    ? dbFetchAll("SELECT id, name, duration_months FROM citizen_certificate_types WHERE is_active = 1 ORDER BY name")
    : [];

if (isPost()) {
    verifyCsrf();
    if (!$isAdminUser) {
        setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
        redirect('partners.php');
    }

    $partnerId = (int)post('partner_id');
    $action = post('action');
    $partner = $partnerId ? dbFetchOne("SELECT * FROM partner_locations WHERE id = ?", [$partnerId]) : null;
    if (!$partner) {
        setFlash('error', 'Ο συνεργάτης δεν βρέθηκε.');
        redirect('partners.php');
    }

    if ($action === 'request_subscription') {
        dbExecute(
            "UPDATE partner_locations SET subscription_status = 'requested', subscription_requested_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$partnerId]
        );
        setFlash('success', 'Σημειώθηκε ότι ζητήθηκε συνδρομή από τον συνεργάτη.');
    } elseif ($action === 'mark_active') {
        dbExecute(
            "UPDATE partner_locations SET subscription_status = 'active', updated_at = NOW() WHERE id = ?",
            [$partnerId]
        );
        setFlash('success', 'Ο συνεργάτης σημειώθηκε ως ενεργός συνδρομητής.');
    } elseif ($action === 'paid_subscription') {
        $certificateTypeId = (int) post('certificate_type_id');
        $issueDate = post('issue_date') ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            $issueDate = date('Y-m-d');
        }

        $certType = dbFetchOne(
            "SELECT id, duration_months FROM citizen_certificate_types WHERE id = ? AND is_active = 1",
            [$certificateTypeId]
        );
        if (!$certType) {
            setFlash('error', 'Επιλέξτε έγκυρο τύπο συνδρομής.');
            redirect('partners.php');
        }

        $durationMonths = max(1, (int)($certType['duration_months'] ?? 12));
        $expiryDate = addMonthsToDate($issueDate, $durationMonths);
        $notes = trim((string)($partner['subscription_notes'] ?? ''));
        $source = 'Συνεργάτης';
        $existingCertId = (int) dbFetchValue(
            "SELECT id FROM citizen_certificates WHERE partner_id = ? ORDER BY id DESC LIMIT 1",
            [$partnerId]
        );

        if ($existingCertId > 0) {
            dbExecute(
                "UPDATE citizen_certificates
                 SET certificate_type_id = ?, first_name = ?, last_name = ?, phone = ?, issue_date = ?,
                     expiry_date = ?, email = ?, source = ?, notes = ?,
                     reminder_sent_3m = 0, reminder_sent_1m = 0, reminder_sent_1w = 0,
                     reminder_sent_expired = 0, updated_at = NOW()
                 WHERE id = ?",
                [
                    $certificateTypeId,
                    $partner['name'],
                    '',
                    $partner['phone'] ?: null,
                    $issueDate,
                    $expiryDate,
                    $partner['email'] ?: null,
                    $source,
                    $notes !== '' ? $notes : null,
                    $existingCertId,
                ]
            );
            $certId = $existingCertId;
        } else {
            $certId = dbInsert(
                "INSERT INTO citizen_certificates
                 (certificate_type_id, first_name, last_name, phone, issue_date, expiry_date, email, source, notes, partner_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $certificateTypeId,
                    $partner['name'],
                    '',
                    $partner['phone'] ?: null,
                    $issueDate,
                    $expiryDate,
                    $partner['email'] ?: null,
                    $source,
                    $notes !== '' ? $notes : null,
                    $partnerId,
                    getCurrentUserId(),
                ]
            );
        }

        dbExecute(
            "UPDATE partner_locations SET subscription_status = 'active', updated_at = NOW() WHERE id = ?",
            [$partnerId]
        );
        logAudit('upsert_partner_subscription', 'citizen_certificates', $certId);
        setFlash('success', 'Η συνδρομή πληρώθηκε και καταχωρήθηκε στη λίστα Συνδρομές.');
    } elseif ($action === 'mark_declined') {
        dbExecute(
            "UPDATE partner_locations SET subscription_status = 'declined', updated_at = NOW() WHERE id = ?",
            [$partnerId]
        );
        setFlash('success', 'Η κατάσταση συνδρομής ενημερώθηκε.');
    } elseif ($action === 'toggle_active') {
        dbExecute(
            "UPDATE partner_locations SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?",
            [$partnerId]
        );
        setFlash('success', 'Η κατάσταση προβολής ενημερώθηκε.');
    }

    logAudit('update_partner_location', 'partner_locations', $partnerId);
    redirect('partners.php');
}

$typeFilter = get('type', '');
$search = trim(get('q', ''));

$where = [];
$params = [];
if (!$isAdminUser) {
    $where[] = 'is_active = 1';
}
if ($typeFilter && isset($partnerTypes[$typeFilter])) {
    $where[] = 'type = ?';
    $params[] = $typeFilter;
}
if ($search !== '') {
    $where[] = '(name LIKE ? OR area LIKE ? OR address LIKE ? OR description LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$partners = dbFetchAll(
    "SELECT * FROM partner_locations $whereSql ORDER BY is_active DESC, FIELD(type, 'workshop','parts','tires','roadside'), name ASC",
    $params
);

$mapPins = array_values(array_filter(array_map(function ($partner) use ($partnerTypes) {
    if (empty($partner['latitude']) || empty($partner['longitude'])) {
        return null;
    }
    return [
        'lat' => (float)$partner['latitude'],
        'lng' => (float)$partner['longitude'],
        'name' => $partner['name'],
        'type' => $partner['type'],
        'type_label' => $partnerTypes[$partner['type']]['label'] ?? $partner['type'],
        'phone' => $partner['phone'],
        'url' => 'partner-form.php?id=' . $partner['id'],
        'maps' => $partner['maps_link'] ?: 'https://www.google.com/maps?q=' . urlencode($partner['latitude'] . ',' . $partner['longitude']),
    ];
}, $partners)));

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
#partnersMap { height: 420px; border-radius: 8px; border: 1px solid #dee2e6; }
.partner-card.inactive { opacity: .58; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-tools me-2"></i>Συνεργάτες</h1>
    </div>
    <?php if ($isAdminUser): ?>
    <a href="partner-form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Νέος Συνεργάτης
    </a>
    <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Αναζήτηση ονόματος, περιοχής, διεύθυνσης">
    </div>
    <div class="col-md-4">
        <select class="form-select" name="type">
            <option value="">Όλοι οι τύποι</option>
            <?php foreach ($partnerTypes as $key => $meta): ?>
                <option value="<?= h($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 d-grid">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Φίλτρο</button>
    </div>
</form>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div id="partnersMap"></div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if (empty($partners)): ?>
            <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Δεν βρέθηκαν συνεργάτες.</div>
        <?php endif; ?>

        <div class="d-flex flex-column gap-3">
        <?php foreach ($partners as $partner): ?>
            <?php
            $type = $partnerTypes[$partner['type']] ?? ['label' => $partner['type'], 'icon' => 'bi-geo-alt', 'color' => 'secondary'];
            $sub = $subscriptionLabels[$partner['subscription_status']] ?? $subscriptionLabels['none'];
            $navUrl = !empty($partner['latitude']) && !empty($partner['longitude'])
                ? 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($partner['latitude'] . ',' . $partner['longitude']) . '&travelmode=driving'
                : ($partner['maps_link'] ?: '');
            ?>
            <div class="card partner-card border-0 shadow-sm <?= !$partner['is_active'] ? 'inactive' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-3">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge bg-<?= h($type['color']) ?>"><i class="bi <?= h($type['icon']) ?> me-1"></i><?= h($type['label']) ?></span>
                                <?php if ($isAdminUser): ?>
                                    <span class="badge <?= h($sub['class']) ?>"><?= h($sub['label']) ?></span>
                                    <?php if (!$partner['is_active']): ?><span class="badge bg-secondary">Κρυφό</span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <h5 class="mb-1"><?= h($partner['name']) ?></h5>
                            <?php if ($partner['area'] || $partner['address']): ?>
                                <div class="text-muted small mb-1"><i class="bi bi-geo-alt me-1"></i><?= h(trim(($partner['area'] ?: '') . ' ' . ($partner['address'] ?: ''))) ?></div>
                            <?php endif; ?>
                            <?php if ($partner['description']): ?>
                                <div class="small"><?= nl2br(h($partner['description'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <?php if ($partner['phone']): ?>
                            <a class="btn btn-sm btn-outline-success" href="tel:<?= h(preg_replace('/\s+/', '', $partner['phone'])) ?>"><i class="bi bi-telephone me-1"></i><?= h($partner['phone']) ?></a>
                        <?php endif; ?>
                        <?php if ($navUrl): ?>
                            <a class="btn btn-sm btn-primary" href="<?= h($navUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-sign-turn-right me-1"></i>Πλοήγηση</a>
                        <?php endif; ?>
                        <?php if ($partner['website']): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= h($partner['website']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-globe me-1"></i>Site</a>
                        <?php endif; ?>
                        <?php if ($isAdminUser): ?>
                            <a class="btn btn-sm btn-outline-primary" href="partner-form.php?id=<?= (int)$partner['id'] ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
                                <input type="hidden" name="action" value="request_subscription">
                                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-envelope-paper me-1"></i>Ζητήθηκε συνδρομή</button>
                            </form>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
                                <input type="hidden" name="action" value="mark_active">
                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle me-1"></i>Ενεργός</button>
                            </form>
                            <button type="button"
                                    class="btn btn-sm btn-success"
                                    onclick="openPaidSubscriptionModal(<?= (int)$partner['id'] ?>, '<?= h(addslashes($partner['name'])) ?>')"
                                    <?= empty($certTypes) ? 'disabled title="Προσθέστε πρώτα τύπο συνδρομής"' : '' ?>>
                                <i class="bi bi-cash-coin me-1"></i>Πληρώθηκε συνδρομή
                            </button>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $partner['is_active'] ? 'Απόκρυψη' : 'Εμφάνιση' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($isAdminUser): ?>
<div class="modal fade" id="paidSubscriptionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="paid_subscription">
                <input type="hidden" name="partner_id" id="paidPartnerId" value="0">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-coin me-2"></i>Πληρώθηκε συνδρομή
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Συνεργάτης</label>
                        <input type="text" class="form-control" id="paidPartnerName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Τύπος Συνδρομής <span class="text-danger">*</span></label>
                        <select class="form-select" name="certificate_type_id" required>
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($certTypes as $ct): ?>
                                <option value="<?= (int)$ct['id'] ?>" data-duration="<?= (int)($ct['duration_months'] ?? 12) ?>"><?= h($ct['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Ημερομηνία πληρωμής</label>
                        <input type="date" class="form-control" name="issue_date" id="paidIssueDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        Λήξη: <strong id="paidExpiryPreview">—</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Καταχώρηση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function openPaidSubscriptionModal(partnerId, partnerName) {
    document.getElementById('paidPartnerId').value = partnerId;
    document.getElementById('paidPartnerName').value = partnerName;
    updatePaidExpiryPreview();
    new bootstrap.Modal(document.getElementById('paidSubscriptionModal')).show();
}

function addMonthsIso(isoDate, months) {
    if (!isoDate) return '';
    var parts = isoDate.split('-').map(Number);
    if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return '';
    var date = new Date(parts[0], parts[1] - 1, parts[2]);
    var originalDay = date.getDate();
    date.setMonth(date.getMonth() + months);
    if (date.getDate() !== originalDay) {
        date.setDate(0);
    }
    var y = date.getFullYear();
    var m = String(date.getMonth() + 1).padStart(2, '0');
    var d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

function formatGreekDate(isoDate) {
    if (!isoDate) return '—';
    var parts = isoDate.split('-');
    return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : '—';
}

function updatePaidExpiryPreview() {
    var modal = document.getElementById('paidSubscriptionModal');
    if (!modal) return;
    var typeSelect = modal.querySelector('select[name="certificate_type_id"]');
    var issueInput = document.getElementById('paidIssueDate');
    var preview = document.getElementById('paidExpiryPreview');
    if (!typeSelect || !issueInput || !preview) return;
    var option = typeSelect.options[typeSelect.selectedIndex];
    var months = option ? parseInt(option.dataset.duration || '12', 10) : 12;
    preview.textContent = formatGreekDate(addMonthsIso(issueInput.value, months));
}

document.addEventListener('DOMContentLoaded', function() {
    var paidModal = document.getElementById('paidSubscriptionModal');
    if (!paidModal) return;
    var typeSelect = paidModal.querySelector('select[name="certificate_type_id"]');
    var issueInput = document.getElementById('paidIssueDate');
    if (typeSelect) typeSelect.addEventListener('change', updatePaidExpiryPreview);
    if (issueInput) issueInput.addEventListener('change', updatePaidExpiryPreview);
});

(function() {
    var pins = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?>;
    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }
    function attr(value) {
        return esc(value).replace(/`/g, '&#096;');
    }
    var map = L.map('partnersMap').setView([35.3387, 25.1442], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);

    var colors = { workshop: '#0d6efd', parts: '#198754', tires: '#ffc107', roadside: '#dc3545' };
    var bounds = [];
    pins.forEach(function(pin) {
        var color = colors[pin.type] || '#6c757d';
        var icon = L.divIcon({
            className: '',
            html: '<div style="width:24px;height:24px;border-radius:50%;background:' + color + ';border:3px solid #fff;box-shadow:0 2px 8px #0005"></div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        var phoneHref = pin.phone ? pin.phone.replace(/\s+/g, '') : '';
        var popup = '<strong>' + esc(pin.name) + '</strong><br>' + esc(pin.type_label);
        if (pin.phone) popup += '<br><a href="tel:' + attr(phoneHref) + '">' + esc(pin.phone) + '</a>';
        popup += '<br><a target="_blank" rel="noopener noreferrer" href="' + attr(pin.maps) + '">Google Maps</a>';
        L.marker([pin.lat, pin.lng], { icon: icon }).addTo(map).bindPopup(popup);
        bounds.push([pin.lat, pin.lng]);
    });
    if (bounds.length > 1) {
        map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30] });
    } else if (bounds.length === 1) {
        map.setView(bounds[0], 14);
    }
    setTimeout(function() { map.invalidateSize(); }, 150);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
