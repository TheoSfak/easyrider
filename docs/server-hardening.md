# Server hardening

The project root contains the web application. Do not expose maintenance or cron commands over HTTP.

Apache deployments use the root `.htaccess` and `scripts/maintenance/.htaccess` rules added with this change. Ensure the virtual host permits these directives with `AllowOverride FileInfo AuthConfig Options` (or move the rules into the virtual-host configuration).

For Nginx, add the equivalent rules to the application server block:

```nginx
location ^~ /scripts/maintenance/ { deny all; }
location ~ ^/(?:_dbcheck|add_indexes|add_tasks_tables|add_weather_cache|api-weather-test|backfill_achievements|check_mission_template|cleanup_incomplete_attempts|cleanup_test_data|debug_exam_fix|delete_test_mission|delete_test_users|enable_zip_extension|fix_branch_names|fix_install_columns|fix_schema_sql|fix-tf-questions|generate_vapid_keys|import_email_templates|import_firstaid_data|install|migrate_complaints|migrate_v3|push-debug|restore_mission_template|restore_notifications|restore_task_templates|run_cohort_migration|run_quiz_migration|seed_email_data|seed_inventory|seed_questions_run|cron_(?:certificate_expiry|citizen_cert_expiry|daily|incomplete_missions|shelf_expiry|shift_reminders|task_reminders))\.php$ { deny all; }
```

Run the relocated tools only from a trusted shell:

```text
php scripts/maintenance/test_app.php
php scripts/maintenance/test_full.php
php scripts/maintenance/generate_pwa_icons.php [logo-file]
php scripts/maintenance/migrate.php
```

Run `migrate.php` once per deployment, after the new code is in place and before serving traffic. It holds a MySQL advisory lock, applies tracked SQL migrations and PHP schema migrations, and exits unsuccessfully if the schema remains behind `DB_SCHEMA_VERSION`.

The remaining root maintenance files are intentionally web-denied until they are converted to CLI commands and moved to `scripts/maintenance/`.
