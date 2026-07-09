# EasyRide — Visual Identity Revamp — Design

## Purpose

EasyRide currently looks like an unbranded fork of the app it came from (VolunteerOps): a generic purple/indigo SaaS-admin theme (`#1e3c72`→`#2a5298` sidebar gradient, `#667eea` accent, stock Inter font) with zero connection to motorcycling or to this specific club. The club has a real emblem — a circular black-ringed badge, "ΣΥΛΛΟΓΟΣ ΜΟΤΟΣΙΚΛΕΤΙΣΤΩΝ ΣΦΑΚΙΑΝΩΝ" (est. 2009), depicting two riders greeting each other silhouetted against a black→amber→gold sunset gradient. This revamp replaces the generic theme with a design system built from that badge's actual colors and character, applied consistently across the whole app.

Two additional, unrelated-looking issues surfaced during discovery and are folded into this same pass because they're part of the same underlying problem (unbranded placeholder assets never replaced with the real thing):
- The `app_logo` setting is currently set to **ActiveWeb's** logo (the web agency that built the site), not the club's.
- The PWA installable icon set (`assets/icons/icon-*.png`, `icon-maskable-512.png`) is **also** ActiveWeb's logo — meaning a rider who installs EasyRide to their phone's home screen currently sees the web agency's logo, not the club's.

## Scope

**In scope:** a full visual reskin — every page, not just high-visibility chrome — covering colors, typography, the club badge as the real logo/PWA icon, and the design-token architecture needed to keep it consistent and maintainable.

**Explicitly out of scope:**
- No new features, no page restructuring, no information-architecture or layout changes. This is a reskin, not a redesign of how pages are organized or how any feature works.
- No hand-redrawing of individual icon glyphs — Bootstrap Icons (`bootstrap-icons@1.11.1`) stays as the icon library. Swapping icon libraries across the ~100+ files that reference `bi bi-*` classes would be a large, error-prone effort for no visual gain over recoloring/reframing the existing set.
- No dark-mode *toggle* / `prefers-color-scheme` support. The app doesn't have a light/dark mode switch today; this revamp introduces a dark-chrome-plus-light-content look as the one and only theme, not a togglable second theme.
- No regeneration of `downloads/easyride-ridemode.apk`'s in-app icon/splash (the Android companion app is a separate repo/build; out of scope here).

## Visual Language (Design Tokens)

Palette derived directly from the club badge, validated against three mocked-up directions during brainstorming (chosen: "Club Patch Bold" with a "dark chrome, light content" split):

| Token | Value | Use |
|---|---|---|
| `--ez-black` | `#141110` | Sidebar/header background, darkest surface |
| `--ez-black-soft` | `#1c1815` | Secondary dark surface (e.g. hover states within chrome) |
| `--ez-content-bg` | `#fbfaf8` | Main content area background (tables, forms, cards) |
| `--ez-orange` | `#e8792c` | Primary accent — buttons, active states, borders |
| `--ez-amber` | `#f2b134` | Secondary accent — gradient endpoint, highlights, active nav text |
| `--ez-accent-gradient` | `linear-gradient(135deg, #e8792c 0%, #f2b134 100%)` | Buttons, badge rings, hero/brand touches |
| `--ez-text-cream` | `#f2e4d0` | Primary text on dark chrome |
| `--ez-text-muted-dark` | `#d8c4a0` | Secondary/muted text on dark chrome |

Semantic status colors (success/warning/danger, used throughout tables and badges for member/mission status) are **not** replaced — they stay recognizable green/amber/red so existing meaning (e.g. "Ενεργός" vs "Ανενεργός") isn't disrupted; only the *neutral* chrome/accent palette changes.

**Typography:** headings, nav labels, and page titles switch to **Oswald** (free, Google Fonts, condensed/display character — a "road sign" feel), loaded alongside the existing Inter. Body text, table content, and form inputs stay **Inter** for dense-data readability. No component that currently reads comfortably in Inter (tables, forms, long text) switches font.

**Chrome vs. content split:** Sidebar, top header/navbar, buttons, active nav/tab states, and badge-style accents use the dark palette. Cards, tables, forms, and modals keep a light background (`--ez-content-bg` / white) so the app's actual data — member lists, participation tables, settings forms — stays easy to scan. This was validated directly against a mocked-up member table during brainstorming; a fully-dark content area was explicitly rejected for readability reasons.

