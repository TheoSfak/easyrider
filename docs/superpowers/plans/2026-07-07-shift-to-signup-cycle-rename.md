# "Σκέλος"/"Βάρδια" → "Κύκλος Εγγραφών" UI Wording Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every user-facing occurrence of "Σκέλος"/"σκέλη" (neuter) and "Βάρδια"/"βάρδιες" (feminine) — two inconsistently-used words for the same `shifts` concept — with a single consistent term, "Κύκλος Εγγραφών" (masculine), across all live UI text, emails, and notifications.

**Architecture:** Pure text substitution. No schema changes to the `shifts`/`shift_swap_requests` tables, no file renames, no PHP identifier changes. One exception: live-stored rows in `achievements`/`email_templates`/`notification_settings` need a new migration (version 79) with UPDATE statements, because editing the PHP source of an *already-applied* historical migration in `includes/migrations.php` would not change what's already sitting in the database.

**Tech Stack:** PHP 8.2 procedural, MariaDB 10.4, no automated test framework (project convention — verify via `php -l` + a gender-agreement checker script + manual/CLI walkthroughs).

## Global Constraints

- Every source word converts to "Κύκλος Εγγραφών" per this exact grammatical table (from the spec):

  | Case | σκέλος (neuter) | βάρδια (feminine) | → Κύκλος Εγγραφών (masculine) |
  |---|---|---|---|
  | Nom. sg | το σκέλος | η βάρδια | ο Κύκλος Εγγραφών |
  | Nom. pl | τα σκέλη | οι βάρδιες | οι Κύκλοι Εγγραφών |
  | Gen. sg | του σκέλους | της βάρδιας | του Κύκλου Εγγραφών |
  | Gen. pl | των σκελών | των βαρδιών | των Κύκλων Εγγραφών |
  | Acc. sg | το σκέλος | τη(ν) βάρδια | τον Κύκλο Εγγραφών |
  | Acc. pl | τα σκέλη | τις βάρδιες | τους Κύκλους Εγγραφών |

  "Εγγραφών" never inflects. Any adjective/pronoun touching the word must also flip to masculine (e.g. "νέα βάρδια" → "νέος Κύκλος Εγγραφών", "άλλες βάρδιες" → "άλλους Κύκλους Εγγραφών" when accusative).
- **Out of scope, must NOT change:** file names (`shifts.php`, `shift-form.php`, etc.), the `shifts`/`shift_swap_requests` tables and columns, PHP variables/functions/array keys (`$shift`, `shift_id`, `shiftId`), URLs/hrefs, achievement/notification `code` values (`shifts_5`, `shift_reminder`, etc.), `seed_questions_run.php`'s unrelated anatomical "σκέλη" (legs), and every already-applied historical migration entry's PHP source in `includes/migrations.php` (immutable historical record per established project policy — fixing what those migrations *seeded* happens via a new migration, not by editing old ones).
- `php -l` on every touched file before committing.
- No automated tests exist — verification is `php -l` + the gender-agreement checker script (Task 8) + manual/CLI spot-checks named per task.

---

### Task 1: Core shift CRUD pages

**Files:**
- Modify: `shifts.php:9,101,105,157,177,241`
- Modify: `shift-form.php:12,21`
- Modify: `shift-view.php:263,334,764,1185,1422,1479`
- Modify: `shift-calendar.php:3,10,292,293,483,485,489`

**Interfaces:** None — pure string literal edits, no functions/variables introduced or changed.

- [ ] **Step 1: Edit `shifts.php`**

| Line | Old | New |
|---|---|---|
| 9 | `$pageTitle = 'Βάρδιες';` | `$pageTitle = 'Κύκλοι Εγγραφών';` |
| 101 | `<i class="bi bi-clock me-2"></i>Βάρδιες` | `<i class="bi bi-clock me-2"></i>Κύκλοι Εγγραφών` |
| 105 | `<i class="bi bi-plus-lg me-1"></i>Νέα Βάρδια` | `<i class="bi bi-plus-lg me-1"></i>Νέος Κύκλος Εγγραφών` |
| 157 | `<th>Βάρδια</th>` | `<th>Κύκλος Εγγραφών</th>` |
| 177 | `<strong><?= h(($shift['title'] ?? '') ?: 'Βάρδια #' . $shift['id']) ?></strong>` | `<strong><?= h(($shift['title'] ?? '') ?: 'Κύκλος Εγγραφών #' . $shift['id']) ?></strong>` |
| 241 | `<strong><?= h(($shift['title'] ?? '') ?: 'Βάρδια #' . $shift['id']) ?></strong>` | `<strong><?= h(($shift['title'] ?? '') ?: 'Κύκλος Εγγραφών #' . $shift['id']) ?></strong>` |

- [ ] **Step 2: Edit `shift-form.php`**

| Line | Old | New |
|---|---|---|
| 12 | `$pageTitle = 'Νέα Βάρδια';` | `$pageTitle = 'Νέος Κύκλος Εγγραφών';` |
| 21 | `$pageTitle = 'Επεξεργασία Βάρδιας';` | `$pageTitle = 'Επεξεργασία Κύκλου Εγγραφών';` |

- [ ] **Step 3: Edit `shift-view.php`**

| Line | Old | New |
|---|---|---|
| 263 | `[$pr['member_id'], $points, 'shift_attendance', "Βάρδια: " . $shift['mission_title'], $id]` | `[$pr['member_id'], $points, 'shift_attendance', "Κύκλος Εγγραφών: " . $shift['mission_title'], $id]` |
| 334 | `'Ακύρωση Βάρδιας',` | `'Ακύρωση Κύκλου Εγγραφών',` |
| 764 | `<h5 class="mb-0"><i class="bi bi-clock me-1"></i>Στοιχεία Βάρδιας</h5>` | `<h5 class="mb-0"><i class="bi bi-clock me-1"></i>Στοιχεία Κύκλου Εγγραφών</h5>` |
| 1185 | `<i class="bi bi-trash me-1"></i>Διαγραφή Βάρδιας` | `<i class="bi bi-trash me-1"></i>Διαγραφή Κύκλου Εγγραφών` |
| 1422 | `<h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Διαγραφή Βάρδιας</h5>` | `<h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Διαγραφή Κύκλου Εγγραφών</h5>` |
| 1479 | `<h5 class="modal-title" id="qrModalLabel"><i class="bi bi-qr-code me-2"></i>QR Check-in Βάρδιας</h5>` | `<h5 class="modal-title" id="qrModalLabel"><i class="bi bi-qr-code me-2"></i>QR Check-in Κύκλου Εγγραφών</h5>` |

- [ ] **Step 4: Edit `shift-calendar.php`**

