<?php
/**
 * Script to insert email templates with proper UTF-8 encoding
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

// Insert email templates
$templates = [
    [
        'code' => 'welcome',
        'name' => 'Καλωσόρισμα',
        'subject' => 'Καλώς ήρθατε στο {{app_name}}!',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>{{app_name}}</h1></div><div style="padding: 30px; background: #fff;"><h2>Καλώς ήρθατε, {{user_name}}!</h2><p>Ευχαριστούμε για την εγγραφή σας στην πλατφόρμα της λέσχης.</p></div></div>',
        'description' => 'Αποστέλλεται σε νέους χρήστες μετά την εγγραφή',
        'available_variables' => '{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}'
    ],
    [
        'code' => 'participation_approved',
        'name' => 'Έγκριση Συμμετοχής',
        'subject' => 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>✓ Εγκρίθηκε!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η συμμετοχή σας στον Κύκλο Εγγραφών εγκρίθηκε!</p><p><strong>Αποστολή:</strong> {{mission_title}}</p><p><strong>Κύκλος Εγγραφών:</strong> {{shift_date}} ({{shift_time}})</p><p><strong>Τοποθεσία:</strong> {{location}}</p></div></div>',
        'description' => 'Αποστέλλεται όταν εγκρίνεται η συμμετοχή μέλους σε Κύκλο Εγγραφών',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
    ],
    [
        'code' => 'participation_rejected',
        'name' => 'Απόρριψη Συμμετοχής',
        'subject' => 'Η συμμετοχή σας δεν εγκρίθηκε - {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ενημέρωση Συμμετοχής</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Δυστυχώς η αίτηση συμμετοχής σας δεν μπόρεσε να εγκριθεί.</p><p><strong>Αποστολή:</strong> {{mission_title}}</p></div></div>',
        'description' => 'Αποστέλλεται όταν απορρίπτεται η συμμετοχή μέλους',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}'
    ],
    [
        'code' => 'shift_reminder',
        'name' => 'Υπενθύμιση Κύκλου Εγγραφών',
        'subject' => 'Υπενθύμιση: Αύριο έχετε Κύκλο Εγγραφών - {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #f39c12; color: white; padding: 20px; text-align: center;"><h1>⏰ Υπενθύμιση</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Σας υπενθυμίζουμε ότι αύριο έχετε Κύκλο Εγγραφών.</p><p><strong>Αποστολή:</strong> {{mission_title}}</p><p><strong>Ώρα:</strong> {{shift_time}}</p><p><strong>Τοποθεσία:</strong> {{location}}</p></div></div>',
        'description' => 'Αποστέλλεται την προηγούμενη μέρα του Κύκλου Εγγραφών',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
    ],
    [
        'code' => 'new_mission',
        'name' => 'Νέα Αποστολή',
        'subject' => 'Νέα αποστολή: {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>🚀 Νέα Αποστολή!</h1></div><div style="padding: 30px; background: #fff;"><h2>{{mission_title}}</h2><p>{{mission_description}}</p><p><strong>Τοποθεσία:</strong> {{location}}</p><p><strong>Περίοδος:</strong> {{start_date}} - {{end_date}}</p></div></div>',
        'description' => 'Αποστέλλεται σε μέλη όταν δημοσιεύεται νέα αποστολή',
        'available_variables' => '{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}, {{start_date}}, {{end_date}}, {{mission_url}}'
    ],
    [
        'code' => 'mission_canceled',
        'name' => 'Ακύρωση Αποστολής',
        'subject' => 'Ακυρώθηκε η αποστολή: {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση Αποστολής</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Σας ενημερώνουμε ότι η αποστολή {{mission_title}} ακυρώθηκε.</p><p>Ζητούμε συγγνώμη για την όποια αναστάτωση.</p></div></div>',
        'description' => 'Αποστέλλεται σε μέλη όταν ακυρώνεται αποστολή',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}'
    ],
    [
        'code' => 'shift_canceled',
        'name' => 'Ακύρωση Κύκλου Εγγραφών',
        'subject' => 'Ακυρώθηκε ο Κύκλος Εγγραφών: {{shift_date}} - {{mission_title}}',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση Κύκλου Εγγραφών</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Ο Κύκλος Εγγραφών στις {{shift_date}} ({{shift_time}}) για την αποστολή {{mission_title}} ακυρώθηκε.</p></div></div>',
        'description' => 'Αποστέλλεται σε μέλη όταν ακυρώνεται Κύκλος Εγγραφών',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}'
    ],
    [
        'code' => 'points_earned',
        'name' => 'Κέρδος Πόντων',
        'subject' => 'Κερδίσατε {{points}} πόντους!',
        'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>🎉 Συγχαρητήρια!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p style="font-size: 24px; color: #27ae60;"><strong>+{{points}} πόντοι</strong></p><p>Συνολικοί πόντοι: <strong>{{total_points}}</strong></p></div></div>',
        'description' => 'Αποστέλλεται όταν το μέλος κερδίζει πόντους',
        'available_variables' => '{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}'
    ]
];

foreach ($templates as $t) {
    dbInsert(
        "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables, is_active, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
        [$t['code'], $t['name'], $t['subject'], $t['body_html'], $t['description'], $t['available_variables']]
    );
}

echo "Email templates inserted!\n";

// Insert notification settings
$notifications = [
    ['code' => 'welcome', 'name' => 'Καλωσόρισμα', 'description' => 'Μετά την εγγραφή νέου χρήστη', 'enabled' => 1],
    ['code' => 'new_mission', 'name' => 'Νέα Αποστολή', 'description' => 'Όταν δημοσιεύεται νέα αποστολή', 'enabled' => 1],
    ['code' => 'participation_approved', 'name' => 'Έγκριση Συμμετοχής', 'description' => 'Όταν εγκρίνεται η συμμετοχή μέλους σε Κύκλο Εγγραφών', 'enabled' => 1],
    ['code' => 'participation_rejected', 'name' => 'Απόρριψη Συμμετοχής', 'description' => 'Όταν απορρίπτεται η συμμετοχή μέλους', 'enabled' => 1],
    ['code' => 'shift_reminder', 'name' => 'Υπενθύμιση Κύκλου Εγγραφών', 'description' => 'Μία μέρα πριν τον Κύκλο Εγγραφών', 'enabled' => 1],
    ['code' => 'mission_canceled', 'name' => 'Ακύρωση Αποστολής', 'description' => 'Όταν ακυρώνεται αποστολή', 'enabled' => 1],
    ['code' => 'shift_canceled', 'name' => 'Ακύρωση Κύκλου Εγγραφών', 'description' => 'Όταν ακυρώνεται Κύκλος Εγγραφών', 'enabled' => 1],
    ['code' => 'points_earned', 'name' => 'Κέρδος Πόντων', 'description' => 'Όταν το μέλος κερδίζει πόντους', 'enabled' => 0],
];

foreach ($notifications as $n) {
    $templateId = dbFetchValue("SELECT id FROM email_templates WHERE code = ?", [$n['code']]);
    dbInsert(
        "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
        [$n['code'], $n['name'], $n['description'], $n['enabled'], $templateId]
    );
}

echo "Notification settings inserted!\n";
echo "Done!\n";
