<?php
/**
 * EasyRide - Header Template
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Get app settings for header
$appName = getSetting('app_name', 'EasyRide');
$appLogo = getSetting('app_logo', '');
$appDescription = getSetting('app_description', '');
$showTrainingNav = getSetting('training_nav_enabled', '0') === '1';
$showInventoryNav = getSetting('inventory_nav_enabled', '0') === '1';
$showGamificationNav = getSetting('gamification_nav_enabled', '0') === '1';

// Unread notifications count + latest 5 for bell dropdown
$unreadNotifCount = 0;
$latestNotifications = [];
if (isLoggedIn()) {
    $uid = getCurrentUserId();
    $unreadNotifCount = (int) dbFetchValue(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL", [$uid]
    );
    $latestNotifications = dbFetchAll(
        "SELECT id, type, title, message, created_at, read_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5", [$uid]
    );
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (isLoggedIn()): ?>
    <meta name="csrf-token" content="<?= h(csrfToken()) ?>">
    <?php endif; ?>
    <title><?= h($pageTitle ?? $appName) ?> - <?= h($appName) ?></title>
    
    <!-- PWA -->
    <link rel="manifest" href="<?= rtrim(BASE_URL, '/') ?>/manifest.json">
    <meta name="theme-color" content="#1e3c72">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h($appName) ?>">
    <link rel="apple-touch-icon" href="<?= rtrim(BASE_URL, '/') ?>/assets/icons/icon-192.png">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr for date/time pickers -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- EasyRide theme -->
    <link href="<?= rtrim(BASE_URL, '/') ?>/assets/css/theme.css?v=<?= APP_VERSION ?>" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Sortable.js for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>
</head>
<body>
<?php if (isPreviewMode()):
    $__pr = getPreviewRole();
    $__prName  = $__pr ? h($__pr['name'])  : 'Άγνωστος Ρόλος';
    $__prColor = $__pr ? h($__pr['color']) : '#6c757d';
?>
<div id="previewBanner" style="position:fixed;top:0;left:0;right:0;z-index:10000;background:#fff3cd;border-bottom:2px solid #ffc107;padding:8px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 6px rgba(0,0,0,.1);">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-eye-fill text-warning fs-5"></i>
        <strong>Προεπισκόπηση Ρόλου:</strong>
        <span class="badge px-2 py-1" style="background-color:<?= $__prColor ?>;color:#fff;font-size:.85rem;"><?= $__prName ?></span>
        <small class="text-muted d-none d-sm-inline">— Όλες οι αλλαγές είναι αποκλεισμένες</small>
    </div>
    <a href="?exit_preview=1" class="btn btn-warning btn-sm ms-3 flex-shrink-0">
        <i class="bi bi-x-circle me-1"></i>Έξοδος Προεπισκόπησης
    </a>
</div>
<div id="previewSpacer" style="height:48px"></div>
<script>
(function(){
    var b=document.getElementById('previewBanner');
    var s=document.getElementById('previewSpacer');
    if(!b)return;
    var h=b.offsetHeight;
    if(s)s.style.height=h+'px';
    var sidebar=document.querySelector('.sidebar');
    if(sidebar)sidebar.style.marginTop=h+'px';
    var topnav=document.querySelector('.top-navbar');
    if(topnav){topnav.style.position='sticky';topnav.style.top='0';}
})();
</script>
<?php unset($__pr,$__prName,$__prColor); endif; ?>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="sidebar-brand">
            <i class="bi bi-signpost-split"></i>
            <div style="flex: 1;">
                <div style="font-size: 1.4rem; font-weight: 700;"><?= h($appName) ?></div>
                <?php if (!empty($appDescription)): ?>
                    <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.85; margin-top: 0.25rem; line-height: 1.3;"><?= h($appDescription) ?></div>
                <?php endif; ?>
            </div>
        </a>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i> Πίνακας Ελέγχου
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'notifications' ? 'active' : '' ?>" href="notifications.php">
                    <i class="bi bi-bell"></i> Ειδοποιήσεις
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?= $unreadNotifCount > 99 ? '99+' : $unreadNotifCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if (isSystemAdmin() || hasPagePermission('ops_dashboard')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'ops-dashboard' ? 'active' : '' ?>" href="ops-dashboard.php">
                    <i class="bi bi-broadcast text-danger"></i> Επιχειρησιακό
                </a>
            </li>
            <?php endif; ?>
            
            <div class="sidebar-section">Δράσεις</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'shift-calendar' ? 'active' : '' ?>" href="shift-calendar.php">
                    <i class="bi bi-calendar3"></i> Ημερολόγιο Δράσεων
                </a>
            </li>
            <?php if (isSystemAdmin() || hasPagePermission('missions_view') || hasPagePermission('missions_manage')): ?>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status') === 'DRAFT') ? 'active' : '' ?>" href="missions.php?status=DRAFT">
                    <i class="bi bi-file-earmark-text text-secondary"></i> Πρόχειρες Δράσεις
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status', 'OPEN') === 'OPEN') ? 'active' : '' ?>" href="missions.php?status=OPEN">
                    <i class="bi bi-flag-fill text-success"></i> Ενεργές Δράσεις
                </a>
            </li>
            <?php if (isSystemAdmin() || hasPagePermission('missions_view') || hasPagePermission('missions_manage')): ?>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status') === 'CLOSED') ? 'active' : '' ?>" href="missions.php?status=CLOSED">
                    <i class="bi bi-flag text-warning"></i> Κλειστές Δράσεις
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status') === 'COMPLETED') ? 'active' : '' ?>" href="missions.php?status=COMPLETED">
                    <i class="bi bi-flag-fill text-primary"></i> Ολοκληρωμένες Δράσεις
                </a>
            </li>
            <?php endif; ?>
            
            <div class="sidebar-section">Διαχείριση</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'tasks' ? 'active' : '' ?>" href="tasks.php">
                    <i class="bi bi-list-task"></i> Εργασίες
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'participations' ? 'active' : '' ?>" href="participations.php">
                    <i class="bi bi-person-check"></i> Συμμετοχές
                </a>
            </li>
            
            <?php if ($showTrainingNav): ?>
            <div class="sidebar-section">Εκπαίδευση</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training' ? 'active' : '' ?>" href="training.php">
                    <i class="bi bi-book"></i> Μαθήματα
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-materials' ? 'active' : '' ?>" href="training-materials.php">
                    <i class="bi bi-file-earmark-pdf"></i> Εκπαιδευτικό Υλικό
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-quizzes' ? 'active' : '' ?>" href="training-quizzes.php">
                    <i class="bi bi-puzzle"></i> Κουίζ
                </a>
            </li>
            <?php if (isAdmin() || isTraineeRescuer()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-exams' ? 'active' : '' ?>" href="training-exams.php">
                    <i class="bi bi-award"></i> Διαγωνίσματα
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isSystemAdmin()): ?>
            <div class="sidebar-section">Διαχείριση Εκπαίδευσης</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-admin' ? 'active' : '' ?>" href="training-admin.php">
                    <i class="bi bi-gear"></i> Κατηγορίες & Υλικό
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-admin' ? 'active' : '' ?>" href="exam-admin.php">
                    <i class="bi bi-file-earmark-text"></i> Διαγωνίσματα & Κουίζ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-form' ? 'active' : '' ?>" href="exam-form.php">
                    <i class="bi bi-plus-circle"></i> Νέο Διαγώνισμα
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'quiz-form' ? 'active' : '' ?>" href="quiz-form.php">
                    <i class="bi bi-plus-circle"></i> Νέο Κουίζ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-statistics' ? 'active' : '' ?>" href="exam-statistics.php">
                    <i class="bi bi-bar-chart-line"></i> Στατιστικά Εξετάσεων
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['questions-pool', 'exam-questions-admin', 'quiz-questions-admin']) ? 'active' : '' ?>" href="questions-pool.php">
                    <i class="bi bi-question-circle"></i> Διαχείριση Ερωτήσεων
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if (isSystemAdmin() || hasPagePermission('members_manage') || hasPagePermission('members_view') || hasPagePermission('inactive_members') || hasPagePermission('positions_manage') || hasPagePermission('complaints_view') || hasPagePermission('complaints_manage')): ?>
            <div class="sidebar-section">Διοίκηση</div>
            
            <?php if (isSystemAdmin() || hasPagePermission('members_manage') || hasPagePermission('members_view')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'members' ? 'active' : '' ?>" href="members.php">
                    <i class="bi bi-people"></i> Μέλη
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin() || hasPagePermission('inactive_members')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inactive-members' ? 'active' : '' ?>" href="inactive-members.php">
                    <i class="bi bi-person-x"></i> Ανενεργά Μέλη
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin() || hasPagePermission('positions_manage')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'member-positions' ? 'active' : '' ?>" href="member-positions.php">
                    <i class="bi bi-person-badge"></i> Ρόλοι Μελών
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'mission-types' ? 'active' : '' ?>" href="mission-types.php">
                    <i class="bi bi-tags"></i> Τύποι Δράσεων
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin() || hasPagePermission('complaints_view') || hasPagePermission('complaints_manage')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'complaints' ? 'active' : '' ?>" href="complaints.php">
                    <i class="bi bi-chat-left-dots"></i> Παράπονα
                </a>
            </li>
            <?php endif; ?>
            <?php endif; // Διοίκηση section ?>
            
            <?php if ($showInventoryNav && !isTraineeRescuer()): ?>
            <div class="sidebar-section">Απόθεμα</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory' ? 'active' : '' ?>" href="inventory.php">
                    <i class="bi bi-box-seam"></i> Υλικά
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-kits' ? 'active' : '' ?>" href="inventory-kits.php">
                    <i class="bi bi-briefcase"></i> Σετ Εξοπλισμού
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-book' ? 'active' : '' ?>" href="inventory-book.php">
                    <i class="bi bi-upc-scan"></i> Χρέωση / Επιστροφή
                </a>
            </li>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-shelf' ? 'active' : '' ?>" href="inventory-shelf.php">
                    <i class="bi bi-grid-3x3"></i> Υλικά Ραφιού
                </a>
            </li>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-notes' ? 'active' : '' ?>" href="inventory-notes.php">
                    <i class="bi bi-sticky"></i> Σημειώσεις Υλικών
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-categories' ? 'active' : '' ?>" href="inventory-categories.php">
                    <i class="bi bi-tags"></i> Κατηγορίες Υλικών
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-locations' ? 'active' : '' ?>" href="inventory-locations.php">
                    <i class="bi bi-geo-alt"></i> Τοποθεσίες
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-warehouses' ? 'active' : '' ?>" href="inventory-warehouses.php">
                    <i class="bi bi-building"></i> Αποθήκες
                </a>
            </li>
            <?php endif; ?>
            <?php endif; // !isTraineeRescuer ?>
            
            <?php if ($showGamificationNav): ?>
            <div class="sidebar-section">Gamification</div>
            
            <?php if (getSetting('points_enabled', '1') === '1'): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'leaderboard' ? 'active' : '' ?>" href="leaderboard.php">
                    <i class="bi bi-trophy"></i> Κατάταξη
                </a>
            </li>
            <?php endif; ?>
            <?php if (getSetting('achievements_enabled', '1') === '1'): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'achievements' ? 'active' : '' ?>" href="achievements.php">
                    <i class="bi bi-award"></i> Επιτεύγματα
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            
            <div class="sidebar-section">Δρόμος</div>

            <li class="nav-item">
                <a class="nav-link" href="downloads/easyride-ridemode.apk" download>
                    <i class="bi bi-phone"></i> Εφαρμογή Android
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['partners', 'partner-form']) ? 'active' : '' ?>" href="partners.php">
                    <i class="bi bi-tools"></i> Συνεργάτες
                </a>
            </li>
            
            <?php if (isSystemAdmin() || hasPagePermission('citizens_view') || hasPagePermission('citizens_manage')): ?>
            <div class="sidebar-section">Συνδρομές</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'citizens' ? 'active' : '' ?>" href="citizens.php">
                    <i class="bi bi-person-vcard"></i> Υποψήφια Μέλη
                </a>
            </li>
            <?php if (isSystemAdmin() || hasPagePermission('citizens_manage')): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'citizen-certificates' ? 'active' : '' ?>" href="citizen-certificates.php">
                    <i class="bi bi-file-earmark-medical"></i> Συνδρομές
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'citizen-certificate-types' ? 'active' : '' ?>" href="citizen-certificate-types.php">
                    <i class="bi bi-tags"></i> Τύποι Συνδρομών
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (isSystemAdmin()): ?>
            <div class="sidebar-section">Επικοινωνία</div>
            
            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['newsletters', 'newsletter-form', 'newsletter-view', 'newsletter-log', 'newsletter-templates', 'newsletter-template-form', 'newsletter-presets', 'newsletter-preset-form']) ? 'active' : '' ?>" href="newsletters.php">
                    <i class="bi bi-envelope-paper"></i> Ενημερωτικά
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['newsletter-presets', 'newsletter-preset-form']) ? 'active' : '' ?>" href="newsletter-presets.php">
                    <i class="bi bi-file-earmark-text"></i> Πρότυπα Περιεχομένου
                </a>
            </li>
            
            <div class="sidebar-section">Σύστημα</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'roles' ? 'active' : '' ?>" href="roles.php">
                    <i class="bi bi-shield-lock"></i> Διαχείριση Ρόλων
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'audit' ? 'active' : '' ?>" href="audit.php">
                    <i class="bi bi-journal-text"></i> Αρχείο Καταγραφής
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'email-logs' ? 'active' : '' ?>" href="email-logs.php">
                    <i class="bi bi-envelope-check"></i> Email Logs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" href="settings.php">
                    <i class="bi bi-gear"></i> Ρυθμίσεις
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <nav class="top-navbar d-flex align-items-center">
            <button class="btn btn-link sidebar-toggle p-0 me-2" onclick="toggleSidebar()">
                <i class="bi bi-list fs-4"></i>
            </button>
            <a href="dashboard.php" class="d-lg-none d-flex align-items-center text-decoration-none me-auto" style="gap:0.4rem;min-width:0;">
                <?php if (!empty($appLogo) && file_exists(__DIR__ . '/../uploads/logos/' . $appLogo)): ?>
                    <img src="uploads/logos/<?= h($appLogo) ?>" alt="" style="height:28px;width:auto;border-radius:6px;flex-shrink:0;">
                <?php else: ?>
                    <i class="bi bi-heart-pulse flex-shrink-0" style="font-size:1.3rem;color:var(--accent-color);"></i>
                <?php endif; ?>
                <span class="fw-bold text-truncate" style="font-size:0.95rem;color:#1e293b;"><?= h($appName) ?></span>
            </a>
            <div class="d-none d-lg-block me-auto"></div>
            
            <div class="d-flex align-items-center flex-shrink-0">
                <!-- Notification Bell -->
                <div class="dropdown me-3">
                    <a class="btn btn-link text-dark position-relative p-1" href="notifications.php" id="notifBell"
                       data-bs-toggle="dropdown" aria-expanded="false" title="Ειδοποιήσεις">
                        <i class="bi bi-bell fs-5"></i>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge" style="font-size:0.65rem;">
                                <?= $unreadNotifCount > 99 ? '99+' : $unreadNotifCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg" style="width:min(360px, 95vw); max-height:420px; overflow-y:auto; right:0; left:auto;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <h6 class="mb-0"><i class="bi bi-bell me-1"></i>Ειδοποιήσεις</h6>
                            <?php if ($unreadNotifCount > 0): ?>
                                <a href="notifications.php?action=mark_all_read" class="text-decoration-none small">Ανάγνωση όλων</a>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($latestNotifications)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>
                                Δεν υπάρχουν ειδοποιήσεις
                            </div>
                        <?php else: ?>
                            <?php foreach ($latestNotifications as $notif): ?>
                                <a href="notifications.php?mark=<?= $notif['id'] ?>" class="dropdown-item py-2 px-3 border-bottom <?= empty($notif['read_at']) ? 'bg-light' : '' ?>" style="white-space:normal;">
                                    <div class="d-flex align-items-start">
                                        <div class="me-2 mt-1">
                                            <?php
                                            $iconClass = match($notif['type'] ?? 'info') {
                                                'success' => 'bi-check-circle-fill text-success',
                                                'warning' => 'bi-exclamation-triangle-fill text-warning',
                                                'danger', 'error' => 'bi-x-circle-fill text-danger',
                                                default => 'bi-info-circle-fill text-primary',
                                            };
                                            ?>
                                            <i class="bi <?= $iconClass ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small"><?= h($notif['title']) ?></div>
                                            <div class="text-muted small text-truncate" style="max-width:280px;"><?= h($notif['message']) ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;"><?= formatDateTime($notif['created_at']) ?></div>
                                        </div>
                                        <?php if (empty($notif['read_at'])): ?>
                                            <span class="ms-1 mt-1"><i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            <div class="text-center py-2">
                                <a href="notifications.php" class="text-decoration-none small">Προβολή όλων</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-link text-dark dropdown-toggle text-decoration-none" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($currentUser['profile_photo']) && file_exists(__DIR__ . '/../uploads/avatars/' . $currentUser['profile_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($currentUser['profile_photo']) ?>" class="rounded-circle me-1 flex-shrink-0" style="width:30px;height:30px;object-fit:cover;" alt="">
                        <?php else: ?>
                            <i class="bi bi-person-circle me-1 flex-shrink-0"></i>
                        <?php endif; ?>
                        <span class="user-name-text d-none d-sm-inline"><?= h($currentUser['name'] ?? 'Χρήστης') ?></span>
                        <span class="user-type-badge d-none d-md-inline"><?= memberTypeBadge($currentUser['member_type'] ?? VTYPE_RESCUER) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown" style="right: 0; left: auto;">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Το Προφίλ μου</a></li>
                        <li><a class="dropdown-item" href="my-participations.php"><i class="bi bi-list-check me-2"></i>Οι Αιτήσεις μου</a></li>
                        <?php if (!empty($currentUser['custom_role_id'])): ?>
                        <li><a class="dropdown-item" href="my-permissions.php"><i class="bi bi-shield-check me-2"></i>Τα Δικαιώματά μου</a></li>
                        <?php endif; ?>
                        <?php if (getSetting('points_enabled', '1') === '1'): ?>
                        <li><a class="dropdown-item" href="my-points.php"><i class="bi bi-star me-2"></i>Οι Πόντοι μου</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="notification-preferences.php"><i class="bi bi-bell me-2"></i>Ρυθμίσεις Ειδοποιήσεων</a></li>
                        <li><a class="dropdown-item" href="complaint-form.php"><i class="bi bi-exclamation-triangle me-2"></i>Αναφορά Παραπόνου</a></li>
                        <li><a class="dropdown-item" href="my-complaints.php"><i class="bi bi-chat-left-dots me-2"></i>Τα Παράπονά μου</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="logout.php" method="post" class="d-inline">
                                <?= csrfField() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Αποσύνδεση
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Page Content -->
        <div class="content-wrapper">
            <?php if (!empty($appLogo) && file_exists(__DIR__ . '/../uploads/logos/' . $appLogo)): ?>
                <div class="text-center mb-4" style="padding: 2rem 0;">
                    <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($appName) ?>" style="max-width: 200px; max-height: 150px; width: auto; height: auto; border-radius: 12px; box-shadow: 0 8px 16px rgba(0,0,0,0.15);">
                </div>
            <?php endif; ?>
            <?php displayFlash(); ?>