| Line | Old | New |
|---|---|---|
| 3 | ` * VolunteerOps - Ημερολόγιο Βάρδιων` | ` * VolunteerOps - Ημερολόγιο Κύκλων Εγγραφών` |
| 10 | `$pageTitle = 'Ημερολόγιο Βάρδιων';` | `$pageTitle = 'Ημερολόγιο Κύκλων Εγγραφών';` |
| 292 | `<h1 class="cal-hero-title">Βάρδιες Αποστολών</h1>` | `<h1 class="cal-hero-title">Κύκλοι Εγγραφών Αποστολών</h1>` |
| 293 | `<p class="cal-hero-sub">Εποπτεία και διαχείριση βαρδιών μελών</p>` | `<p class="cal-hero-sub">Εποπτεία και διαχείριση Κύκλων Εγγραφών μελών</p>` |
| 483 | `+ '&text='     + encodeURIComponent(ep.mission_title + ' — Βάρδια #' + ep.shift_id)` | `+ '&text='     + encodeURIComponent(ep.mission_title + ' — Κύκλος Εγγραφών #' + ep.shift_id)` |
| 485 | `+ '&details='  + encodeURIComponent('Βάρδια Λέσχης - ' + ep.mission_title)` | `+ '&details='  + encodeURIComponent('Κύκλος Εγγραφών Λέσχης - ' + ep.mission_title)` |
| 489 | `? '<a href="' + info.event.url + '" class="btn btn-sm btn-outline-primary w-100 mt-2" style="font-size:0.78rem;"><i class="bi bi-eye me-1"></i>Προβολή Βάρδιας</a>'` | `? '<a href="' + info.event.url + '" class="btn btn-sm btn-outline-primary w-100 mt-2" style="font-size:0.78rem;"><i class="bi bi-eye me-1"></i>Προβολή Κύκλου Εγγραφών</a>'` |

- [ ] **Step 5: Lint**

Run: `C:\xampp\php\php.exe -l shifts.php && C:\xampp\php\php.exe -l shift-form.php && C:\xampp\php\php.exe -l shift-view.php && C:\xampp\php\php.exe -l shift-calendar.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Commit**

```bash
git add shifts.php shift-form.php shift-view.php shift-calendar.php
git commit -m "Rename shift wording to Kyklos Eggrafon on core shift CRUD pages"
```

---

### Task 2: Calendar/ICS export APIs and the calendar-link helper

**Files:**
- Modify: `api-shifts-calendar.php:160`
- Modify: `api-shifts-calendar-ics.php:151,174`
- Modify: `includes/functions.php:160`

**Interfaces:** None — string literal edits only.

- [ ] **Step 1: Edit `api-shifts-calendar.php`**

| Line | Old | New |
|---|---|---|
| 160 | `$shiftLabel  = 'Βάρδια #' . $s['id'];` | `$shiftLabel  = 'Κύκλος Εγγραφών #' . $s['id'];` |

- [ ] **Step 2: Edit `api-shifts-calendar-ics.php`**

| Line | Old | New |
|---|---|---|
| 151 | `$lines[] = 'X-WR-CALNAME:' . icsText($appName . ' - Βάρδιες');` | `$lines[] = 'X-WR-CALNAME:' . icsText($appName . ' - Κύκλοι Εγγραφών');` |
| 174 | `$title       = ($s['is_urgent'] ? '[ΕΠΕΙΓΟΝ] ' : '') . $s['mission_title'] . ' — Βάρδια #' . $s['id'];` | `$title       = ($s['is_urgent'] ? '[ΕΠΕΙΓΟΝ] ' : '') . $s['mission_title'] . ' — Κύκλος Εγγραφών #' . $s['id'];` |

- [ ] **Step 3: Edit `includes/functions.php`**

| Line | Old | New |
|---|---|---|
| 160 | `. '&details=' . rawurlencode('Βάρδια Λέσχης')` | `. '&details=' . rawurlencode('Κύκλος Εγγραφών Λέσχης')` |

- [ ] **Step 4: Lint**

Run: `C:\xampp\php\php.exe -l api-shifts-calendar.php && C:\xampp\php\php.exe -l api-shifts-calendar-ics.php && C:\xampp\php\php.exe -l includes/functions.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Commit**

```bash
git add api-shifts-calendar.php api-shifts-calendar-ics.php includes/functions.php
git commit -m "Rename shift wording in calendar/ICS export APIs"
```

---

### Task 3: Attendance, check-in & participations

**Files:**
- Modify: `attendance.php:256,333`
- Modify: `checkin.php:65,117,175,193`
- Modify: `participations.php:281`
- Modify: `my-participations.php:276,365,416,458,553,614,667,706,748`

**Interfaces:** None — string literal edits only.

- [ ] **Step 1: Edit `attendance.php`**

| Line | Old | New |
|---|---|---|
| 256 | `[$pr['member_id'], $points, 'SHIFT_COMPLETED', 'Βάρδια: ' . $mission['title'], 'App\\Models\\Shift', $shiftId]` | `[$pr['member_id'], $points, 'SHIFT_COMPLETED', 'Κύκλος Εγγραφών: ' . $mission['title'], 'App\\Models\\Shift', $shiftId]` |
| 333 | `<i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν βάρδιες σε αυτή την αποστολή.` | `<i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν κύκλοι εγγραφών σε αυτή την αποστολή.` |

(`'App\\Models\\Shift'` stays unchanged — internal polymorphic-type identifier, not display text.)

- [ ] **Step 2: Edit `checkin.php`**

| Line | Old | New |
|---|---|---|
| 65 | `<p class="text-muted">Αυτός ο σύνδεσμος δεν αντιστοιχεί σε ενεργή βάρδια.</p>` | `<p class="text-muted">Αυτός ο σύνδεσμος δεν αντιστοιχεί σε ενεργό Κύκλο Εγγραφών.</p>` |
| 117 | `setFlash('error', 'Δεν βρέθηκε εγκεκριμένη συμμετοχή για αυτή τη βάρδια.');` | `setFlash('error', 'Δεν βρέθηκε εγκεκριμένη συμμετοχή για αυτόν τον Κύκλο Εγγραφών.');` |
| 175 | `<p class="mb-0 mt-1 small">Δεν έχετε εγκεκριμένη αίτηση για αυτή τη βάρδια. Επικοινωνήστε με τον υπεύθυνο βάρδιας.</p>` | `<p class="mb-0 mt-1 small">Δεν έχετε εγκεκριμένη αίτηση για αυτόν τον Κύκλο Εγγραφών. Επικοινωνήστε με τον υπεύθυνο Κύκλου Εγγραφών.</p>` |
| 193 | `Η παρουσία σας έχει ήδη σημειωθεί ως απουσία. Επικοινωνήστε με τον υπεύθυνο βάρδιας ή διαχειριστή για έλεγχο.` | `Η παρουσία σας έχει ήδη σημειωθεί ως απουσία. Επικοινωνήστε με τον υπεύθυνο Κύκλου Εγγραφών ή διαχειριστή για έλεγχο.` |

