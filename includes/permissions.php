<?php
/**
 * EasyRide - Custom Role Permission Map
 * Fine-grained page permissions for custom roles.
 * SYSTEM_ADMIN always has full access regardless of this map.
 * Applies only to MEMBER-base users with a custom_role_id.
 *
 * Implication rules:
 *   missions_manage   → implies missions_view
 *   complaints_manage → implies complaints_view
 *   citizens_manage   → implies citizens_view
 *   members_manage → implies members_view
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Returns the full permission map grouped by section.
 * Each entry: ['slug' => string, 'label' => string, 'icon' => string, 'description' => string]
 */
function getPermissionMap(): array {
    return [
        'Επιχειρήσεις' => [
            ['slug' => 'ops_dashboard',      'label' => 'Live Επιχειρησιακό',                   'icon' => 'bi-broadcast',        'description' => 'Ζωντανή προβολή δράσεων, μελών & SOS'],
            ['slug' => 'attendance_manage',  'label' => 'Παρουσιολόγιο Δράσης',                'icon' => 'bi-clipboard-check',  'description' => 'Καταγραφή παρουσιών μελών'],
        ],
        'Δράσεις' => [
            ['slug' => 'missions_view',      'label' => 'Προβολή Δράσεων (όλες)',               'icon' => 'bi-eye',              'description' => 'Βλέπει πρόχειρες, κλειστές & ολοκληρωμένες'],
            ['slug' => 'missions_manage',    'label' => 'Διαχείριση Δράσεων',                   'icon' => 'bi-flag',             'description' => 'Δημιουργία, επεξεργασία, αλλαγή κατάστασης, διαγραφή δράσεων'],
            ['slug' => 'shifts_manage',      'label' => 'Διαχείριση Κύκλων Εγγραφών',           'icon' => 'bi-clock',            'description' => 'Δημιουργία Κύκλων Εγγραφών, έγκριση/απόρριψη συμμετοχών'],
            ['slug' => 'tasks_manage',       'label' => 'Διαχείριση Εργασιών',                 'icon' => 'bi-list-task',        'description' => 'Δημιουργία & ανάθεση εργασιών'],
        ],
        'Μέλη' => [
            ['slug' => 'members_view',    'label' => 'Προβολή Προφίλ Μελών',                'icon' => 'bi-person-badge',     'description' => 'Ανάγνωση προφίλ & εγγράφων'],
            ['slug' => 'members_manage',  'label' => 'Διαχείριση Μελών',                    'icon' => 'bi-people',           'description' => 'Πλήρης λίστα, δημιουργία, επεξεργασία μελών'],
            ['slug' => 'inactive_members','label' => 'Ανενεργά Μέλη',                       'icon' => 'bi-person-x',         'description' => 'Προβολή & ενεργοποίηση ανενεργών μελών'],
            ['slug' => 'complaints_view',    'label' => 'Παράπονα (Προβολή)',                  'icon' => 'bi-chat-left-dots',   'description' => 'Βλέπει παράπονα'],
            ['slug' => 'complaints_manage',  'label' => 'Παράπονα (Διαχείριση)',               'icon' => 'bi-chat-left-text',   'description' => 'Αλλαγή κατάστασης, ανάθεση, απάντηση σε παράπονα'],
        ],
        'Συνδρομές' => [
            ['slug' => 'citizens_view',      'label' => 'Συνδρομές (Προβολή)',                 'icon' => 'bi-person-vcard',     'description' => 'Λίστα συνδρομών'],
            ['slug' => 'citizens_manage',    'label' => 'Συνδρομές (Διαχείριση)',              'icon' => 'bi-file-earmark-medical', 'description' => 'Λήξεις συνδρομών & τύποι συνδρομών'],
        ],
        'Ρυθμίσεις' => [
            ['slug' => 'positions_manage',   'label' => 'Ρόλοι Μελών',                        'icon' => 'bi-person-badge',     'description' => 'Διαχείριση ρόλων μελών εντός της λέσχης'],
        ],
    ];
}

/**
 * Returns a flat array of all permission slugs.
 */
function getAllPermissionSlugs(): array {
    $slugs = [];
    foreach (getPermissionMap() as $perms) {
        foreach ($perms as $perm) {
            $slugs[] = $perm['slug'];
        }
    }
    return $slugs;
}

/**
 * Returns slugs that are implied by another slug.
 * Key = implied slug, Value = slug(s) that grant it.
 */
function getImpliedSlugs(): array {
    return [
        'missions_view'   => 'missions_manage',
        'complaints_view' => 'complaints_manage',
        'citizens_view'   => 'citizens_manage',
        'members_view' => 'members_manage',
    ];
}

/**
 * Keep only currently supported permission slugs.
 */
function filterCurrentPermissionSlugs(array $slugs): array {
    $valid = array_flip(getAllPermissionSlugs());
    $filtered = [];

    foreach ($slugs as $slug) {
        $slug = (string) $slug;
        if (isset($valid[$slug]) && !in_array($slug, $filtered, true)) {
            $filtered[] = $slug;
        }
    }

    return $filtered;
}

/**
 * Adds implied view permissions to a direct permission list.
 */
function expandPermissionSlugs(array $directSlugs): array {
    $effective = filterCurrentPermissionSlugs($directSlugs);
    $directSet = array_flip($effective);

    foreach (getImpliedSlugs() as $impliedSlug => $grantingSlugs) {
        foreach ((array) $grantingSlugs as $grantingSlug) {
            if (isset($directSet[$grantingSlug]) && !in_array($impliedSlug, $effective, true)) {
                $effective[] = $impliedSlug;
                break;
            }
        }
    }

    return filterCurrentPermissionSlugs($effective);
}
