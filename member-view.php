<?php
/**
 * VolunteerOps - Member View
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('members_view');

$id = (int) get('id');
if (!$id) {
    redirect('members.php');
}

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

if (!$member) {
    setFlash('error', 'Το μέλος δεν βρέθηκε.');
    redirect('members.php');
}

$pageTitle = $member['name'];

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'delete_personal_data') {
        // GDPR-compliant personal data deletion
        // Only system admins can delete personal data
        if (!isSystemAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('member-view.php?id=' . $id);
        }
        
        // Anonymize user data
        $anonymizedEmail = 'deleted_' . $id . '_' . time() . '@deleted.local';
        
        db()->beginTransaction();
        try {
            dbExecute(
                "UPDATE users SET 
                 name = ?, 
                 email = ?, 
                 phone = NULL, 
                 is_active = 0,
                 updated_at = NOW() 
                 WHERE id = ?",
                ['[Διαγραμμένος Χρήστης]', $anonymizedEmail, $id]
            );
            
            // Delete member profile
            dbExecute("DELETE FROM member_profiles WHERE user_id = ?", [$id]);
            
            // Delete user skills
            dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$id]);
            
            // Delete user achievements
            dbExecute("DELETE FROM user_achievements WHERE user_id = ?", [$id]);
            
            // Delete notifications
            dbExecute("DELETE FROM notifications WHERE user_id = ?", [$id]);
            
            logAudit('delete_personal_data', 'users', $id, 'GDPR data deletion');
            
            db()->commit();
            setFlash('success', 'Τα προσωπικά δεδομένα διαγράφηκαν επιτυχώς.');
        } catch (Exception $e) {
            db()->rollBack();
            setFlash('error', 'Σφάλμα κατά τη διαγραφή προσωπικών δεδομένων.');
        }
        redirect('members.php');
    }

    if ($action === 'upload_document') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('member-view.php?id=' . $id);
        }
        if (empty($_FILES['doc_files']['name'][0])) {
            setFlash('error', 'Παρακαλώ επιλέξτε τουλάχιστον ένα αρχείο.');
            redirect('member-view.php?id=' . $id . '#documents');
        }
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif',
                        'image/webp', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $allowedExt  = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx'];
        $destDir     = __DIR__ . '/uploads/member-docs/';
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        $uploadedCount = 0;
        $errors        = [];
        $fileCount     = count($_FILES['doc_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if (empty($_FILES['doc_files']['tmp_name'][$i])) continue;
            $tmpName  = $_FILES['doc_files']['tmp_name'][$i];
            $origName = basename($_FILES['doc_files']['name'][$i]);
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (class_exists('finfo')) {
                $fi   = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->file($tmpName);
            } else {
                $mime = mime_content_type($tmpName);
            }
            if (!in_array($ext, $allowedExt) || !in_array($mime, $allowedMime)) {
                $errors[] = 'Μη επιτρεπτός τύπος: ' . $origName;
                continue;
            }
            $maxSize = 15 * 1024 * 1024; // 15MB
            if ($_FILES['doc_files']['size'][$i] > $maxSize) {
                $errors[] = 'Υπέρβαση μεγέθους (15MB): ' . $origName;
                continue;
            }
            $label      = pathinfo($origName, PATHINFO_FILENAME);
            $storedName = 'vdoc_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmpName, $destDir . $storedName)) {
                $errors[] = 'Αποτυχία αποθήκευσης: ' . $origName;
                continue;
            }
            dbInsert(
                "INSERT INTO member_documents (user_id, label, original_name, stored_name, mime_type, file_size, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [$id, $label, $origName, $storedName, $mime, $_FILES['doc_files']['size'][$i], getCurrentUserId()]
            );
            logAudit('upload_document', 'member_documents', $id, $origName);
            $uploadedCount++;
        }
        if ($uploadedCount > 0) {
            $msg = 'Ανέβηκαν επιτυχώς ' . $uploadedCount . ' αρχεία.';
            if (!empty($errors)) $msg .= ' Σφάλματα: ' . implode(', ', $errors);
            setFlash('success', $msg);
        } else {
            setFlash('error', 'Κανένα αρχείο δεν ανέβηκε.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
        }
        redirect('member-view.php?id=' . $id . '#documents');
    }

    if ($action === 'delete_document') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('member-view.php?id=' . $id);
        }
        $docId = (int) post('doc_id');
        $doc   = dbFetchOne("SELECT * FROM member_documents WHERE id = ? AND user_id = ?", [$docId, $id]);
        if ($doc) {
            $filePath = __DIR__ . '/uploads/member-docs/' . $doc['stored_name'];
            if (file_exists($filePath)) unlink($filePath);
            dbExecute("DELETE FROM member_documents WHERE id = ?", [$docId]);
            logAudit('delete_document', 'member_documents', $docId, $doc['label']);
            setFlash('success', 'Το αρχείο διαγράφηκε.');
        }
        redirect('member-view.php?id=' . $id . '#documents');
    }

    if ($action === 'upload_photo') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('member-view.php?id=' . $id);
        }
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMime)) {
                setFlash('error', 'Επιτρέπονται μόνο αρχεία εικόνας (JPG, PNG, GIF, WebP).');
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                setFlash('error', 'Το αρχείο δεν μπορεί να είναι μεγαλύτερο από 5MB.');
            } else {
                $src = match($mime) {
                    'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                    'image/png'  => imagecreatefrompng($file['tmp_name']),
                    'image/gif'  => imagecreatefromgif($file['tmp_name']),
                    'image/webp' => imagecreatefromwebp($file['tmp_name']),
                    default      => false,
                };
                if ($src) {
                    $srcW = imagesx($src);
                    $srcH = imagesy($src);
                    $cropSize = min($srcW, $srcH);
                    $cropX = (int)(($srcW - $cropSize) / 2);
                    $cropY = (int)(($srcH - $cropSize) / 2);
                    $dst = imagecreatetruecolor(250, 250);
                    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 250, 250, $cropSize, $cropSize);
                    $filename = $id . '.jpg';
                    $savePath = __DIR__ . '/uploads/avatars/' . $filename;
                    $avatarDir = dirname($savePath);
                    if (!is_dir($avatarDir)) {
                        mkdir($avatarDir, 0755, true);
                    }
                    imagejpeg($dst, $savePath, 90);
                    imagedestroy($src);
                    imagedestroy($dst);
                    dbExecute("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?", [$filename, $id]);
                    logAudit('upload_photo', 'users', $id);
                    setFlash('success', 'Η φωτογραφία προφίλ ενημερώθηκε.');
                } else {
                    setFlash('error', 'Αδυναμία επεξεργασίας της εικόνας.');
                }
            }
        } elseif (post('delete_photo') === '1') {
            $oldFile = __DIR__ . '/uploads/avatars/' . $id . '.jpg';
            if (file_exists($oldFile)) unlink($oldFile);
            dbExecute("UPDATE users SET profile_photo = NULL, updated_at = NOW() WHERE id = ?", [$id]);
            logAudit('delete_photo', 'users', $id);
            setFlash('success', 'Η φωτογραφία διαγράφηκε.');
        }
        redirect('member-view.php?id=' . $id);
    }

    if ($action === 'update_member_since') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('member-view.php?id=' . $id);
        }
        $newDate = post('member_since_date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !strtotime($newDate)) {
            setFlash('error', 'Μη έγκυρη ημερομηνία.');
        } else {
            dbExecute("UPDATE users SET created_at = ? WHERE id = ?", [$newDate . ' 00:00:00', $id]);
            logAudit('update_member_since', 'users', $id, 'Αλλαγή ημερομηνίας μέλους σε ' . $newDate);
            setFlash('success', 'Η ημερομηνία μέλους ενημερώθηκε.');
        }
        redirect('member-view.php?id=' . $id);
    }

}

// Get profile
$profile = dbFetchOne("SELECT * FROM member_profiles WHERE user_id = ?", [$id]);

// Get documents
$documents = dbFetchAll(
    "SELECT vd.*, u.name as uploader_name FROM member_documents vd
     LEFT JOIN users u ON vd.uploaded_by = u.id
     WHERE vd.user_id = ? ORDER BY vd.created_at DESC",
    [$id]
);

// Get achievements
$achievements = [];
if (getSetting('achievements_enabled', '1') === '1') {
    $achievements = dbFetchAll(
        "SELECT a.*, ua.earned_at FROM achievements a
         JOIN user_achievements ua ON a.id = ua.achievement_id
         WHERE ua.user_id = ?
         ORDER BY ua.earned_at DESC",
        [$id]
    );
}

// Get recent participations
$participations = dbFetchAll(
    "SELECT pr.*, s.start_time, s.end_time, m.title as mission_title
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.member_id = ?
     ORDER BY s.start_time DESC
     LIMIT 20",
    [$id]
);

// Get stats
$stats = [
    'total_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE member_id = ? AND status = '" . PARTICIPATION_APPROVED . "'",
        [$id]
    ),
    'attended_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE member_id = ? AND attended = 1",
        [$id]
    ),
    'total_hours' => dbFetchValue(
        "SELECT COALESCE(SUM(actual_hours), 0) FROM participation_requests WHERE member_id = ? AND attended = 1",
        [$id]
    ),
    'pending_requests' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE member_id = ? AND status = '" . PARTICIPATION_PENDING . "'",
        [$id]
    ),
];

// Mission attendance count for current year (only Υγειονομική + Διασωστική count)
$currentYear = date('Y');
$attTypeIds = getAttendanceMissionTypeIds();
if (empty($attTypeIds)) {
    $missionAttendance = 0;
} else {
    $attPlaceholders = implode(',', array_fill(0, count($attTypeIds), '?'));
    $missionAttendance = (int) dbFetchValue(
        "SELECT COUNT(DISTINCT m.id)
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.member_id = ? AND pr.attended = 1
         AND YEAR(m.start_datetime) = ?
         AND m.mission_type_id IN ($attPlaceholders)",
        array_merge([$id, $currentYear], $attTypeIds)
    );
}
$attendanceGoal = (int) getSetting('prereq_attendance_goal', '10');
$attendancePct = $attendanceGoal > 0 ? min(100, round(($missionAttendance / $attendanceGoal) * 100)) : 0;
$attendanceColor = $missionAttendance >= $attendanceGoal ? 'success' : ($missionAttendance >= 7 ? 'info' : ($missionAttendance >= 4 ? 'warning' : 'danger'));

// Τ.Ε.Π. hours (only relevant for TRAINEE_RESCUER)
$tepHours = 0;
$tepGoal = (int) getSetting('prereq_tep_hours_goal', '40');
$tepPct = 0;
$tepColor = 'danger';
if (($member['member_type'] ?? '') === VTYPE_TRAINEE) {
    $tepHours = (float) dbFetchValue(
        "SELECT COALESCE(SUM(
            CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
            ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END
        ), 0)
        FROM participation_requests pr
        JOIN shifts s ON pr.shift_id = s.id
        JOIN missions m ON s.mission_id = m.id
        WHERE pr.member_id = ? AND pr.status = ? AND pr.attended = 1
          AND m.mission_type_id = ?",
        [$id, PARTICIPATION_APPROVED, getTepMissionTypeId()]
    );
    $tepPct = $tepGoal > 0 ? min(100, round(($tepHours / $tepGoal) * 100)) : 0;
    $tepColor = $tepHours >= $tepGoal ? 'success' : ($tepHours >= 25 ? 'info' : ($tepHours >= 10 ? 'warning' : 'danger'));
}

// Educational missions (only for RESCUER)
$eduMissions = 0;
$eduGoal = (int) getSetting('prereq_edu_attendance_goal', '2');
$eduPct = 0;
$eduColor = 'danger';
if (($member['member_type'] ?? '') === VTYPE_RESCUER) {
    $eduMissions = (int) dbFetchValue(
        "SELECT COUNT(DISTINCT m.id)
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.member_id = ? AND pr.attended = 1
           AND YEAR(m.start_datetime) = ?
           AND m.mission_type_id = ?",
        [$id, $currentYear, getEduMissionTypeId()]
    );
    $eduPct = $eduGoal > 0 ? min(100, round(($eduMissions / $eduGoal) * 100)) : 0;
    $eduColor = $eduMissions >= $eduGoal ? 'success' : ($eduMissions >= 1 ? 'warning' : 'danger');
}

// Points history
$pointsHistory = dbFetchAll(
    "SELECT vp.*, 
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN s.id ELSE NULL END as shift_id,
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN m.title ELSE NULL END as shift_title
     FROM member_points vp
     LEFT JOIN shifts s ON vp.pointable_type = 'App\\\\Models\\\\Shift' AND vp.pointable_id = s.id
     LEFT JOIN missions m ON s.mission_id = m.id
     WHERE vp.user_id = ?
     ORDER BY vp.created_at DESC
     LIMIT 10",
    [$id]
);

// Active inventory bookings for this member
$activeBookings = [];
if (function_exists('inventoryTablesExist') && inventoryTablesExist()) {
    $activeBookings = getUserActiveBookings($id);
} elseif (file_exists(__DIR__ . '/includes/inventory-functions.php')) {
    require_once __DIR__ . '/includes/inventory-functions.php';
    if (inventoryTablesExist()) {
        $activeBookings = getUserActiveBookings($id);
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* Member Profile Beautification */
.hero-profile {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 1rem;
    padding: 1.5rem;
    color: #fff;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 32px rgba(102,126,234,.25);
    position: relative;
    overflow: hidden;
}
.hero-profile::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,.06);
    border-radius: 50%;
}
.hero-profile .hero-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,.4);
    box-shadow: 0 4px 15px rgba(0,0,0,.2);
}
.hero-profile .hero-avatar-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    border: 3px solid rgba(255,255,255,.4);
}
.hero-profile .hero-actions .btn {
    border-color: rgba(255,255,255,.4);
    color: #fff;
    backdrop-filter: blur(4px);
    background: rgba(255,255,255,.1);
    font-size: .82rem;
    padding: .35rem .75rem;
}
.hero-profile .hero-actions .btn:hover {
    background: rgba(255,255,255,.25);
    border-color: rgba(255,255,255,.7);
}
.vp-stat-card {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    transition: transform .2s, box-shadow .2s;
    overflow: hidden;
}
.vp-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
}
.vp-stat-card .stat-icon {
    width: 42px;
    height: 42px;
    border-radius: .6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #fff;
    flex-shrink: 0;
}
.vp-stat-card .stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}
.vp-card {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    transition: box-shadow .2s;
    overflow: hidden;
}
.vp-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); }
.vp-card .card-header {
    background: #fff;
    border-bottom: 2px solid #eee;
    padding: .75rem 1rem;
}
.vp-card .card-header h5 { font-size: .95rem; font-weight: 600; }
.vp-card.border-accent-primary .card-header { border-bottom-color: #667eea; }
.vp-card.border-accent-success .card-header { border-bottom-color: #10b981; }
.vp-card.border-accent-info .card-header { border-bottom-color: #06b6d4; }
.vp-card.border-accent-warning .card-header { border-bottom-color: #f59e0b; }
.vp-card.border-accent-danger .card-header { border-bottom-color: #ef4444; }
.vp-card.border-accent-secondary .card-header { border-bottom-color: #6c757d; }
.vp-info-label { color: #6c757d; font-size: .82rem; margin-bottom: .15rem; }
.vp-info-value { font-weight: 500; font-size: .92rem; margin-bottom: .75rem; }
@media (max-width: 768px) {
    .hero-profile { padding: 1rem; text-align: center; }
    .hero-profile .d-flex { flex-direction: column; gap: .75rem; }
    .hero-profile .hero-actions { justify-content: center !important; flex-wrap: wrap; }
    .vp-stat-card .card-body { padding: .65rem !important; }
    .vp-stat-card .stat-value { font-size: 1.2rem; }
}
</style>

<!-- Hero Profile Header -->
<div class="hero-profile">
    <div class="d-flex align-items-center gap-3">
        <?php
        $vpPhoto = $member['profile_photo'] ?? null;
        $vpPhotoExists = $vpPhoto && file_exists(__DIR__ . '/uploads/avatars/' . $vpPhoto);
        ?>
        <?php if ($vpPhotoExists): ?>
            <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($vpPhoto) ?>?t=<?= time() ?>" class="hero-avatar" alt="">
        <?php else: ?>
            <div class="hero-avatar-placeholder"><i class="bi bi-person-fill"></i></div>
        <?php endif; ?>
        <div class="flex-grow-1">
            <h1 class="h4 mb-1 text-white fw-bold">
                <?= h($member['name']) ?>
                <?= memberTypeBadge($member['member_type'] ?? VTYPE_RESCUER) ?>
                <?php if (!empty($member['position_name'])): ?>
                    <span class="badge bg-<?= h($member['position_color'] ?? 'secondary') ?> ms-1" style="font-size:.7rem">
                        <?php if ($member['position_icon']): ?><i class="<?= h($member['position_icon']) ?> me-1"></i><?php endif; ?>
                        <?= h($member['position_name']) ?>
                    </span>
                <?php endif; ?>
            </h1>
            <div style="opacity:.85">
                <i class="bi bi-envelope me-1"></i><?= h($member['email']) ?>
                <?php if ($member['phone']): ?>
                    <span class="ms-3"><i class="bi bi-telephone me-1"></i><?php if ($member['phone']): ?><a href="tel:<?= h($member['phone']) ?>" style="color:#fff !important; text-decoration:none;"><?= h($member['phone']) ?></a><?php else: ?>-<?php endif; ?></span>
                <?php endif; ?>
            </div>
            <div class="mt-1">
                <?php if ($member['is_active']): ?>
                    <span class="badge bg-success" style="font-size:.72rem"><i class="bi bi-check-circle me-1"></i>Ενεργός</span>
                <?php else: ?>
                    <span class="badge bg-secondary" style="font-size:.72rem">Ανενεργός</span>
                <?php endif; ?>
                <?= roleBadge($member['role']) ?>
                <span class="badge bg-light text-dark ms-1" style="font-size:.72rem"><i class="bi bi-calendar3 me-1"></i>Μέλος από <?= formatDate($member['created_at']) ?><?php if (isAdmin()): ?> <a href="#" data-bs-toggle="modal" data-bs-target="#memberSinceModal" style="color:#6c757d;" title="Αλλαγή ημερομηνίας"><i class="bi bi-pencil-square"></i></a><?php endif; ?></span>
            </div>
        </div>
        <div class="hero-actions d-flex gap-2 flex-shrink-0">
            <a href="member-form.php?id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-pencil me-1"></i>Επεξεργασία</a>
            <a href="member-report.php?id=<?= $id ?>" target="_blank" class="btn btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Αναφορά</a>
            <?php if (isSystemAdmin()): ?>
                <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#deleteDataModal">
                    <i class="bi bi-shield-x me-1"></i>Διαγραφή Δεδομένων
                </button>
            <?php endif; ?>
            <a href="members.php" class="btn btn-sm"><i class="bi bi-arrow-left me-1"></i>Πίσω</a>
        </div>
    </div>
</div>

<!-- Annual Mission Attendance Progress -->
<div class="card vp-card mb-4" style="border-left: 4px solid var(--bs-<?= $attendanceColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-calendar-check text-<?= $attendanceColor ?> me-1"></i>
                <strong>Παρουσίες Αποστολών <?= $currentYear ?></strong>
                <span class="text-muted ms-2 d-none d-md-inline">(στόχος: <?= $attendanceGoal ?>)</span>
            </div>
            <div>
                <span class="badge bg-<?= $attendanceColor ?> fs-6"><?= $missionAttendance ?> / <?= $attendanceGoal ?></span>
                <?php if ($missionAttendance >= $attendanceGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Πληροί την προϋπόθεση!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $attendanceColor ?><?= $missionAttendance < $attendanceGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $attendancePct ?>%"
                 aria-valuenow="<?= $missionAttendance ?>" aria-valuemin="0" aria-valuemax="<?= $attendanceGoal ?>">
                <?= $attendancePct ?>%
            </div>
        </div>
        <?php if ($missionAttendance < $attendanceGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <?= $attendanceGoal - $missionAttendance ?> παρουσίες για ολοκλήρωση του στόχου</small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Το μέλος πληροί την προϋπόθεση παραμονής ενεργού μέλους για το <?= $currentYear + 1 ?></small>
        <?php endif; ?>
    </div>
</div>

<?php if (($member['member_type'] ?? '') === VTYPE_TRAINEE): ?>
<!-- Τ.Ε.Π. Hours Progress Bar (only for trainee rescuers) -->
<div class="card vp-card mb-4" style="border-left: 4px solid var(--bs-<?= $tepColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-hospital text-<?= $tepColor ?> me-1"></i>
                <strong>Ώρες Τ.Ε.Π.</strong>
                <span class="text-muted ms-2 d-none d-md-inline">(στόχος: <?= $tepGoal ?> ώρες)</span>
                <span class="badge bg-secondary ms-1">Τμήμα Επειγόντων</span>
            </div>
            <div>
                <span class="badge bg-<?= $tepColor ?> fs-6"><?= number_format($tepHours, 1) ?> / <?= $tepGoal ?> ώρες</span>
                <?php if ($tepHours >= $tepGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Ολοκλήρωσε τον στόχο!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $tepColor ?><?= $tepHours < $tepGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $tepPct ?>%"
                 aria-valuenow="<?= $tepHours ?>" aria-valuemin="0" aria-valuemax="<?= $tepGoal ?>">
                <?= $tepPct ?>%
            </div>
        </div>
        <?php if ($tepHours < $tepGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <strong><?= number_format($tepGoal - $tepHours, 1) ?></strong> ώρες από τις <?= $tepGoal ?> απαιτούμενες σε αποστολές Τ.Ε.Π.</small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Ο δόκιμος έχει ολοκληρώσει τον στόχο των <?= $tepGoal ?> ωρών Τ.Ε.Π.!</small>
        <?php endif; ?>
    </div>
</div>
<?php endif; // VTYPE_TRAINEE ?>

<?php if (($member['member_type'] ?? '') === VTYPE_RESCUER): ?>
<!-- Εκπαιδευτικές Αποστολές Progress Bar (only for rescuers) -->
<div class="card vp-card mb-4" style="border-left: 4px solid var(--bs-<?= $eduColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-mortarboard text-<?= $eduColor ?> me-1"></i>
                <strong>Εκπαιδευτικές Αποστολές <?= $currentYear ?></strong>
                <span class="text-muted ms-2 d-none d-md-inline">(απαιτούνται: <?= $eduGoal ?>)</span>
            </div>
            <div>
                <span class="badge bg-<?= $eduColor ?> fs-6"><?= $eduMissions ?> / <?= $eduGoal ?></span>
                <?php if ($eduMissions >= $eduGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Πληροί την προϋπόθεση!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $eduColor ?><?= $eduMissions < $eduGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $eduPct ?>%"
                 aria-valuenow="<?= $eduMissions ?>" aria-valuemin="0" aria-valuemax="<?= $eduGoal ?>">
                <?= $eduPct ?>%
            </div>
        </div>
        <?php if ($eduMissions < $eduGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <strong><?= $eduGoal - $eduMissions ?></strong> εκπαιδευτικές αποστολές από τις <?= $eduGoal ?> απαιτούμενες</small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Το μέλος πληροί την προϋπόθεση συμμετοχής σε <?= $eduGoal ?> εκπαιδευτικές αποστολές!</small>
        <?php endif; ?>
    </div>
</div>
<?php endif; // VTYPE_RESCUER ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card vp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)"><i class="bi bi-calendar2-check"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= $stats['total_shifts'] ?></div>
                    <small class="text-muted">Κύκλοι Εγγραφών</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card vp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#10b981,#059669)"><i class="bi bi-person-check"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= $stats['attended_shifts'] ?></div>
                    <small class="text-muted">Παρουσίες</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card vp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= number_format($stats['total_hours'], 1) ?></div>
                    <small class="text-muted">Ώρες</small>
                </div>
            </div>
        </div>
    </div>
    <?php if (getSetting('points_enabled', '1') === '1'): ?>
    <div class="col-6 col-md-3">
        <div class="card vp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="bi bi-star-fill"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= number_format($member['total_points']) ?></div>
                    <small class="text-muted">Πόντοι</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Info -->
        <div class="card vp-card border-accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Στοιχεία Προφίλ</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="vp-info-label"><i class="bi bi-telephone me-1"></i>Τηλέφωνο</div>
                        <div class="vp-info-value"><?= h($member['phone'] ?: '-') ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="vp-info-label"><i class="bi bi-card-text me-1"></i>Ταυτότητα</div>
                        <div class="vp-info-value"><?= h($member['id_card'] ?: '-') ?></div>
                        <div class="vp-info-label"><i class="bi bi-receipt me-1"></i>ΑΦΜ</div>
                        <div class="vp-info-value"><?= h($member['afm'] ?: '-') ?></div>
                        <?php if (!empty($member['position_name'])): ?>
                        <div class="vp-info-label"><i class="bi bi-person-gear me-1"></i>Θέση / Ρόλος</div>
                        <div class="vp-info-value">
                            <span class="badge bg-<?= h($member['position_color'] ?? 'secondary') ?>">
                                <?php if ($member['position_icon']): ?><i class="<?= h($member['position_icon']) ?> me-1"></i><?php endif; ?>
                                <?= h($member['position_name']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="vp-info-label"><i class="bi bi-hash me-1"></i>Α.Μ.Κ.Α.</div>
                        <div class="vp-info-value"><?= h($member['amka'] ?: '-') ?></div>
                        <div class="vp-info-label"><i class="bi bi-car-front me-1"></i>Άδεια Οδήγησης / Όχημα</div>
                        <div class="vp-info-value"><?= h($member['driving_license'] ?: '-') ?> <?= $member['vehicle_plate'] ? '/ ' . h($member['vehicle_plate']) : '' ?></div>
                        <?php if (!empty($member['motorcycle_brand_name'])): ?>
                        <div class="vp-info-label"><i class="bi bi-bicycle me-1"></i>Μηχανή</div>
                        <div class="vp-info-value"><?= h($member['motorcycle_brand_name']) ?><?= $member['motorcycle_model_name'] ? ' / ' . h($member['motorcycle_model_name']) : '' ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($member['club_registry_number'])): ?>
                <hr class="my-3">
                <div class="vp-info-label"><i class="bi bi-journal-text me-1"></i>Αρ. Μητρώου Λέσχης</div>
                <div class="vp-info-value"><?= h($member['club_registry_number']) ?></div>
                <?php endif; ?>

                <?php if ($profile): ?>
                <hr>
                <h6 class="text-muted mb-3"><i class="bi bi-person-lines-fill me-1"></i>Προφίλ Μέλους</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Ομάδα Αίματος:</strong> <?= h(($profile['blood_type'] ?? '') ?: '-') ?></p>
                        <p><strong>Διεύθυνση:</strong>
                            <?php
                                $addrParts = array_filter([
                                    $profile['address'] ?? '',
                                    $profile['city'] ?? '',
                                    $profile['postal_code'] ?? '',
                                ]);
                                echo $addrParts ? h(implode(', ', $addrParts)) : '-';
                            ?>
                        </p>
                        <?php if (!empty($profile['bio'])): ?>
                        <p><strong>Βιογραφικό:</strong><br><?= nl2br(h($profile['bio'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Επαφή Έκτακτης Ανάγκης:</strong><br>
                            <?= h(($profile['emergency_contact_name'] ?? '') ?: '-') ?>
                            <?= !empty($profile['emergency_contact_phone']) ? ' (' . h($profile['emergency_contact_phone']) . ')' : '' ?>
                        </p>
                        <p><strong>Διαθεσιμότητα:</strong><br>
                            <?php if ($profile['available_weekdays'] ?? 0): ?><span class="badge bg-info me-1">Καθημερινές</span><?php endif; ?>
                            <?php if ($profile['available_weekends'] ?? 0): ?><span class="badge bg-info me-1">Σαββατοκύριακα</span><?php endif; ?>
                            <?php if ($profile['available_nights'] ?? 0): ?><span class="badge bg-secondary me-1">Νυχτερινές</span><?php endif; ?>
                            <?php if (!($profile['available_weekdays'] ?? 0) && !($profile['available_weekends'] ?? 0) && !($profile['available_nights'] ?? 0)): ?><span class="text-muted">-</span><?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php else: ?>
                <hr>
                <h6 class="text-muted mb-3"><i class="bi bi-person-lines-fill me-1"></i>Προφίλ Μέλους</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Ομάδα Αίματος:</strong> -</p>
                        <p><strong>Διεύθυνση:</strong> -</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Επαφή Έκτακτης Ανάγκης:</strong> -</p>
                        <p><strong>Διαθεσιμότητα:</strong> -</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Participations -->
        <div class="card vp-card border-accent-success mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-check text-success me-2"></i>Πρόσφατες Συμμετοχές</h5>
            </div>
            <div class="card-body">
                <?php if (empty($participations)): ?>
                    <p class="text-muted">Δεν υπάρχουν συμμετοχές.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Κύκλος Εγγραφών</th>
                                    <th>Αποστολή</th>
                                    <th>Ημ/νία</th>
                                    <th>Κατάσταση</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participations as $p): ?>
                                    <tr>
                                        <td>
                                            <a href="shift-view.php?id=<?= $p['shift_id'] ?>">
                                                <?= h($p['mission_title']) ?> - <?= formatDateTime($p['start_time'], 'H:i') ?>
                                            </a>
                                        </td>
                                        <td><?= h($p['mission_title']) ?></td>
                                        <td><?= formatDate($p['start_time']) ?></td>
                                        <td>
                                            <?= statusBadge($p['status']) ?>
                                            <?php if ($p['attended']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        

        <?php if (!empty($activeBookings)): ?>
        <!-- Χρεωμένα Υλικά -->
        <div class="card vp-card border-accent-primary mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Χρεωμένα Υλικά</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary rounded-pill"><?= count($activeBookings) ?></span>
                    <a href="inventory.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-seam me-1"></i>Αποθήκη</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Barcode</th>
                                <th>Υλικό</th>
                                <th>Ημ. Χρέωσης</th>
                                <th>Αναμ. Επιστροφή</th>
                                <th>Κατάσταση</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeBookings as $bk):
                                $overdue = $bk['status'] === 'overdue';
                                $expectedReturn = $bk['expected_return_date'] ?? null;
                            ?>
                            <tr class="<?= $overdue ? 'table-danger' : '' ?>">
                                <td><code class="text-primary"><?= h($bk['barcode']) ?></code></td>
                                <td>
                                    <a href="inventory-view.php?id=<?= $bk['item_id'] ?>" class="text-decoration-none fw-medium">
                                        <?= h($bk['item_name']) ?>
                                    </a>
                                </td>
                                <td><small><?= formatDate($bk['created_at']) ?></small></td>
                                <td>
                                    <?php if ($expectedReturn): ?>
                                        <small class="<?= $overdue ? 'text-danger fw-bold' : '' ?>">
                                            <?= formatDate($expectedReturn) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($overdue): ?>
                                        <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Εκπρόθεσμη</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Ενεργή</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Profile Photo -->
        <div class="card vp-card border-accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-camera text-primary me-2"></i>Φωτογραφία Προφίλ</h5>
            </div>
            <div class="card-body text-center">
                <?php
                $memberPhoto = $member['profile_photo'] ?? null;
                $photoExists = $memberPhoto && file_exists(__DIR__ . '/uploads/avatars/' . $memberPhoto);
                ?>
                <?php if ($photoExists): ?>
                    <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($memberPhoto) ?>?t=<?= time() ?>"
                         class="rounded-circle mb-3" style="width:120px;height:120px;object-fit:cover;" alt="">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
                         style="width:120px;height:120px;font-size:3rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                <?php endif; ?>
                <h5 class="mb-1"><?= h($member['name']) ?></h5>
                <small class="text-muted d-block mb-3"><?= h($member['email']) ?></small>

                <?php if (isAdmin()): ?>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="upload_photo">
                        <div class="mb-2">
                            <input type="file" class="form-control form-control-sm" name="photo" accept="image/*" required>
                            <div class="form-text">JPG, PNG, GIF, WebP — μέγιστο 5MB</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-upload me-1"></i>Ανέβασμα
                        </button>
                    </form>
                    <?php if ($photoExists): ?>
                        <form method="post" class="mt-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="hidden" name="delete_photo" value="1">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                    onclick="return confirm('Διαγραφή φωτογραφίας;')">
                                <i class="bi bi-trash me-1"></i>Διαγραφή
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Achievements -->
        <?php if (getSetting('achievements_enabled', '1') === '1'): ?>
        <div class="card vp-card border-accent-warning mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Επιτεύγματα</h5>
            </div>
            <div class="card-body">
                <?php if (empty($achievements)): ?>
                    <p class="text-muted">Δεν υπάρχουν επιτεύγματα.</p>
                <?php else: ?>
                    <?php foreach ($achievements as $ach): ?>
                        <div class="d-flex align-items-center mb-2">
                            <span class="fs-4 me-2"><?= h($ach['icon'] ?: '🏆') ?></span>
                            <div>
                                <strong><?= h($ach['name']) ?></strong>
                                <br><small class="text-muted"><?= formatDate($ach['earned_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Documents -->
        <?php
            $docCount   = count($documents);
            $docsPreview = array_slice($documents, 0, 5);
        ?>
        <div class="card vp-card border-accent-danger mb-4" id="documents">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-folder2-open text-danger me-2"></i>Αρχεία & Έγγραφα
                    <?php if ($docCount > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $docCount ?></span>
                    <?php endif; ?>
                </h5>
                <div class="d-flex gap-2">
                    <?php if ($docCount > 5): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#allDocsModal">
                        <i class="bi bi-list-ul me-1"></i>Όλα (<?= $docCount ?>)
                    </button>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                        <i class="bi bi-upload me-1"></i>Ανέβασμα
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($documents)): ?>
                    <p class="text-muted p-3 mb-0">Δεν υπάρχουν αρχεία.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($docsPreview as $doc): ?>
                            <?php
                                $isImage = str_starts_with($doc['mime_type'], 'image/');
                                $icon    = $isImage ? 'bi-file-image text-info' : 'bi-file-pdf text-danger';
                                if ($doc['mime_type'] === 'application/msword' || $doc['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                                    $icon = 'bi-file-word text-primary';
                                }
                                $sizeKb = round($doc['file_size'] / 1024);
                            ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-truncate me-2" style="max-width:220px">
                                        <i class="bi <?= $icon ?> me-1"></i>
                                        <a href="member-doc-download.php?id=<?= $doc['id'] ?>&member=<?= $id ?>" target="_blank" class="fw-semibold text-decoration-none">
                                            <?= h($doc['original_name']) ?>
                                        </a>
                                        <br>
                                        <small class="text-muted"><?= $sizeKb ?>KB &middot; <?= formatDate($doc['created_at']) ?></small>
                                    </div>
                                    <?php if (isAdmin()): ?>
                                    <form method="post" onsubmit="return confirm('Διαγραφή αρχείου;')" class="flex-shrink-0">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($docCount > 5): ?>
                    <div class="p-2 text-center border-top">
                        <button type="button" class="btn btn-sm btn-link text-secondary" data-bs-toggle="modal" data-bs-target="#allDocsModal">
                            <i class="bi bi-chevron-down me-1"></i>Εμφάνιση όλων των <?= $docCount ?> αρχείων
                        </button>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (getSetting('points_enabled', '1') === '1'): ?>
        <!-- Points History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-star me-1"></i>Ιστορικό Πόντων</h5>
            </div>
            <div class="card-body">
                <?php if (empty($pointsHistory)): ?>
                    <p class="text-muted">Δεν υπάρχουν πόντοι.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($pointsHistory as $ph): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <strong class="text-success">+<?= $ph['points'] ?> πόντοι</strong>
                                <br><small class="text-muted">
                                    <?= h($ph['shift_title'] ?: $ph['description']) ?>
                                    <br><?= formatDate($ph['created_at']) ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; // points_enabled ?>
    </div>
</div>

<!-- Upload Document Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Ανέβασμα Αρχείων</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="upload_document">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Αρχεία <span class="text-danger">*</span></label>
                        <input type="file" name="doc_files[]" id="docFilesInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" multiple required>
                        <div class="form-text">Επιτρεπτοί τύποι: PDF, JPG, PNG, DOC, DOCX. Μέγιστο μέγεθος ανά αρχείο: 15MB. Μπορείτε να επιλέξετε πολλά αρχεία ταυτόχρονα.</div>
                    </div>
                    <div id="selectedFilesList" class="d-none">
                        <label class="form-label fw-semibold text-muted small">Επιλεγμένα αρχεία:</label>
                        <ul class="list-unstyled small mb-0" id="fileNamesUl"></ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Ανέβασμα</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- All Documents Modal -->
<?php if (!empty($documents)): ?>
<div class="modal fade" id="allDocsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder2-open me-2"></i>Όλα τα Αρχεία <span class="badge bg-secondary"><?= $docCount ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="max-height: 60vh; overflow-y: auto;">
                <!-- Search -->
                <div class="p-3 border-bottom bg-light">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="docSearchInput" class="form-control" placeholder="Αναζήτηση αρχείου..." autocomplete="off">
                        <span class="input-group-text text-muted" id="docSearchCount"><?= $docCount ?> αρχεία</span>
                    </div>
                </div>
                <!-- File table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="allDocsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40%">Αρχείο</th>
                                <th>Μέγεθος</th>
                                <th>Ημερομηνία</th>
                                <th>Ανέβηκε από</th>
                                <?php if (isAdmin()): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                                $isImage = str_starts_with($doc['mime_type'], 'image/');
                                $icon    = $isImage ? 'bi-file-image text-info' : 'bi-file-pdf text-danger';
                                if ($doc['mime_type'] === 'application/msword' || $doc['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                                    $icon = 'bi-file-word text-primary';
                                }
                                $sizeKb = round($doc['file_size'] / 1024);
                            ?>
                            <tr class="doc-row">
                                <td>
                                    <i class="bi <?= $icon ?> me-1"></i>
                                    <a href="member-doc-download.php?id=<?= $doc['id'] ?>&member=<?= $id ?>" target="_blank" class="fw-semibold text-decoration-none doc-name">
                                        <?= h($doc['original_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small align-middle"><?= $sizeKb ?>KB</td>
                                <td class="text-muted small align-middle"><?= formatDate($doc['created_at']) ?></td>
                                <td class="text-muted small align-middle"><?= h($doc['uploader_name']) ?></td>
                                <?php if (isAdmin()): ?>
                                <td class="align-middle">
                                    <form method="post" onsubmit="return confirm('Διαγραφή αρχείου;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noDocsFound" class="d-none text-center text-muted py-4"><i class="bi bi-search me-1"></i>Δεν βρέθηκαν αρχεία.</div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted">Σύνολο: <?= $docCount ?> αρχεία</small>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-sm btn-success" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                    <i class="bi bi-upload me-1"></i>Ανέβασμα νέων
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Personal Data Modal -->
<div class="modal fade" id="deleteDataModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-shield-x me-2"></i>Διαγραφή Προσωπικών Δεδομένων (GDPR)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Προειδοποίηση:</strong> Αυτή η ενέργεια είναι μόνιμη και δεν μπορεί να αναιρεθεί!
                </div>
                <p><strong>Θα διαγραφούν τα ακόλουθα δεδομένα:</strong></p>
                <ul>
                    <li>Όνομα και στοιχεία επικοινωνίας (θα αντικατασταθούν με "[Διαγραμμένος Χρήστης]")</li>
                    <li>Προφίλ μέλους (βιογραφικό, διεύθυνση, επαφή έκτακτης ανάγκης)</li>
                    <li>Επιτεύγματα</li>
                    <li>Ειδοποιήσεις</li>
                </ul>
                <p class="text-muted"><small><i class="bi bi-info-circle me-1"></i>Οι συμμετοχές σε αποστολές και Κύκλους Εγγραφών θα διατηρηθούν για στατιστικούς λόγους, αλλά θα συνδεθούν με ανωνυμοποιημένο χρήστη.</small></p>
                <p class="mb-0">Είστε σίγουροι ότι θέλετε να διαγράψετε τα προσωπικά δεδομένα του <strong><?= h($member['name']) ?></strong>;</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Ακύρωση
                </button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_personal_data">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-shield-x me-1"></i>Οριστική Διαγραφή Δεδομένων
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (isAdmin()): ?>
<!-- Member Since Date Edit Modal -->
<div class="modal fade" id="memberSinceModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_member_since">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-calendar3 me-2"></i>Ημερομηνία Μέλους</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Μέλος από</label>
                    <input type="date" class="form-control" name="member_since_date" value="<?= date('Y-m-d', strtotime($member['created_at'])) ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // --- Upload preview ---
    const uploadInput = document.getElementById('docFilesInput');
    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            const list = document.getElementById('selectedFilesList');
            const ul   = document.getElementById('fileNamesUl');
            ul.innerHTML = '';
            if (this.files.length > 0) {
                Array.from(this.files).forEach(function (f) {
                    const li = document.createElement('li');
                    li.innerHTML = '<i class="bi bi-file-earmark me-1"></i>' + f.name +
                        ' <span class="text-muted">(' + (f.size > 1048576
                            ? (f.size / 1048576).toFixed(1) + ' MB'
                            : Math.round(f.size / 1024) + ' KB') + ')</span>';
                    ul.appendChild(li);
                });
                list.classList.remove('d-none');
            } else {
                list.classList.add('d-none');
            }
        });
    }

    // --- All-docs modal live search ---
    const searchInput = document.getElementById('docSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q       = this.value.trim().toLowerCase();
            const rows    = document.querySelectorAll('#allDocsTable .doc-row');
            const counter = document.getElementById('docSearchCount');
            const noFound = document.getElementById('noDocsFound');
            let visible   = 0;
            rows.forEach(function (row) {
                const name = row.querySelector('.doc-name').textContent.trim().toLowerCase();
                if (!q || name.includes(q)) {
                    row.classList.remove('d-none');
                    visible++;
                } else {
                    row.classList.add('d-none');
                }
            });
            counter.textContent = visible + ' αρχεία';
            noFound.classList.toggle('d-none', visible > 0);
            document.querySelector('#allDocsTable').classList.toggle('d-none', visible === 0);
        });

        // Reset search when modal closes
        const allDocsModal = document.getElementById('allDocsModal');
        if (allDocsModal) {
            allDocsModal.addEventListener('hidden.bs.modal', function () {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            });
        }
    }

});
</script>