- [ ] **Step 3: Edit `participations.php`**

| Line | Old | New |
|---|---|---|
| 281 | `<th>Βάρδια</th>` | `<th>Κύκλος Εγγραφών</th>` |

- [ ] **Step 4: Edit `my-participations.php`**

| Line | Old | New |
|---|---|---|
| 276 | `<strong><?= h($sr['requester_name']) ?></strong> δεν μπορεί να παραστεί στο σκέλος:` | `<strong><?= h($sr['requester_name']) ?></strong> δεν μπορεί να παραστεί στον Κύκλο Εγγραφών:` |
| 365, 458, 614, 706 | `<th>Σκέλος</th>` | `<th>Κύκλος Εγγραφών</th>` |
| 416, 553, 667, 748 | `<div class="mobile-card-label">Σκέλος</div>` | `<div class="mobile-card-label">Κύκλος Εγγραφών</div>` |

- [ ] **Step 5: Lint**

Run: `C:\xampp\php\php.exe -l attendance.php && C:\xampp\php\php.exe -l checkin.php && C:\xampp\php\php.exe -l participations.php && C:\xampp\php\php.exe -l my-participations.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Commit**

```bash
git add attendance.php checkin.php participations.php my-participations.php
git commit -m "Rename shift wording in attendance, check-in, and participations pages"
```

---

### Task 4: Dashboards & reports

**Files:**
- Modify: `dashboard.php:110,951,1101,1181,1240,1944,1951,2001`
- Modify: `mission-view.php:278,432,1343,1346,2264`
- Modify: `missions.php:282,464,471`
- Modify: `ops-dashboard.php:663,677,1301,1311`
- Modify: `ride-report.php:244,246,250`
- Modify: `members.php:371,531`
- Modify: `member-view.php:655,804`
- Modify: `member-report.php:245,277,308`
- Modify: `inactive-members.php:247`
- Modify: `leaderboard.php:190`
- Modify: `profile.php:190`

**Interfaces:** None — string literal edits only.

- [ ] **Step 1: Edit `dashboard.php`**

| Line | Old | New |
|---|---|---|
| 110 | `[$pr['member_id'], $points, 'SHIFT_COMPLETED', 'Σκέλος: ' . $bulkMission['title'], 'App\\Models\\Shift', $pr['shift_id']]` | `[$pr['member_id'], $points, 'SHIFT_COMPLETED', 'Κύκλος Εγγραφών: ' . $bulkMission['title'], 'App\\Models\\Shift', $pr['shift_id']]` |
| 951 | `<div class="stat-label">Σκέλη</div>` | `<div class="stat-label">Κύκλοι Εγγραφών</div>` |
| 1101 | `<span class="badge bg-secondary"><?= (int)$om['shift_count'] ?> σκέλη</span>` | `<span class="badge bg-secondary"><?= (int)$om['shift_count'] ?> κύκλοι εγγραφών</span>` |
| 1181 | `<?= $vol['shifts_count'] ?> σκέλη` | `<?= $vol['shifts_count'] ?> κύκλοι εγγραφών` |
| 1240 | `CONCAT(u.name, ' αιτήθηκε για σκέλος') as description,` | `CONCAT(u.name, ' αιτήθηκε για κύκλο εγγραφών') as description,` |
| 1944 | `<h5><i class="bi bi-calendar-event text-primary me-2"></i>Επόμενα Σκέλη</h5>` | `<h5><i class="bi bi-calendar-event text-primary me-2"></i>Επόμενοι Κύκλοι Εγγραφών</h5>` |
| 1951 | `<p class="text-muted mt-2 mb-0">Δεν έχετε προγραμματισμένα σκέλη.</p>` | `<p class="text-muted mt-2 mb-0">Δεν έχετε προγραμματισμένους κύκλους εγγραφών.</p>` |
| 2001 | `<?= $mission['shift_count'] ?> σκέλη` | `<?= $mission['shift_count'] ?> κύκλοι εγγραφών` |

- [ ] **Step 2: Edit `mission-view.php`**

| Line | Old | New |
|---|---|---|
| 278 | `'Η δράση είναι ακόμα ανοιχτή και αναζητά μέλη. Δείτε τα διαθέσιμα σκέλη.'` | `'Η δράση είναι ακόμα ανοιχτή και αναζητά μέλη. Δείτε τους διαθέσιμους κύκλους εγγραφών.'` |
| 432 | `'Μια νέα δράση δημοσιεύτηκε και αναζητά μέλη. Δείτε τα διαθέσιμα σκέλη.'` | `'Μια νέα δράση δημοσιεύτηκε και αναζητά μέλη. Δείτε τους διαθέσιμους κύκλους εγγραφών.'` |
| 1343 | `<h5 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Σκέλη</h5>` | `<h5 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Κύκλοι Εγγραφών</h5>` |
| 1346 | `<i class="bi bi-plus-lg"></i> Νέο Σκέλος` | `<i class="bi bi-plus-lg"></i> Νέος Κύκλος Εγγραφών` |
| 2264 | `<i class="bi bi-person-plus me-1"></i>Προσθήκη σε Επιλεγμένα Σκέλη` | `<i class="bi bi-person-plus me-1"></i>Προσθήκη σε Επιλεγμένους Κύκλους Εγγραφών` |

- [ ] **Step 3: Edit `missions.php`**

| Line | Old | New |
|---|---|---|
| 282 | `<th>Σκέλη</th>` | `<th>Κύκλοι Εγγραφών</th>` |
| 464 | `<span class="text-muted ms-1">σκέλη</span>` | `<span class="text-muted ms-1">κύκλοι εγγραφών</span>` |
| 471 | `<span class="text-muted ms-1">σκέλη</span>` | `<span class="text-muted ms-1">κύκλοι εγγραφών</span>` |

- [ ] **Step 4: Edit `ops-dashboard.php`**

| Line | Old | New |
|---|---|---|
| 663 | `<a href="shift-view.php?id=<?= $al['shift_id'] ?>" class="alert-link ms-2">Σκέλος →</a>` | `<a href="shift-view.php?id=<?= $al['shift_id'] ?>" class="alert-link ms-2">Κύκλος Εγγραφών →</a>` |
| 677 | `Σκέλος <?= formatDateTime($al['start_time']) ?>:` | `Κύκλος Εγγραφών <?= formatDateTime($al['start_time']) ?>:` |
| 1301 | `+ ` <a href="shift-view.php?id=${al.shift_id}" class="alert-link ms-2">Σκέλος →</a></div>`` | `+ ` <a href="shift-view.php?id=${al.shift_id}" class="alert-link ms-2">Κύκλος Εγγραφών →</a></div>`` |
| 1311 | `+ `<div><strong>${al.mission_title}</strong> — Σκέλος ${al.start_time}: `` | `+ `<div><strong>${al.mission_title}</strong> — Κύκλος Εγγραφών ${al.start_time}: `` |

(hrefs `shift-view.php?id=...` stay unchanged — file name is out of scope.)

- [ ] **Step 5: Edit `ride-report.php`**

| Line | Old | New |
|---|---|---|
| 244 | `<h2>Σκέλη & Συμμετοχές</h2>` | `<h2>Κύκλοι Εγγραφών & Συμμετοχές</h2>` |
| 246 | `<thead><tr><th>Σκέλος</th><th>Ώρες</th><th>Εγκεκριμένα μέλη</th></tr></thead>` | `<thead><tr><th>Κύκλος Εγγραφών</th><th>Ώρες</th><th>Εγκεκριμένα μέλη</th></tr></thead>` |
| 250 | `<td><?= h($shift['title'] ?? 'Σκέλος #' . $shift['id']) ?></td>` | `<td><?= h($shift['title'] ?? 'Κύκλος Εγγραφών #' . $shift['id']) ?></td>` |

- [ ] **Step 6: Edit `members.php`**

| Line | Old | New |
|---|---|---|
| 371 | `<th class="text-center">Σκέλη</th>` | `<th class="text-center">Κύκλοι Εγγραφών</th>` |
| 531 | `<li>Συμμετοχές σε σκέλη: <strong><?= $v['shifts_count'] ?></strong></li>` | `<li>Συμμετοχές σε κύκλους εγγραφών: <strong><?= $v['shifts_count'] ?></strong></li>` |

- [ ] **Step 7: Edit `member-view.php`, `member-report.php`, `inactive-members.php`, `leaderboard.php`, `profile.php`**

| File | Line | Old | New |
|---|---|---|---|
| `member-view.php` | 655 | `<small class="text-muted">Βάρδιες</small>` | `<small class="text-muted">Κύκλοι Εγγραφών</small>` |
| `member-view.php` | 804 | `<th>Βάρδια</th>` | `<th>Κύκλος Εγγραφών</th>` |
| `member-report.php` | 245 | `<div class="lbl">Βάρδιες</div>` | `<div class="lbl">Κύκλοι Εγγραφών</div>` |
| `member-report.php` | 277 | `<th class="text-center">Βάρδιες</th>` | `<th class="text-center">Κύκλοι Εγγραφών</th>` |
| `member-report.php` | 308 | `<th>Ώρες Βάρδιας</th>` | `<th>Ώρες Κύκλου Εγγραφών</th>` |
| `inactive-members.php` | 247 | `<th class="text-center">Βάρδιες</th>` | `<th class="text-center">Κύκλοι Εγγραφών</th>` |
| `leaderboard.php` | 190 | `<th class="text-center">Βάρδιες</th>` | `<th class="text-center">Κύκλοι Εγγραφών</th>` |
| `profile.php` | 190 | `<th class="text-center">Βάρδιες</th>` | `<th class="text-center">Κύκλοι Εγγραφών</th>` |

- [ ] **Step 8: Lint**

Run each: `C:\xampp\php\php.exe -l dashboard.php`, `... mission-view.php`, `... missions.php`, `... ops-dashboard.php`, `... ride-report.php`, `... members.php`, `... member-view.php`, `... member-report.php`, `... inactive-members.php`, `... leaderboard.php`, `... profile.php`
Expected: `No syntax errors detected` for all eleven.

- [ ] **Step 9: Commit**

```bash
git add dashboard.php mission-view.php missions.php ops-dashboard.php ride-report.php members.php member-view.php member-report.php inactive-members.php leaderboard.php profile.php
git commit -m "Rename shift wording across dashboards and reports"
```

---

### Task 5: Settings, roles & exports

**Files:**
- Modify: `settings.php:1088,1091,1108,1284,1798,1909,2465`
- Modify: `role-form.php:149`
- Modify: `includes/export-functions.php:101,311,312`
- Modify: `includes/newsletter-functions.php:71`

**Interfaces:** None — string literal edits only. `settings.php`'s `for="shift_reminder_hours"`/`name="shift_reminder_hours"` attribute values are identifiers, not display text — leave unchanged.

- [ ] **Step 1: Edit `settings.php`**

| Line | Old | New |
|---|---|---|
| 1088 | `<label for="shift_reminder_hours" class="form-label">Υπενθύμιση Σκέλους / Διαδρομής (ώρες πριν)</label>` | `<label for="shift_reminder_hours" class="form-label">Υπενθύμιση Κύκλου Εγγραφών / Διαδρομής (ώρες πριν)</label>` |
| 1091 | `<small class="text-muted">Πόσες ώρες πριν το σκέλος της δράσης να στέλνεται υπενθύμιση (προεπιλογή: 24)</small>` | `<small class="text-muted">Πόσες ώρες πριν τον Κύκλο Εγγραφών της δράσης να στέλνεται υπενθύμιση (προεπιλογή: 24)</small>` |
| 1108 | `<small class="text-muted">Αν ένα σκέλος δεν έχει συμπληρωθεί, στείλε email προς τα μέλη Χ ώρες πριν (προεπιλογή: 48)</small>` | `<small class="text-muted">Αν ένας κύκλος εγγραφών δεν έχει συμπληρωθεί, στείλε email προς τα μέλη Χ ώρες πριν (προεπιλογή: 48)</small>` |
| 1284 | `Όταν είναι ενεργό, κάθε σκέλος αποκτά μοναδικό QR κωδικό. Ο υπεύθυνος ανοίγει το QR και τα μέλη σκανάρουν για αυτόματο check-in παρουσίας.` | `Όταν είναι ενεργό, κάθε κύκλος εγγραφών αποκτά μοναδικό QR κωδικό. Ο υπεύθυνος ανοίγει το QR και τα μέλη σκανάρουν για αυτόματο check-in παρουσίας.` |
| 1798 | `'shift_reminders'     => ['label' => 'Υπενθυμίσεις Σκελών',         'icon' => 'bi-alarm',              'desc' => 'Ειδοποιεί τα εγκεκριμένα μέλη για σκέλη δράσεων εντός ' . h($settings['shift_reminder_hours'] ?? '24') . ' ωρών.', 'color' => 'info'],` | `'shift_reminders'     => ['label' => 'Υπενθυμίσεις Κύκλων Εγγραφών', 'icon' => 'bi-alarm',              'desc' => 'Ειδοποιεί τα εγκεκριμένα μέλη για κύκλους εγγραφών δράσεων εντός ' . h($settings['shift_reminder_hours'] ?? '24') . ' ωρών.', 'color' => 'info'],` |
| 1909 | `<strong>Ώρες υπενθύμισης σκέλους:</strong> <?= h($settings['shift_reminder_hours'] ?? '24') ?>h` | `<strong>Ώρες υπενθύμισης Κύκλου Εγγραφών:</strong> <?= h($settings['shift_reminder_hours'] ?? '24') ?>h` |
| 2465 | `<li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Όλες οι δράσεις &amp; σκέλη</li>` | `<li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Όλες οι δράσεις &amp; κύκλοι εγγραφών</li>` |

(`'shift_reminders'` array key and `shift_reminder_hours` setting key are identifiers — unchanged. Only the `'label'`/`'desc'` string values change.)

- [ ] **Step 2: Edit `role-form.php`**

| Line | Old | New |
|---|---|---|
| 149 | `placeholder="π.χ. Επικεφαλής Βάρδιας" required maxlength="100">` | `placeholder="π.χ. Επικεφαλής Κύκλου Εγγραφών" required maxlength="100">` |

- [ ] **Step 3: Edit `includes/export-functions.php`**

| Line | Old | New |
|---|---|---|
| 101 | `'Βάρδιες',` | `'Κύκλοι Εγγραφών',` |
| 311 | `'Έναρξη Βάρδιας',` | `'Έναρξη Κύκλου Εγγραφών',` |
| 312 | `'Λήξη Βάρδιας',` | `'Λήξη Κύκλου Εγγραφών',` |

- [ ] **Step 4: Edit `includes/newsletter-functions.php`**

| Line | Old | New |
|---|---|---|
| 71 | `ROLE_SHIFT_LEADER      => 'Αρχηγός Βάρδιας',` | `ROLE_SHIFT_LEADER      => 'Αρχηγός Κύκλου Εγγραφών',` |

(`ROLE_SHIFT_LEADER` constant name is an identifier — unchanged. Only the display-label value changes. Note: this role label may also appear in `config.php`'s `ROLE_LABELS` or elsewhere — if a grep for `'Αρχηγός Βάρδιας'` at verification time turns up additional occurrences beyond this one, fix those too using the same mapping.)

- [ ] **Step 5: Lint**

Run: `C:\xampp\php\php.exe -l settings.php && C:\xampp\php\php.exe -l role-form.php && C:\xampp\php\php.exe -l includes/export-functions.php && C:\xampp\php\php.exe -l includes/newsletter-functions.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Commit**

