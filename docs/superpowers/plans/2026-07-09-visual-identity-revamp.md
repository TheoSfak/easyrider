# EasyRide Visual Identity Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the app's generic purple/blue SaaS-admin palette (inherited unchanged from the VolunteerOps fork) with a design system built from the club's real badge — a black-ringed, sunset-amber circular emblem — applied consistently across every page.

**Architecture:** A single new stylesheet, `assets/css/theme.css`, becomes the one source of truth for colors/fonts/component styling (there is currently no dedicated CSS file anywhere in the app — everything is inline `<style>` blocks). `includes/header.php` links it and drops its own inline block; every other file with hardcoded old-palette colors (auth pages, dashboard/profile/member pages, the PWA footer banner, the offline fallback page, the public replay-share page) gets swept and updated to reference the new tokens. The club badge replaces the currently-misconfigured logo (`app_logo` is set to the web agency's logo, not the club's) and the PWA icon set.

**Tech Stack:** PHP (no framework, no build step), Bootstrap 5.3.2 + Bootstrap Icons 1.11.1 (both via CDN), plain CSS custom properties (no Sass/PostCSS), GD (PHP's bundled image library, for icon regeneration).

## Global Constraints

- No new features, no page restructuring, no layout/information-architecture changes — this is a full reskin (colors/type/branding), not a redesign of how any page is organized.
- Bootstrap Icons (`bootstrap-icons@1.11.1`) stays as the icon library — no icon glyph replacement, only recoloring/reframing via CSS.
- No dark-mode *toggle* / `prefers-color-scheme` support — the new dark-chrome-plus-light-content look is the one and only theme.
- Semantic status colors (success `#10b981`, warning `#f59e0b`, danger `#ef4444`, and the `info`/`secondary` badge variants) are **not** replaced — only the neutral chrome/accent palette changes.
- No automated test framework exists in this repo (confirmed project convention) — verification is `php -l` plus manual browser checks via the existing `easyride-php-dev` preview server (`.claude/launch.json`, port 8899) or a throwaway `php -S` server.
- No new CSS framework, no build step — plain CSS custom properties only, consistent with the rest of the codebase.
- Bootstrap/Bootstrap Icons/Google Fonts stay CDN-loaded exactly as today; only a new Google Fonts link (Oswald) and the new local stylesheet link are added.

---

### Task 1: Create the central theme stylesheet

**Files:**
- Create: `assets/css/theme.css`

**Interfaces:**
- Produces: all CSS custom properties later tasks reference by name — `--sidebar-width`, `--primary-gradient`, `--primary-gradient-hover`, `--sidebar-gradient`, `--sidebar-gradient-diagonal`, `--accent-color`, `--accent-hover`, `--success-color`, `--warning-color`, `--danger-color`, `--card-shadow`, `--card-shadow-hover`, `--ez-black`, `--ez-black-soft`, `--ez-content-bg`, `--ez-orange`, `--ez-amber`, `--ez-text-cream`, `--ez-text-muted-dark`, `--ez-font-display`, `--auth-bg-gradient`, `--auth-header-gradient`. Also produces the recolored component classes (`.sidebar`, `.btn-primary`, `.card-header`, `.avatar`, etc.) that every page inherits once `includes/header.php` links this file (Task 2).

- [ ] **Step 1: Write `assets/css/theme.css`**

This ports the exact CSS currently inline in `includes/header.php` (lines 67–698), recolored from the old purple/navy palette (`#667eea`/`#764ba2`/`#1e3c72`/`#2a5298`) to the club badge's black/sunset-amber palette (`#141110`/`#1c1815`/`#e8792c`/`#f2b134`), plus a small set of global Bootstrap-variable overrides that the original inline block never covered (plain links, checkboxes, nav-pills/tabs, pagination — these currently leak Bootstrap's stock blue `#0d6efd` even today). All non-color behavior (border-radius, shadows, transitions, responsive breakpoints, the Summernote popup fixes) is preserved unchanged.

