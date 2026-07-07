# "Σκέλος"/"Βάρδια" → "Κύκλος Εγγραφών" UI Wording Rename

## Purpose

EasyRide currently labels a mission's registration/capacity-limited time-slot as "Σκέλος"/"σκέλη" **or** "Βάρδια"/"βάρδιες" — both words refer to the exact same `shifts` table concept (a time window within a mission that members sign up for, with min/max capacity, swap requests, attendance tracking, and achievement milestones), used inconsistently across the codebase. "Βάρδια" is in fact the dominant term (an initial audit for "Σκέλ" alone found only 9 files; a follow-up audit for "Βάρδι" found 34 files and 221 occurrences, including the real page heading on `shift-form.php` and the live cron reminder email text). Both are rescue-org/duty-roster-era terminology that doesn't fit the motorcycle-club product the app has become.

Earlier this session a *separate and unrelated* feature — multi-day mission itineraries (`mission_days`) — started displaying "Ημέρα 1", "Ημέρα 2" badges for per-calendar-day route/overnight info. If "σκέλος"/"βάρδια" were renamed to "Ημέρα" (the literal translation of "shift" a user might reach for), a multi-day mission would show two different things both labeled "Ημέρα 1" — the day's route badge and the day's registration slot — which would be genuinely confusing. This project instead renames both existing words to **"Κύκλος Εγγραφών"**, a term that carries no collision with the day/itinerary concept.

## Scope

**In scope:** every user-facing occurrence of "Σκέλος"/"σκέλη"/"σκέλους"/"σκελών" **and** "Βάρδια"/"βάρδιες"/"βάρδιας"/"βαρδιών" (and any English "shift"/"shifts" display text) across the application — page titles, headers, buttons, labels, badges, flash messages, cron reminder email subject/body text, achievement names, calendar/ICS export text, CSV export headers if any exist. Confirmed audit counts: 9 files for the "Σκέλ-" family, 34 files (221 occurrences) for the "Βάρδι-" family — some overlap expected between the two lists, and some of the 221 occurrences live inside `includes/migrations.php`'s historical seed-data blocks (out of scope, see below) or genuinely unrelated uses of a word (e.g. `seed_questions_run.php` uses "σκέλη" to mean anatomical "legs" in a first-aid quiz question — not the shift concept at all, must not be touched).

**Out of scope:**
- File names (`shifts.php`, `shift-form.php`, `shift-view.php`, `shift-calendar.php`, `shift-swap.php`, `cron_shift_reminders.php`, `api-shifts-calendar.php`, `api-shifts-calendar-ics.php`, etc.) — unchanged.
- The `shifts` database table, its columns, and the `shift_swap_requests` table — unchanged.
- PHP identifiers: variables (`$shift`, `$shiftId`), function names (e.g. anything in `includes/ride-functions.php` referencing shifts), array keys — unchanged.
- URLs/routes — unchanged, no redirects needed since no files move.
- Achievement *codes* stored as literal DB values (`shifts_5`, `shifts_10`, `shifts_25`, `shifts_50`, `shifts_100`) — these are internal lookup keys, invisible to users; only their *display names/descriptions* (if any exist as separate label text) are in scope.
- The `mission_days` / "Ημέρα" feature — entirely untouched, unrelated to this project.
- Already-applied historical migration blocks in `includes/migrations.php` (e.g. the protected `$templateReplacements`-style blocks from prior rebrands) — these are immutable historical records per this session's established policy, left untouched even if they contain "Βάρδι-"/"Σκέλ-" text.
- Non-shift uses of the same words where they mean something else entirely (e.g. anatomical "σκέλη" = legs, in medical/first-aid quiz content) — verified case-by-case during the audit, not mechanically replaced.
- Historical/already-stored audit-log description strings (e.g. `logAudit(..., "Shift $id")`) — only the code that generates *future* log entries is updated if it's easy to do consistently; already-stored rows in the database are not retroactively edited.

This mirrors how the volunteer→member rename's Phase 3 (UI text sweep) worked, but *without* Phases 1–2 (no schema, no file renames) — this project only ever touches human-readable strings.

## Word Mapping

Both source words become "Κύκλος Εγγραφών" (masculine: ο Κύκλος Εγγραφών / οι Κύκλοι Εγγραφών). Every article, adjective, and pronoun referencing either source word must flip gender accordingly — this exact class of bug (gender mismatch left over from a word-only substitution) caused real, hard-to-spot issues during the earlier volunteer→member rename, so it gets explicit attention here rather than a blind find-and-replace. There are now *two* source genders to watch for: neuter ("σκέλος") and feminine ("βάρδια"), both converging on one masculine target.

**"Σκέλος" (neuter: το σκέλος / τα σκέλη):**