```bash
git add settings.php role-form.php includes/export-functions.php includes/newsletter-functions.php
git commit -m "Rename shift wording in settings, role labels, and CSV export headers"
```

---

### Task 6: Fix live-stored template/achievement/notification text (new migration 79)

**Files:**
- Modify: `includes/migrations.php` (add new migration entry after version 78, before the closing `];`)
- Modify: `config.php:15`

**Interfaces:** None — this is a data-only migration (UPDATE statements against existing rows), no new columns/tables.

**Context:** `includes/migrations.php`'s *existing* entries that originally seeded `achievements`/`email_templates`/`notification_settings` rows have already run — editing their PHP source now would not change anything already stored in the database (migrations only run once, and won't re-run since `DB_SCHEMA_VERSION` already exceeds their version numbers). Per this session's established policy, those old entries are left untouched as historical record. To actually fix what users see today, this task adds a **new** migration that directly `UPDATE`s the current rows. The exact current values (confirmed via direct DB query) are:

- [ ] **Step 1: Add migration 79 to `includes/migrations.php`**

Insert this new array element after the version-78 entry (added in an earlier project this session) and before the closing `];` of the `$migrations` array:

```php
        [
            'version'     => 79,
            'description' => 'Rename shift wording (Σκέλος/Βάρδια) to Κύκλος Εγγραφών in live-stored achievement/email/notification text',
            'up' => function () {
                $achievementUpdates = [
                    ['first_shift', 'Πρώτος Κύκλος Εγγραφών', 'Ολοκλήρωσε τον πρώτο σου Κύκλο Εγγραφών'],
                    ['shifts_5', '5 Κύκλοι Εγγραφών', 'Ολοκλήρωσε 5 κύκλους εγγραφών'],
                    ['shifts_10', '10 Κύκλοι Εγγραφών', 'Ολοκλήρωσε 10 κύκλους εγγραφών'],
                    ['shifts_25', '25 Κύκλοι Εγγραφών', 'Ολοκλήρωσε 25 κύκλους εγγραφών'],
                    ['shifts_50', '50 Κύκλοι Εγγραφών', 'Ολοκλήρωσε 50 κύκλους εγγραφών'],
                    ['shifts_100', '100 Κύκλοι Εγγραφών', 'Ολοκλήρωσε 100 κύκλους εγγραφών'],
                    ['weekend_warrior', null, 'Ολοκλήρωσε 10 κύκλους εγγραφών Σαββατοκύριακου'],
                    ['night_owl', null, 'Ολοκλήρωσε 10 νυχτερινούς κύκλους εγγραφών'],
                    ['early_bird', null, 'Ολοκλήρωσε 5 κύκλους εγγραφών πριν τις 8:00'],
                ];
                foreach ($achievementUpdates as [$code, $newName, $newDescription]) {
                    if ($newName !== null) {
                        dbExecute("UPDATE achievements SET name = ? WHERE code = ?", [$newName, $code]);
                    }
                    dbExecute("UPDATE achievements SET description = ? WHERE code = ?", [$newDescription, $code]);
                }

                dbExecute(
                    "UPDATE email_templates SET
                     name = 'Υπενθύμιση Κύκλου Εγγραφών',
                     subject = 'Υπενθύμιση: Αύριο έχετε Κύκλο Εγγραφών - {{mission_title}}',
                     body_html = REPLACE(REPLACE(REPLACE(body_html,
                        'Υπενθύμιση Αυριανής Βάρδιας', 'Υπενθύμιση Αυριανού Κύκλου Εγγραφών'),
                        'έχετε μια προγραμματισμένη βάρδια', 'έχετε έναν προγραμματισμένο Κύκλο Εγγραφών'),
                        'Δείτε τη Βάρδια', 'Δείτε τον Κύκλο Εγγραφών')
                     WHERE code = 'shift_reminder'"
                );

                dbExecute(
                    "UPDATE email_templates SET
                     name = 'Ακύρωση Κύκλου Εγγραφών',
                     subject = 'Ακυρώθηκε ο Κύκλος Εγγραφών - {{mission_title}} ({{shift_date}})',
                     body_html = REPLACE(REPLACE(REPLACE(REPLACE(body_html,
                        'Ακύρωση Βάρδιας', 'Ακύρωση Κύκλου Εγγραφών'),
                        'η βάρδια στην οποία είχατε δηλώσει συμμετοχή', 'ο Κύκλος Εγγραφών στον οποίο είχατε δηλώσει συμμετοχή'),
                        '>Βάρδια:<', '>Κύκλος Εγγραφών:<'),
                        'Δείτε Άλλες Βάρδιες', 'Δείτε Άλλους Κύκλους Εγγραφών')
                     WHERE code = 'shift_canceled'"
                );
                dbExecute(
                    "UPDATE email_templates SET body_html = REPLACE(body_html,
                        'σε άλλες διαθέσιμες βάρδιες', 'σε άλλους διαθέσιμους κύκλους εγγραφών')
                     WHERE code = 'shift_canceled'"
                );

                dbExecute(
                    "UPDATE email_templates SET
                     subject = 'Τοποθετηθήκατε σε Κύκλο Εγγραφών - {{mission_title}}',
                     body_html = REPLACE(REPLACE(body_html,
                        'Τοποθέτηση σε Βάρδια', 'Τοποθέτηση σε Κύκλο Εγγραφών'),
                        'στην παρακάτω βάρδια:', 'στον παρακάτω Κύκλο Εγγραφών:')
                     WHERE code = 'admin_added_volunteer'"
                );

                dbExecute(
                    "UPDATE email_templates SET body_html = REPLACE(body_html,
                        'αντικαταστήσετε στη βάρδια:', 'αντικαταστήσετε στον Κύκλο Εγγραφών:')
                     WHERE code = 'shift_swap_requested'"
                );

                dbExecute(
                    "UPDATE email_templates SET body_html = REPLACE(body_html,
                        'αντικατάσταση για τη βάρδια εγκρίθηκε', 'αντικατάσταση για τον Κύκλο Εγγραφών εγκρίθηκε')
                     WHERE code = 'shift_swap_approved'"
                );

                $notificationUpdates = [
                    ['participation_approved', null, 'Όταν εγκρίνεται η αίτηση συμμετοχής σε Κύκλο Εγγραφών'],
                    ['shift_reminder', 'Υπενθύμιση Κύκλου Εγγραφών', 'Μία μέρα πριν τον Κύκλο Εγγραφών'],
                    ['shift_canceled', 'Ακύρωση Κύκλου Εγγραφών', 'Όταν ακυρώνεται Κύκλος Εγγραφών'],
                    ['admin_added_volunteer', null, 'Όταν ο διαχειριστής προσθέτει μέλους απευθείας σε Κύκλο Εγγραφών'],
                    ['shift_swap_requested', 'Αίτημα αντικατάστασης Κύκλου Εγγραφών (προς αντικατάστατη)', null],
                ];
                foreach ($notificationUpdates as [$code, $newName, $newDescription]) {
                    if ($newName !== null) {
                        dbExecute("UPDATE notification_settings SET name = ? WHERE code = ?", [$newName, $code]);
                    }
                    if ($newDescription !== null) {
                        dbExecute("UPDATE notification_settings SET description = ? WHERE code = ?", [$newDescription, $code]);
                    }
                }
            },
        ],
```