```css
/* ==========================================================================
   EasyRide Theme — club badge palette (black + sunset amber/orange)
   Single source of truth for colors/fonts/component styling.
   ========================================================================== */

:root {
    --sidebar-width: 260px;

    /* Brand accent (from the club badge's sunset gradient) */
    --primary-gradient: linear-gradient(135deg, #e8792c 0%, #f2b134 100%);
    --primary-gradient-hover: linear-gradient(135deg, #d4691f 0%, #e0a020 100%);
    --accent-color: #e8792c;
    --accent-hover: #f2b134;

    /* Dark chrome (sidebar/header) — from the badge's black ring */
    --sidebar-gradient: linear-gradient(180deg, #141110 0%, #1c1815 50%, #141110 100%);
    --sidebar-gradient-diagonal: linear-gradient(135deg, #141110 0%, #1c1815 100%);
    --ez-black: #141110;
    --ez-black-soft: #1c1815;
    --ez-text-cream: #f2e4d0;
    --ez-text-muted-dark: #d8c4a0;

    /* Light content area (tables/forms/cards stay readable) */
    --ez-content-bg: #fbfaf8;
    --ez-orange: #e8792c;
    --ez-amber: #f2b134;

    /* Auth pages (login/register/etc.) — moody sunset backdrop, pure branding real estate */
    --auth-bg-gradient: linear-gradient(135deg, #1c1815 0%, #e8792c 100%);
    --auth-header-gradient: linear-gradient(135deg, #2b1d14 0%, #141110 100%);

    /* Semantic status colors — unchanged, do not touch */
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;

    --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --card-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

    /* Typography */
    --ez-font-display: 'Oswald', -apple-system, BlinkMacSystemFont, sans-serif;

    /* Bootstrap's own variables — closes the gap on plain links, checkboxes,
       nav-pills/tabs, and pagination, which the old inline block never covered
       and which otherwise still show Bootstrap's stock blue (#0d6efd). */
    --bs-primary: #e8792c;
    --bs-primary-rgb: 232, 121, 44;
    --bs-link-color: #e8792c;
    --bs-link-color-rgb: 232, 121, 44;
    --bs-link-hover-color: #d4691f;
    --bs-link-hover-color-rgb: 212, 105, 31;
}

.form-check-input:checked {
    background-color: #e8792c;
    border-color: #e8792c;
}

.form-check-input:focus {
    border-color: #f2b134;
    box-shadow: 0 0 0 0.25rem rgba(232, 121, 44, 0.25);
}

.nav-pills .nav-link.active,
.nav-pills .show > .nav-link {
    background-color: #e8792c;
}

.nav-tabs .nav-link.active {
    color: #e8792c;
}

.page-link {
    color: #e8792c;
}

.page-item.active .page-link {
    background-color: #e8792c;
    border-color: #e8792c;
}

* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

body {
    min-height: 100vh;
    background: linear-gradient(135deg, #fbfaf8 0%, #f0ebe3 100%);
}

/* Animated Gradient Sidebar */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sidebar-width);
    height: 100vh;
    background: var(--sidebar-gradient);
    padding-top: 0;
    z-index: 1000;
    overflow-y: auto;
    box-shadow: 4px 0 15px rgba(0, 0, 0, 0.15);
}

.sidebar::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}

.sidebar .nav-link {
    color: rgba(255,255,255,0.85);
    padding: 0.85rem 1.5rem;
    border-radius: 0;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: var(--ez-font-display);
    font-weight: 500;
    font-size: 0.9rem;
    position: relative;
    overflow: hidden;
}

.sidebar .nav-link::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, rgba(255,255,255,0.15) 0%, transparent 100%);
    transition: width 0.3s ease;
}

.sidebar .nav-link:hover {
    color: #fff;
    background: rgba(255,255,255,0.1);
    transform: translateX(5px);
}

.sidebar .nav-link:hover::before {
    width: 100%;
}

.sidebar .nav-link.active {
    color: #fff;
    background: linear-gradient(90deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.05) 100%);
    border-left: 4px solid #f2b134;
    box-shadow: inset 0 0 20px rgba(255,255,255,0.1);
}

.sidebar .nav-link i {
    width: 28px;
    margin-right: 0.75rem;
    font-size: 1.1rem;
}

.sidebar-brand {
    color: #fff;
    font-family: var(--ez-font-display);
    font-size: 1.4rem;
    font-weight: 700;
    padding: 1.5rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: rgba(0,0,0,0.2);
    margin-bottom: 0.5rem;
    letter-spacing: -0.5px;
}

.sidebar-brand:hover {
    color: #fff;
    background: rgba(0,0,0,0.25);
}

.sidebar-section {
    color: rgba(255,255,255,0.5);
    font-family: var(--ez-font-display);
    font-size: 0.7rem;
    text-transform: uppercase;
    padding: 1.25rem 1.5rem 0.5rem;
    letter-spacing: 1.5px;
    font-weight: 600;
}

.main-content {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.content-wrapper {
    flex: 1;
    padding: 2rem;
}

/* Glassmorphism Top Navbar */
.top-navbar {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 1rem 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,0.3);
    position: sticky;
    top: 0;
    z-index: 1020;
}

.top-navbar .dropdown {
    position: relative;
}

.top-navbar .dropdown-menu {
    position: absolute !important;
    right: 0 !important;
    left: auto !important;
    top: 100% !important;
    transform: none !important;
    z-index: 1050 !important;
    min-width: 220px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-radius: 8px;
    border: none;
    margin-top: 0.5rem;
}

.top-navbar .dropdown-toggle {
    font-weight: 500;
}

.top-navbar .dropdown-item {
    padding: 0.6rem 1rem;
}

.top-navbar .dropdown-item:hover {
    background-color: #f7f3ee;
}

/* Modern Cards */
.card {
    border: none;
    border-radius: 16px;
    box-shadow: var(--card-shadow);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--card-shadow-hover);
    transform: translateY(-2px);
}

.card-header {
    background: linear-gradient(135deg, #f7f3ee 0%, #f0e9df 100%);
    border-bottom: 1px solid #e5dccb;
    font-family: var(--ez-font-display);
    font-weight: 600;
    padding: 1rem 1.25rem;
}

.card-header[class*="bg-"] {
    background-image: none;
}

/* Stats Cards with Gradients */
.stats-card {
    border-left: none !important;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
    border-radius: 16px 0 0 16px;
}

.stats-card.primary::before { background: var(--primary-gradient); }
.stats-card.success::before { background: linear-gradient(180deg, #10b981 0%, #059669 100%); }
.stats-card.warning::before { background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%); }
.stats-card.danger::before { background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%); }
.stats-card.info::before { background: linear-gradient(180deg, #06b6d4 0%, #0891b2 100%); }

.stats-card .stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #fff;
}

.stats-card.primary .stats-icon { background: var(--primary-gradient); }
.stats-card.success .stats-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
.stats-card.warning .stats-icon { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.stats-card.danger .stats-icon { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
.stats-card.info .stats-icon { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }

/* Modern Buttons */
.btn {
    border-radius: 10px;
    font-family: var(--ez-font-display);
    font-weight: 500;
    padding: 0.6rem 1.25rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.btn-primary {
    background: var(--primary-gradient);
    border: none;
    box-shadow: 0 4px 15px rgba(232, 121, 44, 0.4);
}

.btn-primary:hover {
    background: var(--primary-gradient-hover);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(232, 121, 44, 0.5);
}

.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
}

.btn-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-2px);
}

.btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    border: none;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
}

.btn-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    border: none;
    color: #fff;
}

.btn-outline-primary {
    border: 2px solid var(--accent-color);
    color: var(--accent-color);
}

.btn-outline-primary:hover {
    background: var(--accent-color);
    border-color: var(--accent-color);
    transform: translateY(-2px);
}

/* Modern Badges */
.badge {
    font-weight: 500;
    padding: 0.5em 0.85em;
    border-radius: 8px;
    font-size: 0.75rem;
    letter-spacing: 0.3px;
}

.badge.bg-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; }
.badge.bg-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; color: #fff !important; }
.badge.bg-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important; }
.badge.bg-primary { background: var(--primary-gradient) !important; }
.badge.bg-info { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important; }
.badge.bg-secondary { background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important; }

/* Modern Tables */
.table {
    border-collapse: separate;
    border-spacing: 0;
}

.table thead th {
    background: linear-gradient(135deg, #f7f3ee 0%, #f0e9df 100%);
    border-bottom: 2px solid #e5dccb;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b5d4d;
    padding: 1rem;
}

.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, #f7f3ee 0%, #fdf1e0 100%);
}

.table td {
    padding: 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0e9df;
}

/* Modern Form Controls */
.form-control, .form-select {
    border-radius: 10px;
    border: 2px solid #e5dccb;
    padding: 0.7rem 1rem;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 4px rgba(232, 121, 44, 0.15);
}

.form-label {
    font-weight: 500;
    color: #3d342a;
    margin-bottom: 0.5rem;
}

/* Modern Alerts */
.alert {
    border: none;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    box-shadow: var(--card-shadow);
}

.alert-success {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.1) 100%);
    color: #047857;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.1) 100%);
    color: #b91c1c;
    border-left: 4px solid #ef4444;
}

.alert-warning {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
    color: #92400e;
    border-left: 4px solid #f59e0b;
}

.alert-info {
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(8, 145, 178, 0.1) 100%);
    color: #0e7490;
    border-left: 4px solid #06b6d4;
}

/* Page Headings */
h1, .h1, h2, .h2, h3, .h3, h4, .h4, h5, .h5, h6, .h6 {
    font-family: var(--ez-font-display);
    font-weight: 700;
    color: #231a12;
    letter-spacing: -0.5px;
}

/* Breadcrumb */
.breadcrumb {
    background: transparent;
    padding: 0;
    margin: 0;
}

.breadcrumb-item a {
    color: var(--accent-color);
    text-decoration: none;
}

.breadcrumb-item.active {
    color: #8a7a66;
}

/* Avatar — circular, badge-style ring (echoes the club emblem's shape) */
.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary-gradient);
    border: 2px solid #f2b134;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
    font-size: 1rem;
}

/* Dropdown */
.dropdown-menu {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    padding: 0.5rem;
}

.dropdown-item {
    border-radius: 8px;
    padding: 0.6rem 1rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

.dropdown-item:hover {
    background: linear-gradient(135deg, #f7f3ee 0%, #fdf1e0 100%);
}

/* Progress bars */
.progress {
    border-radius: 10px;
    height: 10px;
    background: #e5dccb;
}

.progress-bar {
    border-radius: 10px;
    background: var(--primary-gradient);
}

/* Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card, .alert, .stats-card {
    animation: fadeInUp 0.4s ease-out;
}

/* Scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f0e9df;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #e8792c 0%, #f2b134 100%);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #d4691f 0%, #e0a020 100%);
}

/* Mobile sidebar toggle */
.sidebar-toggle {
    display: none;
}

@media (max-width: 991.98px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
    }

    .sidebar-toggle {
        display: block;
    }

    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }

    .sidebar-overlay.show {
        display: block;
    }

    .main-content {
        overflow-x: hidden;
        max-width: 100vw;
    }

    .top-navbar {
        padding: 0.6rem 0.8rem;
    }

    .top-navbar .dropdown-toggle {
        font-size: 0.85rem;
        padding: 0.25rem 0.4rem;
    }

    /* Notification dropdown: anchor to right edge of viewport on mobile */
    .top-navbar .dropdown .dropdown-menu.dropdown-menu-end {
        position: fixed !important;
        right: 0.5rem !important;
        left: auto !important;
        top: auto !important;
        transform: none !important;
    }

    .top-navbar .dropdown-toggle .user-name-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 30vw;
    }


    .content-wrapper {
        padding: 1rem;
    }
}

/* Mobile card view for tables - only on portrait phones (<576px) */
@media (max-width: 575.98px) {
    .mobile-cards-container .mobile-card {
        border-radius: 0.5rem;
        margin-bottom: 0.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .mobile-cards-container .mobile-card .card-body {
        padding: 0.75rem;
    }
    .mobile-cards-container .mobile-card-label {
        font-size: 0.7rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.1rem;
    }
    .mobile-cards-container .mobile-card-row {
        margin-bottom: 0.4rem;
    }
    .mobile-cards-container .mobile-card-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid rgba(0,0,0,0.08);
    }
    .mobile-cards-container .mobile-card-actions .btn {
        flex: 1;
    }
    .mobile-cards-container .mobile-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.4rem;
    }
    .mobile-cards-container .mobile-card-header .card-title {
        font-size: 0.95rem;
        margin-bottom: 0;
        flex: 1;
        margin-right: 0.5rem;
    }
    .mobile-cards-container .mobile-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-bottom: 0.3rem;
    }
}

/* ── Summernote + Bootstrap 5 popup fix ── */
.note-modal-backdrop { display: none !important; }
.note-modal.open { display: flex !important; align-items: center; justify-content: center; background: rgba(0,0,0,.4); }
.note-modal .note-modal-content { margin: auto; max-width: 600px; width: 90%; }
.note-popover.popover { display: none; }
.note-popover.popover.in, .note-popover.popover.show { display: block !important; z-index: 3050 !important; }
.note-toolbar .dropdown-menu { z-index: 3060 !important; }
.note-color .note-dropdown-menu,
.note-table .note-dropdown-menu,
.note-style .note-dropdown-menu,
.note-para .note-dropdown-menu { z-index: 3060 !important; }
.note-editor .note-toolbar .note-dropdown-toggle::after { display: none; }
```

