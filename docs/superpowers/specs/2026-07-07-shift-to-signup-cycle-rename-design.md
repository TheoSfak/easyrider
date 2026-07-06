# "Σκέλος" → "Κύκλος Εγγραφών" UI Wording Rename

## Purpose

EasyRide currently labels a mission's registration/capacity-limited time-slot as "Σκέλος"/"σκέλη" (the `shifts` table: a time window within a mission that members sign up for, with min/max capacity, swap requests, attendance tracking, and achievement milestones). This is rescue-org-era terminology that doesn't fit the motorcycle-club product the app has become.

Earlier this session a *separate and unrelated* feature — multi-day mission itineraries (`mission_days`) — started displaying "Ημέρα 1", "Ημέρα 2" badges for per-calendar-day route/overnight info. If "σκέλος" were renamed to "Ημέρα" (the literal translation of "shift" a user might reach for), a multi-day mission would show two different things both labeled "Ημέρα 1" — the day's route badge and the day's registration slot — which would be genuinely confusing. This project instead renames "σκέλος" to **"Κύκλος Εγγραφών"**, a term that carries no collision with the day/itinerary concept.

## Scope

**In scope:** every user-facing occurrence of "Σκέλος"/"σκέλη"/"σκέλους"/"σκελών" (and any English "shift"/"shifts" display text) across the application — page titles, headers, buttons, labels, badges, flash messages, cron reminder email subject/body text, achievement names, calendar/ICS export text, CSV export headers if any exist.

**Out of scope:**
- File names (`shifts.php`, `shift-form.php`, `shift-view.php`, `shift-calendar.php`, `shift-swap.php`, `cron_shift_reminders.php`, `api-shifts-calendar.php`, `api-shifts-calendar-ics.php`, etc.) — unchanged.
- The `shifts` database table, its columns, and the `shift_swap_requests` table — unchanged.
- PHP identifiers: variables (`$shift`, `$shiftId`), function names (e.g. anything in `includes/ride-functions.php` referencing shifts), array keys — unchanged.
- URLs/routes — unchanged, no redirects needed since no files move.
- Achievement *codes* stored as literal DB values (`shifts_5`, `shifts_10`, `shifts_25`, `shifts_50`, `shifts_100`) — these are internal lookup keys, invisible to users; only their *display names/descriptions* (if any exist as separate label text) are in scope.
- The `mission_days` / "Ημέρα" feature — entirely untouched, unrelated to this project.

This mirrors how the volunteer→member rename's Phase 3 (UI text sweep) worked, but *without* Phases 1–2 (no schema, no file renames) — this project only ever touches human-readable strings.

## Word Mapping

"Σκέλος" (neuter: το σκέλος / τα σκέλη) becomes "Κύκλος Εγγραφών" (masculine: ο Κύκλος Εγγραφών / οι Κύκλοι Εγγραφών). Every article, adjective, and pronoun referencing it must flip gender accordingly — this exact class of bug (masculine/neuter mismatch left over from a word-only substitution) caused real, hard-to-spot issues during the earlier volunteer→member rename, so it gets explicit attention here rather than a blind find-and-replace.

| Case | Old (σκέλος, neuter) | New (Κύκλος Εγγραφών, masculine) |
|---|---|---|
| Nominative singular | το σκέλος | ο Κύκλος Εγγραφών |
| Nominative plural | τα σκέλη | οι Κύκλοι Εγγραφών |
| Genitive singular | του σκέλους | του Κύκλου Εγγραφών |
| Genitive plural | των σκελών | των Κύκλων Εγγραφών |
| Accusative singular | το σκέλος | τον Κύκλο Εγγραφών |
| Accusative plural | τα σκέλη | τους Κύκλους Εγγραφών |

"Εγγραφών" is a fixed modifier (like "Υπουργείο Εξωτερικών") and does not itself inflect with case — only "Σκέλος"/"Κύκλος" and its surrounding article/adjective do.

Adjective forms built on "σκέλος" (e.g. any phrase using "σκελικ-" as an adjective root, if any exist) get rephrased naturally case-by-case rather than mechanically inflected, matching how the volunteer→member rename handled "εθελοντικ-" adjective forms.

Any English "shift"/"shifts" appearing in user-facing text (not code identifiers) becomes "signup cycle"/"signup cycles".

## Approach

1. **Audit**: grep the whole codebase for every human-readable string containing "Σκέλ" (catches σκέλος/σκέλη/σκέλους/σκελών and any capitalized/lowercase variants) and for English "shift"/"Shift" appearing in HTML/string-literal contexts (not variable/function names or the `shifts`/`shift_swap_requests` identifiers). Build a concrete file-by-file list — this becomes the implementation plan's task breakdown, similar to how the volunteer→member rename's audit produced its phase list.
2. **Replace**, file by file: swap the old word for the correctly-cased/gendered new phrase from the mapping table above, and fix any article/adjective left stranded in the wrong gender.
3. **Verify** via a small checker script (same technique used for the volunteer→member rename): grep every "Κύκλο-" occurrence with its surrounding context and confirm masculine agreement (ο/του/τον/οι/των/τους, not η/της/την/τα/των/τις).

## Testing

No automated test framework exists in this project (established convention). Verification:
1. `php -l` on every touched file.
2. Gender-agreement checker: a throwaway grep/PHP script (not committed) that flags any neuter article (το/τα/της/την) sitting next to "Κύκλο-", so mismatches are caught mechanically rather than by rereading every string by eye.
3. Manual/CLI-simulated walkthrough of the main affected screens: `shifts.php` (list), `shift-form.php` (create/edit), `shift-view.php` (detail + roster), `shift-calendar.php`, `shift-swap.php`, and one rendered cron reminder email body — confirming the new wording reads correctly and no fatal errors appear.
4. Confirm `mission-view.php`, `ride-mode.php`, and `ops-dashboard.php` (the multi-day "Ημέρα" badges added earlier this session) are unaffected — these files may also reference shifts for unrelated reasons (e.g. shift-based participation on a multi-day mission), so any shift-wording changes there must not touch the day-badge text.

## Out of scope

- Any structural/architectural merge of shifts with `mission_days` (considered and explicitly rejected earlier in this session's brainstorming — they are functionally distinct: one is a capacity-limited signup window, the other is per-day route/itinerary data).
- File, URL, database, or PHP-identifier renames.
- The CSV import/export format for shift data (unaffected either way, since this is wording-only).