- [ ] **Step 2: Bump the schema version constant**

In `config.php:15`, change `define('DB_SCHEMA_VERSION', 78);` to `define('DB_SCHEMA_VERSION', 79);`

- [ ] **Step 3: Lint**

Run: `C:\xampp\php\php.exe -l includes/migrations.php && C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Trigger the migration and verify against the live DB**

Load any page in the browser so `runSchemaMigrations()` fires, then verify:

```bash
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "USE easyride; SELECT setting_value FROM settings WHERE setting_key='db_schema_version';"
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "USE easyride; SELECT code, name FROM achievements WHERE code IN ('shifts_5','shifts_100','first_shift');"
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "USE easyride; SELECT code, name, subject FROM email_templates WHERE code IN ('shift_reminder','shift_canceled','admin_added_volunteer');"
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "USE easyride; SELECT code, name, description FROM notification_settings WHERE code IN ('shift_reminder','shift_canceled');"
```

Expected: schema version is `79`; `shifts_5`/`shifts_100` names read "5 Κύκλοι Εγγραφών"/"100 Κύκλοι Εγγραφών"; `first_shift` reads "Πρώτος Κύκλος Εγγραφών"; `shift_reminder`/`shift_canceled` template names and subjects use "Κύκλο(υ) Εγγραφών" wording, no "Βάρδι-" remaining; `notification_settings` names/descriptions likewise updated. Also spot-check `body_html` for `shift_reminder`/`shift_canceled`/`admin_added_volunteer`/`shift_swap_requested`/`shift_swap_approved` no longer contains "Βάρδι" (`SELECT code FROM email_templates WHERE body_html LIKE '%άρδι%'` should return no rows for these five codes).

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php
git commit -m "Add migration 79: fix live-stored shift wording in achievements/emails/notifications"
```