- [ ] **Step 2: Verify the file is well-formed CSS (brace balance check)**

Run: `node -e "const s=require('fs').readFileSync('assets/css/theme.css','utf8'); const open=(s.match(/\{/g)||[]).length; const close=(s.match(/\}/g)||[]).length; if(open!==close){console.error('MISMATCH', open, close); process.exit(1);} console.log('OK', open, 'rules');"`

Expected: `OK <N> rules` (no MISMATCH error). This is a mechanical sanity check, not a substitute for the visual verification in Task 9 — this repo has no CSS linter/build step, so this is the only automatable check available for a plain CSS file.

- [ ] **Step 3: Commit**

```bash
git add assets/css/theme.css
git commit -m "Add central theme.css with club badge palette (black + sunset amber)"
```

---

### Task 2: Wire the theme stylesheet into the app shell

**Files:**
- Modify: `includes/header.php`

**Interfaces:**
- Consumes: `assets/css/theme.css` (Task 1) — every CSS custom property and component class defined there.
- Produces: every page that includes `includes/header.php` (i.e., every logged-in app page) now has the new palette applied automatically.

- [ ] **Step 1: Add the stylesheet and Oswald font link, remove the old inline `<style>` block**

In `includes/header.php`, the current block (confirmed at lines 53–64) is:

```php
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr for date/time pickers -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Sortable.js for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>
```

Replace it with (adds the Oswald font family alongside Inter, and links the new theme stylesheet with a version-busting query string matching the existing convention used elsewhere in the app, e.g. `ride-replay.js?v=<?= APP_VERSION ?>`):

```php
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr for date/time pickers -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- EasyRide theme -->
    <link href="<?= rtrim(BASE_URL, '/') ?>/assets/css/theme.css?v=<?= APP_VERSION ?>" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Sortable.js for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>
```

Then delete the entire inline `<style>...</style>` block that follows (currently lines 66–699 — starts right after the script tags above with `<style>` and ends with `</style>` immediately before `</head>`). After deletion, the `<head>` should end with the `</head>` tag immediately after the script tags shown above (no `<style>` block remains in this file).

- [ ] **Step 2: `php -l` check**

Run: `C:/xampp/php/php.exe -l includes/header.php`
Expected: `No syntax errors detected in includes/header.php`

- [ ] **Step 3: Manual visual check**

Start the preview server (`easyride-php-dev`, port 8899) or a throwaway `php -S localhost:8899`, log in, and load `dashboard.php`. Confirm: sidebar is near-black (not navy/purple), active nav item has an amber left border, buttons are orange/amber (not purple), page headings render in the condensed Oswald font, and no console/network errors for the new stylesheet (check the `theme.css` request returns 200).

- [ ] **Step 4: Commit**

```bash
git add includes/header.php
git commit -m "Link theme.css and Oswald font into the app shell, drop old inline styles"
```

---

### Task 3: Reskin the auth-page family (login/register/password/verify/install)

**Files:**
- Modify: `login.php`
- Modify: `register.php`
- Modify: `forgot-password.php`
- Modify: `reset-password.php`
- Modify: `verify-email.php`
- Modify: `install.php`

**Interfaces:**
- Consumes: `--auth-bg-gradient`, `--auth-header-gradient`, `--primary-gradient`, `--primary-gradient-hover`, `--accent-color` from `assets/css/theme.css` (Task 1).

These six pages don't include `includes/header.php` (they render before login / outside the app shell), so each needs its own `<link>` to `assets/css/theme.css`.

- [ ] **Step 1: `login.php`**

Add the theme stylesheet link right after the existing Bootstrap Icons link (around line 55):

```php
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= rtrim(BASE_URL, '/') ?>/assets/css/theme.css?v=<?= APP_VERSION ?>" rel="stylesheet">
```

Then replace the old hardcoded colors (currently lines 58, 73, 83, 89):

```php
        body {
            background: var(--auth-bg-gradient);
```
```php
        .login-header {
            background: var(--auth-header-gradient);
```
```php
        .btn-login {
            background: var(--primary-gradient);
```
```php
        .btn-login:hover {
            background: var(--primary-gradient-hover);
```

- [ ] **Step 2: `register.php`**

Same pattern: add the theme.css `<link>` after the Bootstrap Icons link (around line 72). Replace (currently lines 75, 90):

```php
        body {
            background: var(--auth-bg-gradient);
```
```php
        .register-header {
            background: var(--auth-header-gradient);
```

- [ ] **Step 3: `forgot-password.php`**

Add the theme.css `<link>` after the Bootstrap Icons link (around line 81). Replace the page's own inline `<style>` (currently lines 84, 94):

```php
        body {
            background: var(--auth-bg-gradient);
```
```php
        .card-header-auth {
            background: var(--auth-header-gradient);
```