**Iconography:** Bootstrap Icons stays as the icon set. Where icons currently appear as avatars/stat markers/badges, they get a circular badge-style frame (border + optional gradient ring) echoing the club emblem's circular shape — this is a CSS framing treatment (border-radius, border, background), not new icon assets.

## Technical Architecture

There is currently **no dedicated CSS file anywhere in the app** — every page relies on the Bootstrap 5.3.2 CDN plus ad hoc inline `<style>` blocks. `includes/header.php` has one global inline block (source of the current purple sidebar gradient), but several pages carry their *own* additional local inline blocks with different hardcoded colors (confirmed: the auth pages use an entirely different blue, `#2c3e50`→`#3498db`, not even the same palette as the dashboard).

This revamp introduces a single source of truth:

- **New file: `assets/css/theme.css`** — all tokens above as CSS custom properties on `:root`, plus:
  - Overrides for Bootstrap's own CSS variables where Bootstrap 5.3 consumes them directly (e.g. `--bs-primary` and related `-rgb`/`-text-emphasis`/`-border-subtle` variants).
  - Explicit selector overrides for the Bootstrap components that don't pick up CSS-variable overrides automatically (compiled-Sass defaults) — buttons, badges, nav-pills/nav-tabs active states, form-control focus rings, and any other component whose current look visibly comes from Bootstrap's default blue rather than a CSS var.
  - The sidebar/header component classes currently defined inline in `includes/header.php` (`.sidebar`, `.sidebar-brand`, `.sidebar-section`, `.nav-link` states, `.main-content`), rewritten against the new tokens instead of the old gradient.
- **`includes/header.php`**: links `assets/css/theme.css` (plus the Oswald Google Fonts link, alongside the existing Inter link) and removes its old inline `<style>` block now that the rules live in the shared file.

**Files with their own hardcoded old-palette colors, to be swept and updated** (found via direct search, not assumed complete — implementation should re-grep before finishing):
- Auth-page family sharing the old blue gradient (`#2c3e50`→`#3498db`/`#1a252f` etc.): `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`, `verify-email.php`, `install.php`.
- Old purple/navy (`#667eea`/`#1e3c72`/`#2a5298`/`#764ba2`) hardcoded directly: `dashboard.php`, `member-view.php`, `profile.php`, `includes/footer.php` (PWA install-banner/update-toast inline JS styles — these are transient UI, easy to miss since they only render conditionally), `offline.html`.
- `replay-share.php`'s public page background gradient (currently the same old blue as the auth pages) — bring it in line with the new palette so a publicly-shared link doesn't look like it belongs to a different, older app than the one that generated it.

**Branding assets:**
- `manifest.json`: `theme_color`/`background_color` currently plain white (`#ffffff`/`#ffffff`) — update to the new dark chrome color so the PWA install/splash screen matches.
- `app_logo` setting: currently ActiveWeb's logo — replace with the real club badge.
- `assets/icons/icon-72.png` through `icon-512.png` and `icon-maskable-512.png`: currently ActiveWeb's logo — regenerate from the club badge at each existing size.
- **Dependency/prerequisite:** the club badge was shared as a pasted image in conversation, not a file path — there is no source file in the repo yet. Before implementation can regenerate the logo/icons, the user needs to save the badge image into the repo at `assets/branding/club-badge-source.png` (new directory), kept as the canonical source the logo and all icon sizes are derived from.

## Testing / Verification

No automated visual test tooling exists in this repo (manual verification is the established project convention). Verification plan:
1. `php -l` on every touched `.php` file.
2. Manual browser check (via the existing `easyride-php-dev` preview server / throwaway dev server pattern already used this session) across representative pages: login, dashboard, mission-view (including the Ride Replay modal touched in the prior session), a data-heavy list page (e.g. members), settings, and the public `replay-share.php` page — at both desktop and mobile viewport widths.
3. Confirm no page still renders the old purple/blue palette (re-grep for the old hex codes after implementation; the list above should return no matches, following the same verification pattern used for the earlier volunteer→member rename work).
4. Confirm the PWA install icon and splash screen reflect the new badge/colors (install prompt banner in `includes/footer.php`, `manifest.json`).