---

### Task 7: Cron reminder text and one-off seed/utility scripts

**Files:**
- Modify: `cron_shift_reminders.php:75,76`
- Modify: `seed_email_data.php:37,38,40,61,63,64,93,95`
- Modify: `restore_notifications.php:13,15`
- Modify: `fix_schema_sql.php:56,81,89,101,165,169,344,346`
- Modify: `import_email_templates.php:67,89,101,165,169,344,346`
- Modify: `install.php:851,909`
- Modify: `cleanup_test_data.php:254,268`

**Interfaces:** None — string literal edits only. These are one-off admin/dev utility scripts (not part of the automatic migration runner) — they only affect behavior if an admin manually re-runs them in the future, but are updated for consistency.

- [ ] **Step 1: Edit `cron_shift_reminders.php`**

| Line | Old | New |
|---|---|---|
| 75 | `'Υπενθύμιση Βάρδιας', ` | `'Υπενθύμιση Κύκλου Εγγραφών', ` |
| 76 | `"Σε {$reminderHours} ώρες έχετε βάρδια στην αποστολή '{$shift['mission_title']}' στις " . date('H:i', strtotime($shift['start_time']))` | `"Σε {$reminderHours} ώρες έχετε Κύκλο Εγγραφών στην αποστολή '{$shift['mission_title']}' στις " . date('H:i', strtotime($shift['start_time']))` |

- [ ] **Step 2: Edit `seed_email_data.php`**

Apply this mapping wherever it occurs in the file (lines 37, 38, 40, 61, 63, 64, 93, 95 per the audit — verify each with a fresh grep for "Βάρδι" in this file before editing, since exact line numbers may shift slightly):