This file also builds an HTML **email** body as a PHP string (the "forgot password" email sent to the user) — that markup can't use CSS variables since email clients strip external stylesheets and often don't resolve custom properties reliably. Replace the two literal hex values there directly (currently lines 49 and 53):

```php
    <h2 style="color:#141110">Επαναφορά Κωδικού</h2>
```
```php
    <a href="' . $resetUrl . '" style="background:#e8792c;color:white;padding:14px 32px;text-decoration:none;border-radius:6px;display:inline-block;font-size:16px">
```

- [ ] **Step 4: `reset-password.php`**

Add the theme.css `<link>` after the Bootstrap Icons link (around line 70). Replace (currently lines 73, 83):

```php
        body {
            background: var(--auth-bg-gradient);
```
```php
        .card-header-auth {
            background: var(--auth-header-gradient);
```

- [ ] **Step 5: `verify-email.php`**

Add the theme.css `<link>` after the Bootstrap Icons link (around line 67). Replace the only occurrence (currently line 70):

```php
        body {
            background: var(--auth-bg-gradient);
```

- [ ] **Step 6: `install.php`**

Add the theme.css `<link>` after the Bootstrap Icons link (around line 544). Replace the body background (currently line 547) and the active step-indicator color (currently line 591) — leave `.install-header`'s green (`#27ae60`/`#1e8449`) untouched, it's an unrelated installer-success color, not part of this palette:

```php
        body {
            background: var(--auth-bg-gradient);
```
```php
        .step.active {
            background: var(--accent-color);
```

- [ ] **Step 7: `php -l` check on all six files**

Run: `C:/xampp/php/php.exe -l login.php && C:/xampp/php/php.exe -l register.php && C:/xampp/php/php.exe -l forgot-password.php && C:/xampp/php/php.exe -l reset-password.php && C:/xampp/php/php.exe -l verify-email.php && C:/xampp/php/php.exe -l install.php`
Expected: `No syntax errors detected` for all six.

- [ ] **Step 8: Grep check — no old colors remain in these files**

Run: `grep -rn "2c3e50\|3498db\|2980b9\|1a252f\|1f6691" login.php register.php forgot-password.php reset-password.php verify-email.php install.php`
Expected: no output (the green `#27ae60`/`#1e8449` in `install.php`'s `.install-header` is a different color family and won't match this pattern).

- [ ] **Step 9: Manual visual check**

Load `login.php` and `register.php` in the browser (logged out). Confirm the backdrop is a dark-to-amber sunset gradient (not navy-to-blue) and the login button is orange/amber.

- [ ] **Step 10: Commit**

```bash
git add login.php register.php forgot-password.php reset-password.php verify-email.php install.php
git commit -m "Reskin auth pages (login/register/password/verify/install) to the new palette"
```

---

### Task 4: Reskin dashboard, member, and profile pages

**Files:**
- Modify: `dashboard.php`
- Modify: `member-view.php`
- Modify: `profile.php`

**Interfaces:**
- Consumes: `--accent-color`, `--primary-gradient` from `assets/css/theme.css` (already linked via `includes/header.php`, Task 2 — these three pages all include the header, no new `<link>` needed).

These three files hardcode the old `#667eea`/`#764ba2` pair directly (inline `<style>` blocks and inline `style="..."` attributes) instead of using the shared variables. Replace each occurrence with the equivalent variable reference.

- [ ] **Step 1: `dashboard.php`**

Replace each exact line:

Line 489 — `background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);` → `background: var(--primary-gradient);`

Line 592 — `.ds-widget.accent-blue .card-header { border-bottom-color: #667eea; }` → `.ds-widget.accent-blue .card-header { border-bottom-color: var(--accent-color); }`

Line 605 — `.ds-leader-item:hover { background: #f8f9ff; border-left-color: #667eea; }` → `.ds-leader-item:hover { background: #fdf7ef; border-left-color: var(--accent-color); }`

Line 621 — `    border-left: 4px solid #667eea;` → `    border-left: 4px solid var(--accent-color);`

Line 866 — `<div class="stat-icon-box" style="background:linear-gradient(135deg,#667eea,#764ba2)">` → `<div class="stat-icon-box" style="background:var(--primary-gradient)">`

Line 947 — same replacement as line 866.

Line 1410 — `    border: 2px dashed #667eea;` → `    border: 2px dashed var(--accent-color);`

- [ ] **Step 2: `member-view.php`**

Line 389 — `background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);` → `background: var(--primary-gradient);`

Line 480 — `.vp-card.border-accent-primary .card-header { border-bottom-color: #667eea; }` → `.vp-card.border-accent-primary .card-header { border-bottom-color: var(--accent-color); }`

Line 652 — `<div class="stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)"><i class="bi bi-calendar2-check"></i></div>` → `<div class="stat-icon" style="background:var(--primary-gradient)"><i class="bi bi-calendar2-check"></i></div>`

- [ ] **Step 3: `profile.php`**

Line 281 — `background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);` → `background: var(--primary-gradient);`

Line 360 — `.pp-card.accent-primary .card-header { border-bottom-color: #667eea; }` → `.pp-card.accent-primary .card-header { border-bottom-color: var(--accent-color); }`

Line 518 — `<div class="stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)"><i class="bi bi-calendar2-check"></i></div>` → `<div class="stat-icon" style="background:var(--primary-gradient)"><i class="bi bi-calendar2-check"></i></div>`

- [ ] **Step 4: `php -l` check**

