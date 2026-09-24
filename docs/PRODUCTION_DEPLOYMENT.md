# ZeeraK Production Deployment

This checklist is intended for the Laravel 8 / Vue 2 ZeeraK Real Estate & Builders application on Ubuntu, Apache and MySQL.

## 1. Environment

Use production-safe values in `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Karachi

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAYS=30

SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

SECURITY_HSTS_ENABLED=true
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=false
SECURITY_HSTS_PRELOAD=false

QUEUE_CONNECTION=sync
```

Generate an application key only for a new installation. Never replace the key on an existing production system unless you understand the impact on encrypted application data.

## 2. Deploy application code

From the application directory:

```bash
php artisan down

git pull --rebase origin main

composer install --no-dev --prefer-dist --optimize-autoloader

npm ci
npm run production

php artisan migrate --force
php artisan storage:link

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan zeerak:production-check
php artisan zeerak:acceptance-check
php artisan zeerak:reconcile-finances

php artisan up
```

Do not use `migrate:fresh` on production.

If `route:cache` reports a closure-route error, do not leave deployment half-complete. Clear the route cache and investigate the route before enabling the application.

## 3. Permissions

The web-server user must be able to write Laravel runtime directories:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Application source files should not be broadly writable by the web server.

## 4. Laravel scheduler

Add one cron entry for the deployment user:

```cron
* * * * * cd /var/www/html/zeerak-realestate && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler currently handles overdue-installment maintenance and uses overlap protection.

Verify manually:

```bash
php artisan zeerak:update-overdue-installments
```

## 5. Database and uploaded-file backups

ZeeraK includes a built-in backup command for both MySQL and `storage/app`. The backup directory is deliberately separate from `storage/app` so backups cannot recursively include themselves.

Recommended production configuration:

```env
BACKUP_ENABLED=true
BACKUP_PATH=/var/backups/zeerak
BACKUP_RETENTION_DAYS=14
BACKUP_SCHEDULE_TIME=02:00
BACKUP_DATABASE=true
BACKUP_FILES=true
MYSQLDUMP_PATH=/usr/bin/mysqldump
```

For local XAMPP on Windows, an example dump path is:

```env
MYSQLDUMP_PATH=C:/xampp/mysql/bin/mysqldump.exe
```

Create a backup manually:

```bash
php artisan zeerak:backup
```

The command creates:
- a raw MySQL `.sql` dump
- a ZIP archive of `storage/app`, including private financial documents and public uploads
- a JSON manifest containing SHA-256 checksums, artifact sizes and backup metadata

Verify the newest backup:

```bash
php artisan zeerak:verify-backup
```

Or verify a specific manifest:

```bash
php artisan zeerak:verify-backup /var/backups/zeerak/zeerak-YYYYMMDD-HHMMSS-PID-manifest.json
```

Useful manual modes:

```bash
php artisan zeerak:backup --database-only
php artisan zeerak:backup --files-only
php artisan zeerak:backup --no-prune
```

When `BACKUP_ENABLED=true`, Laravel schedules a full backup every day at `BACKUP_SCHEDULE_TIME`. Old ZeeraK backup artifacts are removed after `BACKUP_RETENTION_DAYS`. The scheduler cron entry from section 4 must therefore be active.

### Off-site backup requirement

A backup stored only on the application server is not sufficient disaster recovery. Copy the completed backup set to a second server, encrypted cloud/object storage, or another protected location. Do not expose the backup directory through Apache.

At minimum:
- keep daily backups for 14 days
- keep an additional monthly copy separately
- verify backups after copying off-site
- test a restore periodically on a non-production environment

### Restore runbook

Do not restore directly over a live production system without first taking a fresh backup of its current state.

1. Put the application into maintenance mode and create one final backup:

```bash
php artisan down
php artisan zeerak:backup --no-prune
php artisan zeerak:verify-backup
```

2. Verify the backup set that will be restored:

```bash
php artisan zeerak:verify-backup /path/to/zeerak-...-manifest.json
```

3. Restore the SQL dump to the intended MySQL database. Use credentials supplied interactively or through a protected MySQL option file rather than placing the password directly in shell history:

```bash
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < /path/to/zeerak-...-database.sql
```

4. Restore files into a temporary directory first, inspect them, then synchronize the extracted `storage-app/` contents into the application's `storage/app/` directory:

```bash
mkdir -p /tmp/zeerak-restore
unzip /path/to/zeerak-...-storage.zip -d /tmp/zeerak-restore
rsync -a /tmp/zeerak-restore/storage-app/ /var/www/html/zeerak-realestate/storage/app/
```

5. Restore ownership/permissions and rebuild Laravel caches:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

6. Validate the restored application before reopening it:

```bash
php artisan zeerak:production-check
php artisan zeerak:reconcile-finances
```

Then test login, branch access, recent bookings/payments, a private document download, a customer statement and a payment receipt. Only after those checks succeed:

```bash
php artisan up
```

## 6. Logs

Production uses Laravel daily log rotation. Monitor:

```bash
tail -f storage/logs/laravel-$(date +%F).log
```

Investigate recurring HTTP 500 errors, permission failures, failed uploads, authentication failures and scheduler errors.

## 7. Security

The application adds response headers for:
- MIME sniffing protection
- clickjacking protection
- referrer policy
- browser camera/microphone/geolocation restrictions
- HSTS on HTTPS responses

HSTS is configurable. The safe default deliberately leaves `includeSubDomains` and `preload` disabled:

```env
SECURITY_HSTS_ENABLED=true
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=false
SECURITY_HSTS_PRELOAD=false
```

Only enable `SECURITY_HSTS_INCLUDE_SUBDOMAINS=true` after confirming every current and future subdomain is permanently served over HTTPS. Only enable preload after deliberately meeting browser preload requirements; it is difficult to reverse quickly.

Production must terminate HTTPS correctly. If a reverse proxy/load balancer is used, configure Laravel trusted proxies correctly so HTTPS detection remains accurate.

Do not expose:
- `.env`
- `storage/app`
- database backups
- private financial documents

Only the Laravel `public` directory should be served by Apache.

## 8. Queue

The current application can run with:

```env
QUEUE_CONNECTION=sync
```

When asynchronous email, notifications, imports or background processing are introduced, switch to a durable queue such as database or Redis and manage workers with Supervisor/systemd.

## 9. Post-deployment checks

Run:

```bash
php artisan zeerak:production-check --strict
php artisan zeerak:acceptance-check --strict
php artisan zeerak:reconcile-finances
```

The acceptance command is non-destructive. It checks critical routes and cross-module data integrity without modifying records. The detailed manual workflow checklist is in `docs/ACCEPTANCE_TESTING.md`.

Then test:
- Login/logout
- Role and branch isolation
- Lead → Customer → Site Visit → Booking
- Installment plan generation
- Payment and reversal
- Commission lifecycle
- Expense creation
- Receipt/invoice upload and download
- PDF/Excel reports
- Notification links
- Profile image
- Financial audit trail

Do not use `zeerak:reconcile-finances --fix` until every reported inconsistency has been reviewed.