| Old fragment | New fragment |
|---|---|
| `'name' => 'Υπενθύμιση Βάρδιας',` | `'name' => 'Υπενθύμιση Κύκλου Εγγραφών',` |
| `'subject' => 'Υπενθύμιση: Αύριο έχετε βάρδια - {{mission_title}}',` | `'subject' => 'Υπενθύμιση: Αύριο έχετε Κύκλο Εγγραφών - {{mission_title}}',` |
| `<p>Σας υπενθυμίζουμε ότι αύριο έχετε βάρδια.</p>` | `<p>Σας υπενθυμίζουμε ότι αύριο έχετε Κύκλο Εγγραφών.</p>` |
| `'description' => 'Αποστέλλεται την προηγούμενη μέρα της βάρδιας',` | `'description' => 'Αποστέλλεται την προηγούμενη μέρα του Κύκλου Εγγραφών',` |
| `'name' => 'Ακύρωση Βάρδιας',` | `'name' => 'Ακύρωση Κύκλου Εγγραφών',` |
| `'subject' => 'Ακυρώθηκε η βάρδια: {{shift_date}} - {{mission_title}}',` | `'subject' => 'Ακυρώθηκε ο Κύκλος Εγγραφών: {{shift_date}} - {{mission_title}}',` |
| `<h1>Ακύρωση Βάρδιας</h1>` | `<h1>Ακύρωση Κύκλου Εγγραφών</h1>` |
| `<p>Η βάρδια στις {{shift_date}} ({{shift_time}}) για την αποστολή {{mission_title}} ακυρώθηκε.</p>` | `<p>Ο Κύκλος Εγγραφών στις {{shift_date}} ({{shift_time}}) για την αποστολή {{mission_title}} ακυρώθηκε.</p>` |
| `'description' => 'Αποστέλλεται σε μέλη όταν ακυρώνεται βάρδια',` | `'description' => 'Αποστέλλεται σε μέλη όταν ακυρώνεται Κύκλος Εγγραφών',` |
| `['code' => 'shift_reminder', 'name' => 'Υπενθύμιση Βάρδιας', 'description' => 'Μία μέρα πριν τη βάρδια', 'enabled' => 1],` | `['code' => 'shift_reminder', 'name' => 'Υπενθύμιση Κύκλου Εγγραφών', 'description' => 'Μία μέρα πριν τον Κύκλο Εγγραφών', 'enabled' => 1],` |
| `['code' => 'shift_canceled', 'name' => 'Ακύρωση Βάρδιας', 'description' => 'Όταν ακυρώνεται βάρδια', 'enabled' => 1],` | `['code' => 'shift_canceled', 'name' => 'Ακύρωση Κύκλου Εγγραφών', 'description' => 'Όταν ακυρώνεται Κύκλος Εγγραφών', 'enabled' => 1],` |

Also fix the `<p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>` label occurring in this file's other email bodies (e.g. `participation_approved`) → `<p><strong>Κύκλος Εγγραφών:</strong> {{shift_date}} ({{shift_time}})</p>`, and `'description' => 'Αποστέλλεται όταν εγκρίνεται η συμμετοχή μέλους σε βάρδια'` → `'...σε Κύκλο Εγγραφών'`, and `<p>Η συμμετοχή σας στη βάρδια εγκρίθηκε!</p>` → `<p>Η συμμετοχή σας στον Κύκλο Εγγραφών εγκρίθηκε!</p>`.

- [ ] **Step 3: Edit `restore_notifications.php`**

| Line | Old | New |
|---|---|---|
| 13 | `[13, 'shift_reminder', 'Υπενθύμιση Βάρδιας', 'Μία μέρα πριν τη βάρδια', 1],` | `[13, 'shift_reminder', 'Υπενθύμιση Κύκλου Εγγραφών', 'Μία μέρα πριν τον Κύκλο Εγγραφών', 1],` |
| 15 | `[15, 'shift_canceled', 'Ακύρωση Βάρδιας', 'Όταν ακυρώνεται βάρδια', 1],` | `[15, 'shift_canceled', 'Ακύρωση Κύκλου Εγγραφών', 'Όταν ακυρώνεται Κύκλος Εγγραφών', 1],` |

- [ ] **Step 4: Edit `fix_schema_sql.php` and `import_email_templates.php`**