Run: `C:/xampp/php/php.exe -l dashboard.php && C:/xampp/php/php.exe -l member-view.php && C:/xampp/php/php.exe -l profile.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Grep check — no old colors remain**

Run: `grep -n "667eea\|764ba2" dashboard.php member-view.php profile.php`
Expected: no output.

- [ ] **Step 6: Manual visual check**

Load the dashboard, a member's profile page, and your own profile page. Confirm all stat icons/avatars/accent borders are orange/amber, not purple.

- [ ] **Step 7: Commit**

```bash
git add dashboard.php member-view.php profile.php
git commit -m "Reskin dashboard/member/profile pages to the new accent palette"
```

---

### Task 5: Reskin the PWA install banner and offline page

**Files:**
- Modify: `includes/footer.php`
- Modify: `offline.html`

**Interfaces:**
- None — these are self-contained (`footer.php`'s colors are in inline JS-generated styles; `offline.html` is a standalone static page that must keep working with zero network, so it cannot depend on the linked stylesheet).

- [ ] **Step 1: `includes/footer.php`**

Replace the four exact JS `style.cssText`/inline-style occurrences (currently lines 267, 270, 289, 295, 325):

Line 267 — `toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:99998;background:#1e3c72;color:#fff;padding:12px 20px;border-radius:12px;box-shadow:0 8px 25px rgba(0,0,0,.3);display:flex;align-items:center;gap:12px;font-size:14px;font-weight:500;animation:voSlideUp .4s ease;max-width:90vw;';` → replace `background:#1e3c72` with `background:#141110`.

Line 270 — `'<button onclick="location.reload()" style="background:#667eea;color:#fff;border:none;padding:6px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Ανανέωση</button>' +` → replace `background:#667eea` with `background:#e8792c`.

Line 289 — `banner.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99997;background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;padding:16px 20px;border-radius:16px;box-shadow:0 12px 35px rgba(0,0,0,.3);max-width:340px;animation:voSlideUp .5s ease;';` → replace `background:linear-gradient(135deg,#1e3c72,#2a5298)` with `background:linear-gradient(135deg,#141110,#1c1815)`.

Line 295 — `'<button id="vo-install-btn" style="flex:1;background:#667eea;color:#fff;border:none;padding:8px;border-radius:10px;font-weight:600;cursor:pointer;font-size:13px;">Εγκατάσταση</button>' +` → replace `background:#667eea` with `background:#e8792c`.

Line 325 — `tip.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:99997;background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;padding:18px 20px 28px;border-radius:20px 20px 0 0;box-shadow:0 -8px 30px rgba(0,0,0,.35);animation:voSlideUp .4s ease;';` → replace `background:linear-gradient(135deg,#1e3c72,#2a5298)` with `background:linear-gradient(135deg,#141110,#1c1815)`.

- [ ] **Step 2: `offline.html`**

