<?php
require_once __DIR__ . '/../../bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

// Restore notification settings with proper Greek
dbExecute("DELETE FROM notification_settings");

$notifications = [
    [9, 'welcome', 'Καλωσόρισμα', 'Όταν γίνεται νέος χρήστης', 1],
    [10, 'new_mission', 'Νέα Αποστολή', 'Όταν δημιουργείται νέα αποστολή', 1],
    [11, 'participation_approved', 'Έγκριση Συμμετοχής', 'Όταν εγκρίνεται η αίτηση συμμετοχής σε Κύκλο Εγγραφών', 1],
    [12, 'participation_rejected', 'Απόρριψη Συμμετοχής', 'Όταν απορρίπτεται η αίτηση συμμετοχής', 1],
    [13, 'shift_reminder', 'Υπενθύμιση Κύκλου Εγγραφών', 'Μία μέρα πριν τον Κύκλο Εγγραφών', 1],
    [14, 'mission_canceled', 'Ακύρωση Αποστολής', 'Όταν ακυρώνεται αποστολή', 1],
    [15, 'shift_canceled', 'Ακύρωση Κύκλου Εγγραφών', 'Όταν ακυρώνεται Κύκλος Εγγραφών', 1],
    [16, 'points_earned', 'Πόντοι Βαθμολογίας', 'Όταν το μέλος κερδίζει πόντους', 0],
    [17, 'task_assigned', 'Ανάθεση Εργασίας', 'Όταν ανατίθεται μια εργασία σε μέλος', 1],
    [18, 'task_comment', 'Σχόλιο σε Εργασία', 'Όταν προστίθεται σχόλιο σε εργασία', 1],
    [19, 'task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Όταν πλησιάζει η προθεσμία εργασίας (24h πριν)', 1],
    [20, 'task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Όταν αλλάζει η κατάσταση μιας εργασίας', 1],
    [21, 'task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Όταν ολοκληρώνεται μια υποεργασία', 1],
    [27, 'mission_needs_members', 'Αποστολή Χρειάζεται Μέλη', 'Όταν μια αποστολή πλησιάζει και δεν έχει αρκετούς μέλη', 1],
];

foreach ($notifications as $notif) {
    dbInsert(
        "INSERT INTO notification_settings (id, code, name, description, email_enabled) VALUES (?, ?, ?, ?, ?)",
        $notif
    );
}

echo "✅ Επαναφορά ολοκληρώθηκε! Ανανέωσε τη σελίδα Settings.\n";
echo "Σύνολο: " . count($notifications) . " notifications\n";