Both files contain near-duplicate email template definitions. Apply the same substitutions as Step 2 (`seed_email_data.php`) wherever the equivalent lines appear — specifically the `<p><strong>Βάρδια:</strong> ...</p>` label (→ `<p><strong>Κύκλος Εγγραφών:</strong> ...</p>`), `'name' => 'Υπενθύμιση Βάρδιας'` / `'name' => 'Ακύρωση Βάρδιας'` (→ `'Υπενθύμιση Κύκλου Εγγραφών'` / `'Ακύρωση Κύκλου Εγγραφών'`), the `<h1>❌ Ακύρωση Βάρδιας</h1>` heading in `import_email_templates.php` (→ `<h1>❌ Ακύρωση Κύκλου Εγγραφών</h1>`), and the `['code' => 'shift_reminder', ...]` / `['code' => 'shift_canceled', ...]` array rows (same mapping as Step 2's last two rows). Grep each file for "Βάρδι" before editing to confirm exact current line numbers, since both files may have shifted slightly from the audit's line references.

- [ ] **Step 5: Edit `install.php`**

| Line | Old | New |
|---|---|---|
| 851 | `<div class="small"><strong>8+</strong> Βάρδιες</div>` | `<div class="small"><strong>8+</strong> Κύκλοι Εγγραφών</div>` |
| 909 | `<li>Βάρδιες με αιτήσεις συμμετοχής</li>` | `<li>Κύκλοι Εγγραφών με αιτήσεις συμμετοχής</li>` |

- [ ] **Step 6: Edit `cleanup_test_data.php`**

| Line | Old | New |
|---|---|---|
| 254 | `onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν ΜΟΝΙΜΑ <?= count($testMissions) ?> αποστολές και ΟΛΑ τα σχετικά δεδομένα (βάρδιες, συμμετοχές, εργασίες). Είστε σίγουροι;');">` | `onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν ΜΟΝΙΜΑ <?= count($testMissions) ?> αποστολές και ΟΛΑ τα σχετικά δεδομένα (κύκλοι εγγραφών, συμμετοχές, εργασίες). Είστε σίγουροι;');">` |
| 268 | `<th>Βάρδιες</th>` | `<th>Κύκλοι Εγγραφών</th>` |

- [ ] **Step 7: Lint**

Run `C:\xampp\php\php.exe -l` on all seven touched files individually.
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Commit**

```bash
git add cron_shift_reminders.php seed_email_data.php restore_notifications.php fix_schema_sql.php import_email_templates.php install.php cleanup_test_data.php
git commit -m "Rename shift wording in cron reminders and seed/utility scripts"
```

---

### Task 8: Smoke tests, gender-agreement verification, version bump, and release

**Files:**
- Modify: `test_app.php:87,88,90,141`
- Modify: `test_full.php:427,1233`
- Modify: `config.php:14`
- Modify: `README.md`
- Modify: `ROADMAP.md`

**Interfaces:** None.

- [ ] **Step 1: Edit `test_app.php`**

| Line | Old | New |
|---|---|---|
| 87 | `['url' => '/mission-view.php?id=11', 'name' => 'Mission View', 'expect' => 'Βάρδιες'],` | `['url' => '/mission-view.php?id=11', 'name' => 'Mission View', 'expect' => 'Κύκλοι Εγγραφών'],` |
| 88 | `['url' => '/shifts.php', 'name' => 'Shifts List', 'expect' => 'Βάρδιες'],` | `['url' => '/shifts.php', 'name' => 'Shifts List', 'expect' => 'Κύκλοι Εγγραφών'],` |
| 90 | `['url' => '/shift-form.php?mission_id=11', 'name' => 'New Shift Form', 'expect' => 'Νέα Βάρδια'],` | `['url' => '/shift-form.php?mission_id=11', 'name' => 'New Shift Form', 'expect' => 'Νέος Κύκλος Εγγραφών'],` |
| 141 | `if ($mission['success'] && strpos($mission['body'], 'Βάρδιες') !== false) {` | `if ($mission['success'] && strpos($mission['body'], 'Κύκλοι Εγγραφών') !== false) {` |

- [ ] **Step 2: Edit `test_full.php`**

| Line | Old | New |
|---|---|---|
| 427 | `if ($response['success'] && strpos($response['body'], 'Βάρδιες') !== false) {` | `if ($response['success'] && strpos($response['body'], 'Κύκλοι Εγγραφών') !== false) {` |
| 1233 | `strpos($response['body'], 'Βάρδιες') !== false) {  // Redirected to shifts for admin` | `strpos($response['body'], 'Κύκλοι Εγγραφών') !== false) {  // Redirected to shifts for admin` |

- [ ] **Step 3: Run the gender-agreement checker**

Write a throwaway script (do not commit) that scans every PHP file for "Κύκλο" preceded or followed within a few words by an unambiguous wrong-gender article, e.g.:

```bash
grep -rnE "(το|τα) Κύκλ|(το|τα) κύκλ|(η|τη|την|τις) Κύκλ|(η|τη|την|τις) κύκλ" --include="*.php" C:/Users/user/Desktop/easyride
```

Expected: no matches. If any are found, fix the specific line (the article, not the noun, is wrong) and re-run.

- [ ] **Step 4: Confirm no "Σκέλ"/"Βάρδι" remain outside excluded scope**

```bash
grep -rn "Σκέλ\|Βάρδι" --include="*.php" C:/Users/user/Desktop/easyride
```

Expected: zero matches, EXCEPT `seed_questions_run.php`'s anatomical "σκέλη" (legs, unrelated meaning — must remain) and any already-applied historical migration entries inside `includes/migrations.php` predating version 79 (immutable, must remain). Every other match is a miss from Tasks 1–7 — go back and fix it.

- [ ] **Step 5: Manual/CLI walkthrough**

Verify in the browser or via a CLI script hitting the DB directly: `shifts.php` list page, `shift-form.php` (both new and edit), `shift-view.php` detail page, `shift-calendar.php`, one rendered `shift_reminder` email (via the updated `email_templates` row from Task 6), and confirm `mission-view.php`'s multi-day "Ημέρα X" badges (from the earlier multi-day-mission feature) are visually unaffected and unambiguous next to the new "Κύκλος Εγγραφών" wording.

- [ ] **Step 6: Bump `APP_VERSION`**

In `config.php:14`, change `define('APP_VERSION', '3.83.0');` to `define('APP_VERSION', '3.84.0');`

- [ ] **Step 7: Update `README.md`**

Update the version line (`**Version:** 3.83.0` → `3.84.0`) and the version badge (`3.83.0-blue` → `3.84.0-blue`), and add a new entry at the top of "Current Release Highlights":

```markdown

### v3.84.0

- Renamed the inconsistently-used "Σκέλος"/"Βάρδια" wording for a mission's registration/capacity-limited time-slot to a single consistent term, "Κύκλος Εγγραφών", across every page, email, and notification — a pure wording change with no effect on the underlying signup/capacity/swap/attendance functionality.
```

- [ ] **Step 8: Update `ROADMAP.md`**

Add a new entry at the top of the "Deployed" section:

```markdown

### v3.84.0 — "Σκέλος"/"Βάρδια" → "Κύκλος Εγγραφών"
- Ενοποίηση δύο ασυνεπών όρων ("Σκέλος", "Βάρδια") που σήμαιναν το ίδιο πράγμα (χρονικό παράθυρο εγγραφής μελών με όριο συμμετεχόντων) σε έναν: "Κύκλος Εγγραφών". Καθαρά αλλαγή λεκτικού σε όλη την εφαρμογή (σελίδες, emails, ειδοποιήσεις, achievements) — καμία αλλαγή στη λειτουργικότητα, στα ονόματα αρχείων/πινάκων, ή στα URLs.
- Spec: [docs/superpowers/specs/2026-07-07-shift-to-signup-cycle-rename-design.md](docs/superpowers/specs/2026-07-07-shift-to-signup-cycle-rename-design.md)
```

- [ ] **Step 9: Lint**

Run: `C:\xampp\php\php.exe -l test_app.php && C:\xampp\php\php.exe -l test_full.php && C:\xampp\php\php.exe -l config.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 10: Commit**

```bash
git add test_app.php test_full.php config.php README.md ROADMAP.md
git commit -m "Update smoke tests and bump version for shift-to-signup-cycle rename release"
```

- [ ] **Step 11: Sync and release**

Per this project's established release workflow (see persistent memory `easyride-release-workflow`):

```bash
robocopy "C:\Users\user\Desktop\easyride" "C:\xampp\htdocs\easyride" /MIR /XD .git backups exports uploads /XF config.local.php update.log
git tag -a v3.84.0 -m "v3.84.0"
git push origin main
git push origin v3.84.0
gh release create v3.84.0 --repo TheoSfak/easyrider --title "EasyRide v3.84.0" --notes "Unified two inconsistent Greek terms for a mission's signup time-slot ('Σκέλος'/'Βάρδια') into one consistent term, 'Κύκλος Εγγραφών', across the whole app. Wording-only change — no functional impact."
```

**Do not omit `/XD backups exports uploads` this time** — a prior release in this session ran `/MIR` without it and permanently deleted a live backup and the site's active logo file.
