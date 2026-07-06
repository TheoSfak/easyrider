<?php
/**
 * VolunteerOps - Simple Web-Based Deployment Assistant
 * Access via: https://yphresies.gr/volunteerops/deploy-assistant.php
 */

// Security check
$DEPLOY_PASSWORD = 'yphresies2026!CHANGE_THIS'; // CHANGE THIS PASSWORD!

session_start();

if (isset($_POST['password'])) {
    if ($_POST['password'] === $DEPLOY_PASSWORD) {
        $_SESSION['deploy_auth'] = true;
    } else {
        $error = 'Λάθος κωδικός!';
    }
}

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$isAuth = $_SESSION['deploy_auth'] ?? false;

if (!$isAuth) {
    ?>
    <!DOCTYPE html>
    <html lang="el">
    <head>
        <meta charset="UTF-8">
        <title>Deployment Assistant - Authentication</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; background: #f5f5f5; }
            .login-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #667eea; margin-bottom: 20px; }
            input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
            button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
            button:hover { background: #5568d3; }
            .error { color: #ef4444; margin: 10px 0; }
            .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 5px; margin-bottom: 20px; color: #856404; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔐 Deployment Assistant</h1>
            <div class="warning">
                <strong>⚠️ Προσοχή:</strong> Διαγράψτε αυτό το αρχείο μετά την εγκατάσταση!
            </div>
            <?php if (isset($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="password" placeholder="Κωδικός Deployment" required autofocus>
                <button type="submit">Είσοδος</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Authenticated - Show assistant

$step = $_GET['step'] ?? 'check';
$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'run_indexes':
            try {
                require_once __DIR__ . '/bootstrap.php';
                $sql = file_get_contents(__DIR__ . '/sql/add_indexes.sql');
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                $success = 0;
                foreach ($statements as $statement) {
                    if (empty($statement) || strpos($statement, '--') === 0) continue;
                    try {
                        db()->exec($statement);
                        $success++;
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                            throw $e;
                        }
                    }
                }
                $message = "Επιτυχία! Δημιουργήθηκαν $success indexes.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Σφάλμα: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'cleanup':
            $files = ['install.php', 'add_indexes.php', 'deploy-assistant.php', 'test_full.php', 'test_app.php'];
            $cleaned = 0;
            foreach ($files as $file) {
                if (file_exists(__DIR__ . '/' . $file)) {
                    unlink(__DIR__ . '/' . $file);
                    $cleaned++;
                }
            }
            if (is_dir(__DIR__ . '/sql')) {
                array_map('unlink', glob(__DIR__ . '/sql/*.sql'));
                rmdir(__DIR__ . '/sql');
            }
            $message = "Καθαρισμός ολοκληρώθηκε! Διαγράφηκαν $cleaned αρχεία.";
            $messageType = 'success';
            break;
    }
}

// System checks
function checkRequirement($name, $check, $hint = '') {
    $status = $check ? '✅' : '❌';
    $color = $check ? '#10b981' : '#ef4444';
    echo "<div style='padding: 10px; margin: 5px 0; background: " . ($check ? '#d1fae5' : '#fee2e2') . "; border-left: 4px solid $color; border-radius: 5px;'>";
    echo "<strong>$status $name</strong>";
    if (!$check && $hint) echo "<br><small style='color: #666;'>$hint</small>";
    echo "</div>";
}

?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Assistant - VolunteerOps</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 900px; margin: 20px auto; padding: 20px; background: #f5f7fa; }
        .container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #667eea; margin-bottom: 10px; }
        h2 { color: #444; margin-top: 30px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .nav { display: flex; gap: 10px; margin: 20px 0; }
        .nav a { padding: 10px 20px; background: #e2e8f0; border-radius: 5px; text-decoration: none; color: #334155; }
        .nav a.active { background: #667eea; color: white; }
        .message { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .message.success { background: #d1fae5; border: 1px solid #10b981; color: #065f46; }
        .message.error { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; }
        .message.warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .message.info { background: #dbeafe; border: 1px solid #3b82f6; color: #1e40af; }
        button { padding: 12px 24px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 5px; }
        button:hover { background: #5568d3; }
        button.danger { background: #ef4444; }
        button.danger:hover { background: #dc2626; }
        button.success { background: #10b981; }
        button.success:hover { background: #059669; }
        .code { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 5px; overflow-x: auto; margin: 10px 0; }
        .header-actions { float: right; }
        .step-indicator { display: flex; gap: 10px; margin: 20px 0; }
        .step { flex: 1; padding: 10px; text-align: center; background: #e2e8f0; border-radius: 5px; }
        .step.active { background: #667eea; color: white; font-weight: bold; }
        .step.complete { background: #10b981; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-actions">
            <form method="post" style="display: inline;">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="danger">Αποσύνδεση</button>
            </form>
        </div>
        
        <h1>🚀 Deployment Assistant</h1>
        <p style="color: #666;">VolunteerOps v2.0 - Οδηγός Εγκατάστασης</p>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="nav">
            <a href="?step=check" class="<?= $step === 'check' ? 'active' : '' ?>">📋 Έλεγχος</a>
            <a href="?step=database" class="<?= $step === 'database' ? 'active' : '' ?>">🗄️ Database</a>
            <a href="?step=cron" class="<?= $step === 'cron' ? 'active' : '' ?>">⏰ Cron Jobs</a>
            <a href="?step=cleanup" class="<?= $step === 'cleanup' ? 'active' : '' ?>">🧹 Καθαρισμός</a>
        </div>
        
        <?php if ($step === 'check'): ?>
            <h2>Έλεγχος Συστήματος</h2>
            <?php
            checkRequirement('PHP Version ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>='), 'Απαιτείται PHP 8.0+');
            checkRequirement('PDO Extension', extension_loaded('pdo'));
            checkRequirement('PDO MySQL', extension_loaded('pdo_mysql'));
            checkRequirement('mbstring Extension', extension_loaded('mbstring'));
            checkRequirement('JSON Extension', extension_loaded('json'));
            checkRequirement('Session Support', function_exists('session_start'));
            
            $uploadsWritable = is_writable(__DIR__ . '/uploads');
            checkRequirement('Uploads Directory Writable', $uploadsWritable, 'chmod 777 uploads');
            
            $backupsWritable = is_writable(__DIR__ . '/backups');
            checkRequirement('Backups Directory Writable', $backupsWritable, 'chmod 777 backups');
            
            $hasConfig = file_exists(__DIR__ . '/config.local.php');
            checkRequirement('Configuration File Exists', $hasConfig, 'Τρέξτε install.php πρώτα');
            ?>
            
        <?php elseif ($step === 'database'): ?>
            <h2>Database Optimization</h2>
            <p>Προσθήκη indexes για βελτιστοποίηση απόδοσης (20+ indexes).</p>
            
            <div class="message warning">
                <strong>⚠️ Προσοχή:</strong> Εκτελέστε αυτό μόνο μία φορά μετά την εγκατάσταση!
            </div>
            
            <form method="post">
                <input type="hidden" name="action" value="run_indexes">
                <button type="submit" class="success">▶️ Εκτέλεση Indexes</button>
            </form>
            
            <h3 style="margin-top: 30px;">Χειροκίνητη Εκτέλεση</h3>
            <p>Εναλλακτικά, εκτελέστε στο phpMyAdmin:</p>
            <div class="code">
                <pre>-- Ανοίξτε το αρχείο sql/add_indexes.sql
-- Αντιγράψτε και εκτελέστε το περιεχόμενο</pre>
            </div>
            
        <?php elseif ($step === 'cron'): ?>
            <h2>Cron Jobs Configuration</h2>
            <p>Προσθέστε αυτά τα cron jobs στο cPanel για αυτοματοποιημένες ειδοποιήσεις.</p>
            
            <h3>Επιλογή 1: Ενιαίο Cron Job (Προτεινόμενο)</h3>
            <div class="code">
0 8 * * * /usr/bin/php <?= __DIR__ ?>/cron_daily.php
            </div>
            <p><small>Εκτελεί όλες τις ειδοποιήσεις καθημερινά στις 08:00</small></p>
            
            <h3>Επιλογή 2: Ξεχωριστά Cron Jobs</h3>
            
            <strong>Task Reminders (Κάθε 6 ώρες):</strong>
            <div class="code">
0 */6 * * * /usr/bin/php <?= __DIR__ ?>/cron_task_reminders.php
            </div>
            
            <strong>Shift Reminders (Καθημερινά 08:00):</strong>
            <div class="code">
0 8 * * * /usr/bin/php <?= __DIR__ ?>/cron_shift_reminders.php
            </div>
            
            <strong>Incomplete Missions (Καθημερινά 09:00):</strong>
            <div class="code">
0 9 * * * /usr/bin/php <?= __DIR__ ?>/cron_incomplete_missions.php
            </div>
            
            <div class="message info" style="margin-top: 20px;">
                <strong>💡 Συμβουλή:</strong> Μπορείτε να δοκιμάσετε τα cron jobs χειροκίνητα:<br>
                <code>php <?= __DIR__ ?>/cron_daily.php</code>
            </div>
            
        <?php elseif ($step === 'cleanup'): ?>
            <h2>Καθαρισμός Αρχείων Εγκατάστασης</h2>
            
            <div class="message warning">
                <strong>⚠️ ΣΗΜΑΝΤΙΚΟ:</strong> Αυτή η ενέργεια θα διαγράψει:
                <ul>
                    <li>install.php</li>
                    <li>deploy-assistant.php (αυτό το αρχείο)</li>
                    <li>add_indexes.php</li>
                    <li>test_*.php (δοκιμαστικά αρχεία)</li>
                    <li>sql/ (φάκελος με scripts)</li>
                </ul>
            </div>
            
            <form method="post" onsubmit="return confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε τα αρχεία εγκατάστασης;');">
                <input type="hidden" name="action" value="cleanup">
                <button type="submit" class="danger">🗑️ Διαγραφή Αρχείων Εγκατάστασης</button>
            </form>
            
            <div class="message info" style="margin-top: 30px;">
                <strong>✅ Μετά τον καθαρισμό:</strong>
                <ol>
                    <li>Το σύστημα είναι έτοιμο για χρήση</li>
                    <li>Συνδεθείτε στο <a href="index.php">dashboard</a></li>
                    <li>Ρυθμίστε SMTP για emails</li>
                    <li>Προσθέστε Departments & Users</li>
                    <li>Δημιουργήστε την πρώτη αποστολή</li>
                </ol>
            </div>
        <?php endif; ?>
        
        <hr style="margin: 40px 0; border: none; border-top: 1px solid #e2e8f0;">
        
        <div style="text-align: center; color: #666;">
            <p><strong>VolunteerOps v2.0</strong> - Performance Optimized</p>
            <p><small>GitHub: <a href="https://github.com/TheoSfak/member-ops" target="_blank">TheoSfak/member-ops</a></small></p>
        </div>
    </div>
</body>
</html>