| Case | Old (σκέλος, neuter) | New (Κύκλος Εγγραφών, masculine) |
|---|---|---|
| Nominative singular | το σκέλος | ο Κύκλος Εγγραφών |
| Nominative plural | τα σκέλη | οι Κύκλοι Εγγραφών |
| Genitive singular | του σκέλους | του Κύκλου Εγγραφών |
| Genitive plural | των σκελών | των Κύκλων Εγγραφών |
| Accusative singular | το σκέλος | τον Κύκλο Εγγραφών |
| Accusative plural | τα σκέλη | τους Κύκλους Εγγραφών |

**"Βάρδια" (feminine: η βάρδια / οι βάρδιες):**

| Case | Old (βάρδια, feminine) | New (Κύκλος Εγγραφών, masculine) |
|---|---|---|
| Nominative singular | η βάρδια | ο Κύκλος Εγγραφών |
| Nominative plural | οι βάρδιες | οι Κύκλοι Εγγραφών |
| Genitive singular | της βάρδιας | του Κύκλου Εγγραφών |
| Genitive plural | των βαρδιών | των Κύκλων Εγγραφών |
| Accusative singular | τη/την βάρδια | τον Κύκλο Εγγραφών |
| Accusative plural | τις βάρδιες | τους Κύκλους Εγγραφών |

"Εγγραφών" is a fixed modifier (like "Υπουργείο Εξωτερικών") and does not itself inflect with case — only "Σκέλος"/"Βάρδια"/"Κύκλος" and its surrounding article/adjective do.

Adjective forms built on either root (e.g. "σκελικ-", or phrases like "υπεύθυνος βάρδιας" = "shift lead") get rephrased naturally case-by-case rather than mechanically inflected, matching how the volunteer→member rename handled "εθελοντικ-" adjective forms. For example "υπεύθυνος βάρδιας" (shift lead, itself already masculine — "υπεύθυνος" doesn't change) → "υπεύθυνος Κύκλου Εγγραφών".

Any English "shift"/"shifts" appearing in user-facing text (not code identifiers) becomes "signup cycle"/"signup cycles".

## Approach

1. **Audit**: grep the whole codebase for every human-readable string containing "Σκέλ" or "Βάρδι" (catches all case/number variants of both words) and for English "shift"/"Shift" appearing in HTML/string-literal contexts (not variable/function names or the `shifts`/`shift_swap_requests` identifiers). Confirmed counts: 9 files for "Σκέλ", 34 files / 221 occurrences for "Βάρδι". Build a concrete file-by-file list, excluding the historical-migration and non-shift-meaning false positives noted in Scope — this becomes the implementation plan's task breakdown, similar to how the volunteer→member rename's audit produced its phase list. Given the size, group files into batches (e.g. by feature area: shift CRUD pages, cron/email, dashboards/reports, settings) rather than one file per plan task.
2. **Replace**, file by file: swap the old word for the correctly-cased/gendered new phrase from the mapping tables above, and fix any article/adjective left stranded in the wrong gender.
3. **Verify** via a small checker script (same technique used for the volunteer→member rename): grep every "Κύκλο-" occurrence with its surrounding context and confirm masculine agreement (ο/του/τον/οι/των/τους, not η/της/την/τα/των/τις).

## Testing

No automated test framework exists in this project (established convention). Verification:
1. `php -l` on every touched file.
2. Gender-agreement checker: a throwaway grep/PHP script (not committed) that flags any neuter-only article (το/τα) or feminine-only article (η/τη/την/τις) sitting next to "Κύκλο-" — "του"/"των" are ambiguous across genders so aren't useful mismatch signals by themselves, but the unambiguous markers catch leftover gender mismatches mechanically rather than by rereading every string by eye.
3. Manual/CLI-simulated walkthrough of the main affected screens: `shifts.php` (list), `shift-form.php` (create/edit — the page the original `test_app.php` smoke check targets), `shift-view.php` (detail + roster), `shift-calendar.php`, `shift-swap.php`, `attendance.php`, `checkin.php`, and one rendered cron reminder email body — confirming the new wording reads correctly and no fatal errors appear.
4. Confirm `mission-view.php`, `ride-mode.php`, and `ops-dashboard.php` (the multi-day "Ημέρα" badges added earlier this session) are unaffected — these files may also reference shifts for unrelated reasons (e.g. shift-based participation on a multi-day mission), so any shift-wording changes there must not touch the day-badge text.

## Out of scope

- Any structural/architectural merge of shifts with `mission_days` (considered and explicitly rejected earlier in this session's brainstorming — they are functionally distinct: one is a capacity-limited signup window, the other is per-day route/itinerary data).
- File, URL, database, or PHP-identifier renames.
- The CSV import/export format for shift data (unaffected either way, since this is wording-only).
