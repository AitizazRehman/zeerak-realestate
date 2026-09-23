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

Back up both the database and Laravel storage. Private financial receipts/invoices are stored under `storage/app`; profile/company images are stored under `storage/app/public`.

Example database backup:

```bash
mkdir -p /var/backups/zeerak
mysqldump --single-transaction --quick --routines --triggers \
  -u YOUR_DB_USER -p YOUR_DB_NAME \
  | gzip > /var/backups/zeerak/db-$(date +%F-%H%M).sql.gz
```

Example uploaded-file backup:

```bash
tar -czf /var/backups/zeerak/storage-$(date +%F-%H%M).tar.gz \
  -C /var/www/html/zeerak-realestate storage/app
```

Keep backups outside the web root and copy them to a second server or secure external storage. Test restores periodically.

A practical schedule is:
- Database: daily
- `storage/app`: daily
- Retain daily backups for at least 14 days
- Retain at least one monthly backup separately

## 6. Logs

Production uses Laravel daily log rotation. Monitor:

```bash
tail -f storage/logs/laravel-$(date +%F).log
```

Investigate recurring HTTP 500 errors, permission failures, failed uploads, authentication failures and scheduler errors.

## 7. Security

The application adds response headers for:
- MIME sniffing protection
- Clickjacking protection
- Referrer policy
- Browser camera/microphone/geolocation restrictions
- HSTS when the request is HTTPS

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
php artisan zeerak:reconcile-finances
```

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
