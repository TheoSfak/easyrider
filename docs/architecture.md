# Feature modules

EasyRide remains framework-free, but new work and extractions follow this layout:

```text
includes/features/<feature>/
  <Feature>Repository.php  # SQL and persistence
  <Feature>Service.php     # read models and business rules
  <Feature>Controller.php  # HTTP/page orchestration
  templates/               # reusable presentation fragments
```

The feature entry point includes only the classes it needs; there is no implicit autoloader or framework dependency.

## First extraction: missions

`mission-view.php` now delegates its primary mission and itinerary queries to the Mission controller, service, and repository, and renders its page heading through a feature template. Its route, action, and output remain unchanged.

Continue the extraction in this order:

1. Mission actions into explicit command handlers.
2. Mission shifts/chat/debrief read models into `MissionRepository` and `MissionViewService`.
3. Additional `mission-view.php` panels into templates.
4. Apply the same pattern to `mission-form.php`, `settings.php`, and `dashboard.php`.

Avoid adding new SQL directly to page templates or expanding page-level POST switches; add a repository/service method instead.

## Authorization regression suite

Run this read-only CLI suite after changing custom roles or Ride Mode endpoint guards:

```text
php scripts/maintenance/test-authorization.php
```

It verifies every role-editor permission has a guarded entry point, every `requirePermission()` slug is assignable, custom roles cannot become generic administrators, and Ride Mode read/write endpoints retain their access controls.