This page must render with zero network access (it's the PWA offline fallback), so it keeps its colors as plain hardcoded hex — no dependency on the linked stylesheet or Google Fonts. Replace (currently lines 8, 21):

```html
    <meta name="theme-color" content="#141110">
```
```css
            background: linear-gradient(135deg, #141110 0%, #1c1815 50%, #141110 100%);
```

- [ ] **Step 3: `php -l` check**

Run: `C:/xampp/php/php.exe -l includes/footer.php`
Expected: `No syntax errors detected in includes/footer.php`

(`offline.html` is static HTML, no PHP lint applicable.)

- [ ] **Step 4: Grep check — no old colors remain**

Run: `grep -n "1e3c72\|2a5298\|667eea" includes/footer.php offline.html`
Expected: no output.

- [ ] **Step 5: Manual visual check**

Trigger the PWA install prompt (or the update-available toast, if a version bump is pending) and confirm it renders in the new near-black/amber palette. Load `offline.html` directly and confirm its background/theme-color match.

- [ ] **Step 6: Commit**

```bash
git add includes/footer.php offline.html
git commit -m "Reskin PWA install banner and offline fallback page to the new palette"
```

---

### Task 6: Reskin the public Ride Replay share page

**Files:**
- Modify: `replay-share.php`

**Interfaces:**
- Consumes: `--auth-bg-gradient` from `assets/css/theme.css` (Task 1).

- [ ] **Step 1: Add the theme stylesheet link**

In `replay-share.php`, add the `<link>` after the existing Bootstrap Icons link (around line 90, right before the conditional Leaflet CSS link):

```php
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= rtrim(BASE_URL, '/') ?>/assets/css/theme.css?v=<?= APP_VERSION ?>" rel="stylesheet">
```

- [ ] **Step 2: Replace the background gradient**

Replace the current line (line 123, from this session's earlier og-tag work):

```php
        body {
            background: var(--auth-bg-gradient);
```

- [ ] **Step 3: `php -l` check**

Run: `C:/xampp/php/php.exe -l replay-share.php`
Expected: `No syntax errors detected in replay-share.php`

- [ ] **Step 4: Grep check**

Run: `grep -n "2c3e50\|3498db" replay-share.php`
Expected: no output.

- [ ] **Step 5: Manual visual check**

Load a public share link (`replay-share.php?token=...` for a completed mission) and confirm the backdrop matches the new sunset gradient instead of the old blue.

- [ ] **Step 6: Commit**

```bash
git add replay-share.php
git commit -m "Reskin the public Ride Replay share page to the new palette"
```

---

### Task 7: Update the PWA manifest theme colors

**Files:**
- Modify: `manifest.json`

**Interfaces:**
- None.

- [ ] **Step 1: Update `theme_color` and `background_color`**

Replace (currently lines 9–10):

```json
    "theme_color": "#141110",
    "background_color": "#fbfaf8",
```

- [ ] **Step 2: Validate JSON**

Run: `node -e "JSON.parse(require('fs').readFileSync('manifest.json','utf8')); console.log('valid JSON')"`
Expected: `valid JSON`

- [ ] **Step 3: Commit**

```bash
git add manifest.json
git commit -m "Update PWA manifest theme/background colors to match the new palette"
```

---

### Task 8: Replace the logo and PWA icons with the real club badge

**Files:**
- Create: `assets/branding/club-badge-source.png` (provided by the user — see prerequisite below)
- Create: `scripts/regenerate-pwa-icons.php`
- Modify (generated, not hand-edited): `assets/icons/icon-72.png`, `icon-96.png`, `icon-128.png`, `icon-144.png`, `icon-152.png`, `icon-192.png`, `icon-384.png`, `icon-512.png`, `icon-maskable-512.png`
- Modify (generated, not hand-edited): `uploads/logos/club-badge.png` (new file)
- Data change: the `settings` table's `app_logo` row, updated from `logo_1771739763.jpg` to `club-badge.png`

**Interfaces:**
- Consumes: `assets/branding/club-badge-source.png` (must exist before this task runs), `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` constants from `config.php`/`config.local.php`.

**Prerequisite:** the club badge was shared as a pasted image in conversation, not a file — before this task can run, save it to `assets/branding/club-badge-source.png` in the repo (create the `assets/branding/` directory; it's new). It must be a PNG.

- [ ] **Step 1: Write `scripts/regenerate-pwa-icons.php`**

This is a one-off (but kept, reusable) CLI script: it resizes the source badge into every size `manifest.json` references, generates a maskable variant with proper safe-zone padding, copies the source into `uploads/logos/` under a new filename, and updates the `app_logo` setting in the database.

```php
<?php
/**
 * Regenerate PWA icons and the app_logo setting from the real club badge.
 * Run once from the repo root: C:/xampp/php/php.exe scripts/regenerate-pwa-icons.php
 */

require_once __DIR__ . '/../config.php';
if (file_exists(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}

$sourcePath = __DIR__ . '/../assets/branding/club-badge-source.png';
if (!file_exists($sourcePath)) {
    fwrite(STDERR, "Source badge not found at $sourcePath\n");
    exit(1);
}

$source = imagecreatefrompng($sourcePath);
if (!$source) {
    fwrite(STDERR, "Failed to read $sourcePath as PNG\n");
    exit(1);
}
imagesavealpha($source, true);
$srcW = imagesx($source);
$srcH = imagesy($source);
$srcSide = max($srcW, $srcH);

/**
 * Resizes $source onto a square transparent canvas of $size x $size,
 * centered, preserving aspect ratio (letterboxed if source isn't square).
 */
function squareResize($source, int $srcW, int $srcH, int $size): \GdImage {
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    $scale = min($size / $srcW, $size / $srcH);
    $destW = (int) round($srcW * $scale);
    $destH = (int) round($srcH * $scale);
    $destX = (int) (($size - $destW) / 2);
    $destY = (int) (($size - $destH) / 2);

    imagecopyresampled($canvas, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    return $canvas;
}

$iconSizes = [72, 96, 128, 144, 152, 192, 384, 512];
foreach ($iconSizes as $size) {
    $canvas = squareResize($source, $srcW, $srcH, $size);
    $destPath = __DIR__ . "/../assets/icons/icon-{$size}.png";
    imagepng($canvas, $destPath);
    imagedestroy($canvas);
    echo "Wrote $destPath\n";
}

/**
 * Maskable icon: solid brand-black background, badge scaled to 80% of the
 * canvas so the OS's circular/rounded-square mask never clips the emblem
 * (per the standard maskable-icon safe-zone guideline).
 */
$maskableSize = 512;
$maskable = imagecreatetruecolor($maskableSize, $maskableSize);
$bg = imagecolorallocate($maskable, 0x14, 0x11, 0x10); // --ez-black
imagefill($maskable, 0, 0, $bg);
$safeZone = (int) round($maskableSize * 0.8);
$scale = min($safeZone / $srcW, $safeZone / $srcH);
$destW = (int) round($srcW * $scale);
$destH = (int) round($srcH * $scale);
$destX = (int) (($maskableSize - $destW) / 2);
$destY = (int) (($maskableSize - $destH) / 2);
imagecopyresampled($maskable, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
$maskablePath = __DIR__ . '/../assets/icons/icon-maskable-512.png';
imagepng($maskable, $maskablePath);
imagedestroy($maskable);
echo "Wrote $maskablePath\n";

// Copy the source into uploads/logos/ under a fresh filename, following the
// same naming convention settings.php uses for logo uploads ('logo_' . time()).
$logoFilename = 'club-badge.png';
$logoDest = __DIR__ . '/../uploads/logos/' . $logoFilename;
copy($sourcePath, $logoDest);
echo "Wrote $logoDest\n";

imagedestroy($source);

// Update the app_logo setting.
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'app_logo'")->fetchColumn();
if ($exists) {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'app_logo'");
} else {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('app_logo', ?, NOW(), NOW())");
}
$stmt->execute([$logoFilename]);
echo "Updated app_logo setting to $logoFilename\n";

echo "Done.\n";
```

- [ ] **Step 2: `php -l` check**

Run: `C:/xampp/php/php.exe -l scripts/regenerate-pwa-icons.php`
Expected: `No syntax errors detected in scripts/regenerate-pwa-icons.php`

- [ ] **Step 3: Run the script**

Run: `C:/xampp/php/php.exe scripts/regenerate-pwa-icons.php`
Expected: nine `Wrote ...` lines (one per icon size + maskable + logo copy), then `Updated app_logo setting to club-badge.png` and `Done.` — no PHP errors/warnings.

- [ ] **Step 4: Verify the generated files**

Run: `identify assets/icons/icon-512.png assets/icons/icon-maskable-512.png uploads/logos/club-badge.png` (ImageMagick, if available) or open each file with the Read tool to visually confirm they show the club badge, not a blank/corrupt image, and that the maskable variant has visible padding around the badge (not touching the canvas edges).

- [ ] **Step 5: Manual visual check**

Reload the app in the browser; confirm the sidebar/header logo (wherever `app_logo` renders — `includes/header.php`, `login.php`, etc.) now shows the club badge, not the ActiveWeb logo. Check the browser tab's favicon/PWA install icon reflects the badge too (may require clearing the site's cached manifest/icons or a hard refresh).

- [ ] **Step 6: Commit**

```bash
git add scripts/regenerate-pwa-icons.php assets/branding/club-badge-source.png assets/icons/ uploads/logos/club-badge.png
git commit -m "Regenerate PWA icons and app_logo from the real club badge"
```

Note: `uploads/` is excluded from the production htdocs robocopy sync (per the project's established release workflow) and is typically gitignored content — confirm with `git status` whether `uploads/logos/club-badge.png` is actually tracked before committing; if `uploads/` is gitignored, this file won't go through git and the `app_logo` setting change (already applied directly to the database by the script) is what actually makes it live — note this explicitly when handing off to the release step so the file gets copied to production by whatever mechanism already handles `uploads/` there (not this plan's concern, but worth flagging).

---

### Task 9: Full-app verification pass

**Files:** none (verification only).

**Interfaces:** none.

- [ ] **Step 1: Repo-wide grep for any remaining old-palette colors**

Run: `grep -rln "667eea\|764ba2\|5a6fd6\|6a4190\|1e3c72\|2a5298\|2c3e50\|3498db\|2980b9\|1a252f\|1f6691" --include="*.php" --include="*.html" .`

Expected: no output. If any file appears, it was missed by Tasks 2–6 — open it, identify the surrounding selector/context the same way this plan did for every other file, and fix it following the same mapping (`#667eea`/`#764ba2` family → `var(--accent-color)`/`var(--primary-gradient)`; `#1e3c72`/`#2a5298` family → `var(--sidebar-gradient)`/`#141110`/`#1c1815`; `#2c3e50`/`#3498db` family → `var(--auth-bg-gradient)`).

- [ ] **Step 2: `php -l` on every touched file**

Run: `C:/xampp/php/php.exe -l includes/header.php && C:/xampp/php/php.exe -l login.php && C:/xampp/php/php.exe -l register.php && C:/xampp/php/php.exe -l forgot-password.php && C:/xampp/php/php.exe -l reset-password.php && C:/xampp/php/php.exe -l verify-email.php && C:/xampp/php/php.exe -l install.php && C:/xampp/php/php.exe -l dashboard.php && C:/xampp/php/php.exe -l member-view.php && C:/xampp/php/php.exe -l profile.php && C:/xampp/php/php.exe -l includes/footer.php && C:/xampp/php/php.exe -l replay-share.php && C:/xampp/php/php.exe -l scripts/regenerate-pwa-icons.php`

Expected: `No syntax errors detected` for every file.

- [ ] **Step 3: Manual browser walkthrough**

Using the `easyride-php-dev` preview server (or a throwaway `php -S`), log in and check, at both desktop (1280px) and mobile (375px) widths:
- `login.php` — sunset gradient backdrop, orange login button.
- `dashboard.php` — near-black sidebar, amber active-nav border, orange stat icons/avatars, Oswald headings.
- `mission-view.php` — the Ride Replay modal (touched in the prior session) still renders correctly with the new card/button/badge styling; the multi-rider legend toggle still works.
- A data-heavy list page (e.g. `members.php` or `member-view.php`) — confirm the table stays light/readable, not dark.
- `settings.php` — forms/tabs render with the new focus-ring color, no stray Bootstrap blue.
- The public `replay-share.php` page (logged out) — matches the new palette.

- [ ] **Step 4: Confirm no regressions in existing functionality**

Click through: sidebar collapse/expand on mobile, a dropdown menu, a modal (e.g. the Ride Replay modal), and a form submission (e.g. editing your profile) — confirm nothing is visually broken (overlapping text, invisible text-on-background, etc.) as a result of the color/font changes.

- [ ] **Step 5: Final commit (if Step 1 found and fixed anything)**

```bash
git add -A
git commit -m "Fix remaining old-palette color references found during full-app verification"
```

(Skip this commit if Step 1 found nothing to fix.)

---

## Self-Review Notes

- **Spec coverage:** every section of `docs/superpowers/specs/2026-07-09-visual-identity-revamp-design.md` maps to a task — design tokens (Task 1), technical architecture/central stylesheet (Tasks 1–2), the full file sweep (Tasks 3–6), branding assets/manifest/icons (Tasks 7–8), verification (Task 9). Semantic status colors and Bootstrap Icons are explicitly left untouched throughout, matching the spec's out-of-scope list.
- **Type/name consistency:** `--accent-color`, `--primary-gradient`, `--primary-gradient-hover`, `--auth-bg-gradient`, `--auth-header-gradient`, and `--sidebar-gradient` are defined once in Task 1 and referenced by the exact same names in every later task — no renaming drift.
- **Scope:** this plan is a reskin only — no task changes page structure, adds a feature, or touches business logic. Task 8 is the one task with an external dependency (the user must provide the badge file); every other task is self-contained and independently testable.
