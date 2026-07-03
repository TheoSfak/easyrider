# EasyRide

**EasyRide** is a PHP/MySQL web application for motorcycle clubs that organise rides, events, member subscriptions, road partners, and live ride operations.

The app is built as a plain PHP project without a framework, so it can run on common shared hosting, XAMPP, or a standard Apache/PHP/MySQL production server.

**Version:** 3.79.0  
**Author:** Theodore Sfakianakis  
**Repository:** https://github.com/TheoSfak/easyrider

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![Version](https://img.shields.io/badge/version-3.79.0-blue)

---

## What EasyRide Does

EasyRide helps a motorcycle club manage the full operational flow around rides:

- create and publish club rides or actions
- add members to rides
- design a route on a map
- define route stops and timeline
- send Ride Mode links to participants
- view live GPS points during the ride
- receive SOS / help / breakdown signals
- chat with ride participants
- keep member subscriptions organised
- manage road partners such as garages, parts shops, and roadside support
- close a ride with an after-ride report

---

## Core Modules

### Ride / Action Management

- Draft, open, closed, completed, and canceled ride states
- Ride title, type, location, description, notes, responsible user, start and end time
- Route map with points and polyline
- Route timeline with stop duration and notes
- Google Maps navigation link for mobile use
- Participant management from the ride page
- Ride closure workflow with summary and debrief

### Ride Mode

Mobile-first ride screen for participants.

- GPS sharing while the screen is active
- status buttons for on the way, at stop, OK, help, breakdown, left behind
- SOS-style incident creation
- wake lock support where the browser allows it
- live participant list
- nearby partner assistance
- start checklist for road captain/admin

### Live Operations

Operational dashboard for admins and responsible users.

- live map with member GPS points
- optional route visibility toggle per viewer
- attendance / participant status cards
- participant-only chat
- route map and timeline in the operational layout
- active incidents and ride status overview

### Ride Reports

- after-ride report page
- GPS usage summary
- incidents and resolved incidents
- checklist completion
- participant and route information
- closure notes from admin

### Members & Subscriptions

- member list
- candidate members
- subscription list
- subscription types with duration in months
- expiry tracking
- partner subscriptions
- source tracking for partner-created subscriptions

### Road Partners

Partner directory for garages, parts shops, roadside help, and other useful road contacts.

- partner type
- contact details
- map coordinates
- subscription status
- visible in Ride Mode as nearby assistance points

### Notifications & Email

- in-app notifications
- email templates
- notification preferences
- Ride Mode link email
- ride completion / report notification
- subscription expiry notifications

### Admin & Settings

- role and permission management
- app logo and PWA settings
- database migrations
- update page connected to GitHub releases
- backup and restore tools
- audit log

---

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.0+ |
| Database | MySQL / MariaDB |
| Frontend | Bootstrap 5, Bootstrap Icons |
| Maps | Leaflet / OpenStreetMap, Google Maps links |
| Auth | PHP sessions |
| Email | SMTP / PHPMailer |
| Updates | GitHub releases |

---

## Requirements

- PHP 8.0 or newer
- MySQL 8.0 or MariaDB
- Apache or Nginx
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`
- Optional: `zip` for backup/export/update workflows
- HTTPS in production, especially for PWA and geolocation features

---

## Local Development

Recommended local path on Windows/XAMPP:

```text
C:\xampp\htdocs\easyride
```

Local URL:

```text
http://localhost/easyride
```

The local configuration file is:

```text
config.local.php
```

This file is ignored by Git because it contains database credentials.

---

## Production Deployment

1. Upload the project files to the production web root.
2. Create a MySQL database.
3. Create `config.local.php` with production DB credentials.
4. Import the current database or run the installer/migrations depending on the deployment method.
5. Make sure these paths are not publicly writable except where required:
   - `uploads/`
   - `backups/`
6. Use HTTPS.
7. Log in as admin and check:
   - Settings
   - Email / SMTP
   - PWA install icon
   - Update page
   - Cron jobs

---

## GitHub Updates

The built-in updater is configured to read releases from:

```text
TheoSfak/easyrider
```

Release flow:

```bash
git add .
git commit -m "Release EasyRide vX.Y.Z"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin main
git push origin vX.Y.Z
gh release create vX.Y.Z --repo TheoSfak/easyrider --title "EasyRide vX.Y.Z" --notes "Release notes..."
```

The production update page checks the latest GitHub release and downloads the release zipball.

---

## Important Git-Ignored Files

These are intentionally not committed:

```text
config.local.php
.private/
backups/
uploads/
*.log
vendor/
node_modules/
```

Do not commit production credentials, database dumps with real data, uploaded member files, or backup archives.

---

## Database

Main schema:

```text
sql/schema.sql
```

Runtime migrations:

```text
includes/migrations.php
```

Current schema version:

```text
71
```

---

## Main Files

| File | Purpose |
| --- | --- |
| `config.php` | app constants and version |
| `config.local.php` | local/production DB credentials, ignored by Git |
| `bootstrap.php` | loads config, DB, auth, helpers, migrations |
| `mission-view.php` | ride/action detail page |
| `mission-form.php` | create/edit ride/action |
| `ride-mode.php` | mobile ride mode |
| `ride-report.php` | ride report |
| `ops-dashboard.php` | live operational dashboard |
| `partners.php` | road partners |
| `settings.php` | admin settings |
| `update.php` | GitHub release updater |
| `includes/ride-functions.php` | Ride Mode helpers |
| `includes/migrations.php` | DB migrations |

---

## Cron Jobs

Recommended production cron jobs:

| Script | Purpose | Schedule |
| --- | --- | --- |
| `cron_daily.php` | general daily housekeeping | daily |
| `cron_shift_reminders.php` | ride/action reminders | hourly |
| `cron_incomplete_missions.php` | overdue action reminders | daily |
| `cron_task_reminders.php` | task reminders if task module is used | daily |

Subscription expiry notifications should be kept enabled from settings if the club uses annual/monthly subscriptions.

Example:

```bash
php /path/to/easyride/cron_daily.php
```

---

## Notes For Production

- Geolocation in browsers works best on HTTPS.
- PWA install icon uses the app logo assets.
- Mobile GPS cannot run indefinitely in the background like a native Android/iOS app. Ride Mode is designed to work while the page is open and the screen remains active.
- Always take a file and database backup before using the updater on production.

---

## Current Release Highlights

### v3.79.0

- production-ready EasyRide identity for motorcycle clubs
- Ride Mode for motorcycle club rides
- live GPS pings and SOS-style statuses
- operational dashboard map and participant chat
- route drawing, route timeline, and Google Maps navigation
- ride start checklist
- ride report and close-ride workflow
- member subscriptions and subscription types
- road partners module
- GitHub updater now points to `TheoSfak/easyrider`

---

## Support

For code issues, use:

```text
https://github.com/TheoSfak/easyrider/issues
```
