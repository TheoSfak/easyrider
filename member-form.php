<?php
/**
 * EasyRide - Member Form (Create/Edit)
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('members_manage');

$id = (int) get('id');
$member = null;
$pageTitle = 'Νέο Μέλος';
$currentUser = getCurrentUser();

if ($id) {
    $member = dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$member) {
        setFlash('error', 'Το μέλος δεν βρέθηκε.');
        redirect('members.php');
    }
    // Department admins can only edit users in their own department
    if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && 
        $member['department_id'] != $currentUser['department_id']) {
        setFlash('error', 'Δεν έχετε δικαίωμα επεξεργασίας αυτού του μέλους.');
        redirect('members.php');
    }
    $pageTitle = 'Επεξεργασία: ' . $member['name'];
}

// Load extended profile (member_profiles table)
$profile = $id ? dbFetchOne("SELECT * FROM member_profiles WHERE user_id = ?", [$id]) : null;

// Get member positions
$memberPositions = dbFetchAll("SELECT id, name, color, icon FROM member_positions WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");

// Get custom roles for assignment dropdown
$customRoles = [];
try {
    $customRoles = dbFetchAll("SELECT id, name, color FROM custom_roles ORDER BY name ASC");
} catch (Exception $e) {
    // Table not yet created (migration pending)
}

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $data = [
        'name' => post('name'),
        'email' => post('email'),
        'phone' => post('phone'),
        'role' => post('role', ROLE_MEMBER),
        'department_id' => null,
        'warehouse_id' => null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'position_id' => post('position_id') ?: null,
        'id_card' => post('id_card') ?: null,
        'afm' => post('afm') ?: null,
        'amka' => post('amka') ?: null,
        'driving_license' => post('driving_license') ?: null,
        'vehicle_plate' => post('vehicle_plate') ?: null,
        'club_registry_number' => post('club_registry_number') ?: null,
        'custom_role_id' => post('custom_role_id') ?: null,
    ];

    // Validate custom_role_id — only allowed for MEMBER role
    if ($data['custom_role_id'] !== null) {
        if ($data['role'] === ROLE_SYSTEM_ADMIN) {
            $data['custom_role_id'] = null; // system admins never have a custom role
        } else {
            $validRole = dbFetchValue("SELECT id FROM custom_roles WHERE id = ?", [(int)$data['custom_role_id']]);
            if (!$validRole) {
                $data['custom_role_id'] = null;
            }
        }
    }
    
    // Validation
    if (empty($data['name'])) {
        $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    }
    if (empty($data['email'])) {
        $errors[] = 'Το email είναι υποχρεωτικό.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Μη έγκυρο email.';
    } else {
        // Check unique email
        $existingEmail = dbFetchOne(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$data['email'], $id ?: 0]
        );
        if ($existingEmail) {
            $errors[] = 'Το email χρησιμοποιείται ήδη.';
        }
    }
    
    // Only system admin can create other system admins
    if ($data['role'] === ROLE_SYSTEM_ADMIN && $currentUser['role'] !== ROLE_SYSTEM_ADMIN) {
        $errors[] = 'Δεν έχετε δικαίωμα για αυτόν τον ρόλο.';
    }
    
    // Dept admin can only assign their department
    if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && $data['department_id'] != $currentUser['department_id']) {
        $data['department_id'] = $currentUser['department_id'];
    }
    
    // Password for new users — use raw $_POST to preserve whitespace
    $password = $_POST['password'] ?? '';
    if (!$member && empty($password)) {
        $errors[] = 'Ο κωδικός είναι υποχρεωτικός για νέους χρήστες.';
    } elseif (!$member && strlen($password) < 6) {
        $errors[] = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
    }
    
    if (empty($errors)) {
        try {
        $memberType = post('member_type', VTYPE_RESCUER);
        if (!in_array($memberType, [VTYPE_TRAINEE, VTYPE_RESCUER])) {
            $memberType = VTYPE_RESCUER;
        }
        
        $cohortYear = post('cohort_year') ? post('cohort_year') : null;
        
        if ($member) {
            // Update
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
            
            // Update password if provided
            if (!empty($password)) {
                dbExecute(
                    "UPDATE users SET password = ? WHERE id = ?",
                    [password_hash($password, PASSWORD_DEFAULT), $id]
                );
            }
            
            logAudit('update', 'users', $id);
            setFlash('success', 'Το μέλος ενημερώθηκε.');
        } else {
            // Create
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
            logAudit('create', 'users', $id);
            setFlash('success', 'Το μέλος δημιουργήθηκε.');
        }
        
        // Upsert member_profiles (bio, address, emergency contact, availability, etc.)
        $profileFields = [
            'bio'                     => post('bio'),
            'address'                 => post('address'),
            'city'                    => post('city'),
            'postal_code'             => post('postal_code'),
            'emergency_contact_name'  => post('emergency_contact_name'),
            'emergency_contact_phone' => post('emergency_contact_phone'),
            'blood_type'              => post('blood_type'),
            'available_weekdays'      => isset($_POST['available_weekdays']) ? 1 : 0,
            'available_weekends'      => isset($_POST['available_weekends']) ? 1 : 0,
            'available_nights'        => isset($_POST['available_nights']) ? 1 : 0,
            'has_first_aid'           => isset($_POST['has_first_aid']) ? 1 : 0,
        ];
        $existingProfile = dbFetchOne("SELECT id FROM member_profiles WHERE user_id = ?", [$id]);
        if ($existingProfile) {
            dbExecute(
                "UPDATE member_profiles SET 
                 bio=?, address=?, city=?, postal_code=?,
                 emergency_contact_name=?, emergency_contact_phone=?, blood_type=?,
                 available_weekdays=?, available_weekends=?, available_nights=?, has_first_aid=?,
                 updated_at=NOW() WHERE user_id=?",
                array_merge(array_values($profileFields), [$id])
            );
        } else {
            dbInsert(
                "INSERT INTO member_profiles 
                 (user_id, bio, address, city, postal_code,
                  emergency_contact_name, emergency_contact_phone, blood_type,
                  available_weekdays, available_weekends, available_nights, has_first_aid,
                  created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                array_merge([$id], array_values($profileFields))
            );
        }
        
        redirect('member-view.php?id=' . $id);
        } catch (Exception $e) {
            error_log('Member form error: ' . $e->getMessage());
            setFlash('error', 'Παρουσιάστηκε σφάλμα κατά την αποθήκευση. Παρακαλώ δοκιμάστε ξανά.');
            redirect('members.php');
        }
    }
}

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

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= h($pageTitle) ?></h1>
    <a href="members.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ονοματεπώνυμο *</label>
                    <input type="text" class="form-control" name="name" value="<?= h($form['name']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email *</label>
                    <input type="email" class="form-control" name="email" value="<?= h($form['email']) ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τηλέφωνο</label>
                    <input type="tel" class="form-control" name="phone" value="<?= h($form['phone']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Κωδικός <?= $member ? '(αφήστε κενό για να διατηρηθεί)' : '*' ?></label>
                    <input type="password" class="form-control" name="password" minlength="6" <?= $member ? '' : 'required' ?>>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ρόλος *</label>
                    <select class="form-select" name="role" id="roleSelect" required>
                        <?php foreach (ROLE_LABELS as $r => $label): ?>
                            <?php 
                            // Dept admins can't create system admins
                            if ($r === ROLE_SYSTEM_ADMIN && $currentUser['role'] !== ROLE_SYSTEM_ADMIN) continue;
                            // Legacy roles hidden — replaced by custom roles
                            if (in_array($r, [ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER])) continue;
                            ?>
                            <option value="<?= $r ?>" <?= $form['role'] === $r ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τύπος Μέλους</label>
                    <select class="form-select" name="member_type">
                        <?php foreach (MEMBER_TYPE_LABELS as $vt => $vtLabel): ?>
                            <option value="<?= $vt ?>" <?= ($form['member_type'] ?? VTYPE_RESCUER) === $vt ? 'selected' : '' ?>>>
                                <?= (MEMBER_TYPE_ICONS[$vt] ?? '') . ' ' . $vtLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($customRoles) && ($form['role'] ?? ROLE_MEMBER) !== ROLE_SYSTEM_ADMIN): ?>
            <div class="row" id="customRoleRow">
                <div class="col-md-6 mb-3">
                    <label class="form-label">
                        <i class="bi bi-shield-lock me-1"></i>Προσαρμοσμένος Ρόλος
                    </label>
                    <select class="form-select" name="custom_role_id">
                        <option value="">— Χωρίς ειδικά δικαιώματα —</option>
                        <?php foreach ($customRoles as $cr): ?>
                            <option value="<?= $cr['id'] ?>"
                                    data-color="<?= h($cr['color']) ?>"
                                    <?= ($form['custom_role_id'] ?? null) == $cr['id'] ? 'selected' : '' ?>>
                                <?= h($cr['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">
                        Ορίζει ποιες σελίδες βλέπει ο χρήστης. <a href="roles.php" target="_blank">Διαχείριση ρόλων</a>
                    </small>
                </div>
                <div class="col-md-6 mb-3 d-flex align-items-center pt-4">
                    <span id="customRoleBadge" class="badge rounded-pill px-3 py-2 d-none" style="font-size:0.95rem;"></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><i class="bi bi-person-badge me-1"></i>Θέση / Ρόλος στην Οργάνωση</label>
                    <select class="form-select" name="position_id">
                        <option value="">— Χωρίς θέση —</option>
                        <?php foreach ($memberPositions as $pos): ?>
                            <option value="<?= $pos['id'] ?>" <?= ($form['position_id'] ?? '') == $pos['id'] ? 'selected' : '' ?>>
                                <?= h($pos['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Οργανωτικός ρόλος εντός του σώματος. <a href="member-positions.php" target="_blank">Διαχείριση θέσεων</a></small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Χρονιά Σειράς</label>
                    <input type="number" class="form-control" name="cohort_year" 
                           value="<?= h($form['cohort_year'] ?? '') ?>" 
                           placeholder="π.χ. 2026" min="2020" max="2099"
                           title="Χρονιά σειράς δοκίμων (προαιρετικό)">
                    <small class="text-muted">Για δόκιμους διασώστες - χρησιμοποιείται για στατιστικά ανά χρονιά</small>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                           <?= $form['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">
                        Ενεργός λογαριασμός
                    </label>
                </div>
            </div>
            
            <hr>
            <h5 class="mb-3"><i class="bi bi-card-heading me-2"></i>Προσωπικά Στοιχεία</h5>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ταυτότητα</label>
                    <input type="text" class="form-control" name="id_card" value="<?= h($form['id_card'] ?? '') ?>" placeholder="Αριθμός Ταυτότητας">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">ΑΦΜ</label>
                    <input type="text" class="form-control" name="afm" value="<?= h($form['afm'] ?? '') ?>" placeholder="9 ψηφία" maxlength="9" inputmode="numeric">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Α.Μ.Κ.Α.</label>
                    <input type="text" class="form-control" name="amka" value="<?= h($form['amka'] ?? '') ?>" placeholder="11 ψηφία" maxlength="11">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Άδεια Οδήγησης</label>
                    <input type="text" class="form-control" name="driving_license" value="<?= h($form['driving_license'] ?? '') ?>" placeholder="Αριθμός Αδείας">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Αριθμός Κυκλοφορίας</label>
                    <input type="text" class="form-control" name="vehicle_plate" value="<?= h($form['vehicle_plate'] ?? '') ?>" placeholder="π.χ. ΑΒΓ-1234">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Αρ. Μητρώου Λέσχης</label>
                    <input type="text" class="form-control" name="club_registry_number" value="<?= h($form['club_registry_number'] ?? '') ?>" placeholder="Προαιρετικό">
                </div>
            </div>

            <hr>
            <h5 class="mb-3"><i class="bi bi-person-lines-fill me-2"></i>Προφίλ Μέλους</h5>

            <div class="mb-3">
                <label class="form-label">Σύντομο Βιογραφικό</label>
                <textarea class="form-control" name="bio" rows="3"><?= h($profile['bio'] ?? '') ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ομάδα Αίματος</label>
                    <select class="form-select" name="blood_type">
                        <option value="">-</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'] as $bt): ?>
                            <option value="<?= $bt ?>" <?= ($profile['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h6 class="mb-2">Διεύθυνση</h6>
            <div class="mb-3">
                <label class="form-label">Οδός / Αριθμός</label>
                <input type="text" class="form-control" name="address" value="<?= h($profile['address'] ?? '') ?>">
            </div>
            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Πόλη</label>
                    <input type="text" class="form-control" name="city" value="<?= h($profile['city'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Τ.Κ.</label>
                    <input type="text" class="form-control" name="postal_code" value="<?= h($profile['postal_code'] ?? '') ?>">
                </div>
            </div>

            <h6 class="mb-2">Επαφή Έκτακτης Ανάγκης</h6>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Όνομα</label>
                    <input type="text" class="form-control" name="emergency_contact_name" value="<?= h($profile['emergency_contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τηλέφωνο</label>
                    <input type="tel" class="form-control" name="emergency_contact_phone" value="<?= h($profile['emergency_contact_phone'] ?? '') ?>">
                </div>
            </div>

            <h6 class="mb-2">Διαθεσιμότητα</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_weekdays" id="avail_weekdays"
                               <?= ($profile['available_weekdays'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_weekdays">Καθημερινές</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_weekends" id="avail_weekends"
                               <?= ($profile['available_weekends'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_weekends">Σαββατοκύριακα</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_nights" id="avail_nights"
                               <?= ($profile['available_nights'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_nights">Νυχτερινές</label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="has_first_aid" id="has_first_aid"
                           <?= ($profile['has_first_aid'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="has_first_aid">Πιστοποίηση Πρώτων Βοηθειών</label>
                </div>
            </div>
            
            <hr>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Αποθήκευση
            </button>
            <a href="members.php" class="btn btn-outline-secondary">Ακύρωση</a>
        </form>
    </div>
</div>

<?php if (!empty($customRoles)): ?>
<script>
(function () {
    const roleSelect       = document.getElementById('roleSelect');
    const customRoleRow    = document.getElementById('customRoleRow');
    const customRoleSelect = customRoleRow ? customRoleRow.querySelector('select[name="custom_role_id"]') : null;
    const badge            = document.getElementById('customRoleBadge');

    function updateBadge() {
        if (!customRoleSelect || !badge) return;
        const selected = customRoleSelect.options[customRoleSelect.selectedIndex];
        if (selected && selected.value) {
            badge.textContent    = selected.text;
            badge.style.backgroundColor = selected.dataset.color || '#6c757d';
            badge.style.color    = '#fff';
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    function toggleCustomRoleRow() {
        if (!customRoleRow || !roleSelect) return;
        const isSysAdmin = roleSelect.value === '<?= ROLE_SYSTEM_ADMIN ?>';
        customRoleRow.style.display = isSysAdmin ? 'none' : '';
        if (isSysAdmin && customRoleSelect) {
            customRoleSelect.value = '';
            badge.classList.add('d-none');
        }
    }

    if (roleSelect)       roleSelect.addEventListener('change', toggleCustomRoleRow);
    if (customRoleSelect) customRoleSelect.addEventListener('change', updateBadge);

    // Init on page load
    toggleCustomRoleRow();
    updateBadge();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
