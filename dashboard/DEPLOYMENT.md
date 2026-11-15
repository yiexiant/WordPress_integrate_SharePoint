# Deployment Guide: Central Backup Dashboard

This guide deploys the Laravel dashboard that monitors every WordPress site running the SharePoint Backup plugin.
For running it on your own PC with Laragon, see [`GETTING_STARTED.md`](GETTING_STARTED.md). For installing the plugin
on the WordPress sites, see [`../plugin/DEPLOYMENT.md`](../plugin/DEPLOYMENT.md).

---

## Contents

1. [What you are deploying](#1-what-you-are-deploying)
2. [Requirements](#2-requirements)
3. [Choose where to host it](#3-choose-where-to-host-it)
4. [Install on Ubuntu with Nginx and PHP-FPM](#4-install-on-ubuntu-with-nginx-and-php-fpm)
5. [Production `.env`](#5-production-env)
6. [Database, first user and caches](#6-database-first-user-and-caches)
7. [Web server and HTTPS](#7-web-server-and-https)
8. [Scheduler (required for monitoring)](#8-scheduler-required-for-monitoring)
9. [Connect the WordPress sites](#9-connect-the-wordpress-sites)
10. [Verify the deployment](#10-verify-the-deployment)
11. [Updating to a new version](#11-updating-to-a-new-version)
12. [Backing up the dashboard itself](#12-backing-up-the-dashboard-itself)
13. [Hosting on Windows instead](#13-hosting-on-windows-instead)
14. [Security checklist](#14-security-checklist)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. What you are deploying

```
Operators (browser, HTTPS) ──► Dashboard (Laravel 13, PHP 8.3)
                                  ├─ Database: websites, backup/restore history, activity, users, sessions
                                  ├─ Scheduler: `php artisan sites:sync` every 5 minutes
                                  └─ Outbound HTTPS ──► each WordPress site: /?rest_route=/spsync/v1/…  (X-SPSYNC-KEY)
```

- The dashboard never talks to SharePoint. It only calls the plugin on each WordPress site, using that site's
  API key (stored encrypted with `APP_KEY`).
- Monitoring (online/offline, **Backup overdue**) is done by the **scheduler**. Without it, data refreshes only when
  someone clicks a button.
- Sign-in is required for every page. Every operator account can run backups, restores and URL rewrites.

---

## 2. Requirements

| Item | Requirement |
|---|---|
| OS | Any OS that runs PHP 8.3 (this guide: Ubuntu 24.04 LTS; Windows notes in §13) |
| PHP | **8.3 or newer** with extensions `ctype curl dom fileinfo filter mbstring openssl pdo tokenizer xml zip intl` and **`pdo_sqlite`** (default database) or `pdo_mysql` |
| Composer | 2.x |
| Node.js | **20.19+ or 22.12+**, needed only to build the CSS/JS (`npm run build`). It can run on a build machine instead. |
| Database | SQLite (default; fine for dozens of sites and a few operators) or MySQL 8 / MariaDB 10.6+ |
| Web server | Nginx or Apache, serving the `public/` folder only |
| Network | **Outbound HTTPS (443)** to every WordPress site; inbound HTTPS for operators (ideally restricted, §14) |
| Resources | 1 vCPU, 1 GB RAM and 2 GB disk is enough for a typical fleet |

---

## 3. Choose where to host it

| Option | Use when |
|---|---|
| **Linux VM** (§4–§8) | Recommended for production: an always-on server with HTTPS and a scheduler. |
| **Windows server / PC** (§13) | Internal use on a machine that is always on. |
| **Your own PC with Laragon** | Testing only (see `GETTING_STARTED.md`). Monitoring stops when the PC sleeps. |

The dashboard must be able to reach the WordPress sites. To manage sites that only exist on an internal network,
host it inside that network and set `WP_ALLOW_PRIVATE_HOSTS=true` (§5).

---

## 4. Install on Ubuntu with Nginx and PHP-FPM

Commands assume Ubuntu 24.04, the domain `backups.example.com` and the install path `/var/www/backup-dashboard`.

### 4.1 System packages

```sh
sudo apt update
sudo apt install -y nginx git unzip sqlite3 \
  php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node.js 22 (build only)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

> Ubuntu 24.04 ships PHP 8.3. On older releases, add the `ppa:ondrej/php` repository first.

### 4.2 Get the code

```sh
sudo mkdir -p /var/www && cd /var/www
sudo git clone <your-repository-url> backup-source
sudo ln -s /var/www/backup-source/dashboard /var/www/backup-dashboard
sudo chown -R $USER:www-data /var/www/backup-source
cd /var/www/backup-dashboard
```

The repository also contains the plugin; only the `dashboard/` folder is served.

### 4.3 Dependencies and front-end build

```sh
composer install --no-dev --optimize-autoloader --no-interaction
npm install
npm run build            # writes public/build (not stored in git)
```

### 4.4 File permissions

```sh
sudo chown -R $USER:www-data storage bootstrap/cache database
sudo find storage bootstrap/cache database -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache database -type f -exec chmod 664 {} \;
```

PHP-FPM (user `www-data`) must be able to write to `storage/`, `bootstrap/cache/` and, with SQLite, the `database/`
folder and file.

---

## 5. Production `.env`

```sh
cp .env.example .env
php artisan key:generate        # ONLY on a brand-new install, see the warning below
nano .env
```

Recommended values:

```dotenv
APP_NAME="Backup Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backups.example.com

LOG_CHANNEL=daily
LOG_LEVEL=warning

# SQLite (default). The file is database/database.sqlite.
DB_CONNECTION=sqlite
# MySQL alternative:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=backup_dashboard
# DB_USERNAME=backup_dashboard
# DB_PASSWORD=<strong password>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true       # requires HTTPS
CACHE_STORE=database
QUEUE_CONNECTION=database        # no queued jobs are used today; harmless

# WordPress connections (see config/services.php)
WP_VERIFY_TLS=true               # verify the WordPress sites' HTTPS certificates
WP_ALLOW_PRIVATE_HOSTS=false     # true only if you manage sites on private/LAN addresses
WP_BACKUP_OVERDUE_GRACE_HOURS=6  # hours past a site's schedule before it shows "Backup overdue"
```

> ⚠️ **`APP_KEY` encrypts every stored WordPress API key.** Generate it once, back it up somewhere safe (§12), and
> never run `key:generate` on an existing install. A lost or changed key makes every saved site key unreadable, and
> each one has to be re-entered.

Protect the file: `chmod 640 .env`.

---

## 6. Database, first user and caches

```sh
# SQLite: create the empty database file first
touch database/database.sqlite && chmod 664 database/database.sqlite

php artisan migrate --force
php artisan dashboard:user admin@example.com --name="Your Name"     # prompts for a password (12+ characters)

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run the three `*:cache` commands after **every** `.env` or code change; cached configuration ignores later edits.

Add more operators, or reset a password, at any time:

```sh
php artisan dashboard:user someone@example.com --name="Someone"
```

---

## 7. Web server and HTTPS

### 7.1 Nginx site

`/etc/nginx/sites-available/backup-dashboard`:

```nginx
server {
    listen 80;
    server_name backups.example.com;
    root /var/www/backup-dashboard/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "same-origin";

    charset utf-8;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 330;   # restores wait up to 300 s for WordPress to answer
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```sh
sudo ln -s /etc/nginx/sites-available/backup-dashboard /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Also raise PHP-FPM's limit to match: in `/etc/php/8.3/fpm/php.ini` set `max_execution_time = 330`, then
`sudo systemctl restart php8.3-fpm`.

### 7.2 HTTPS (Let's Encrypt)

```sh
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d backups.example.com --redirect
```

HTTPS is required: `SESSION_SECURE_COOKIE=true` only sends the login cookie over HTTPS.

### 7.3 Behind a load balancer or reverse proxy

If TLS ends at a proxy (Cloudflare, an AWS ALB, Traefik…), Laravel must trust it so it generates `https://` links.
In `bootstrap/app.php`, inside `->withMiddleware(...)`, add `$middleware->trustProxies(at: '*');` (or the proxy's IP
range).

---

## 8. Scheduler (required for monitoring)

The scheduler runs `sites:sync` every 5 minutes (health, backup status and overdue detection for every site) and a
full diagnostics refresh daily at 03:30. Use **one** of these methods.

**Cron (simplest):**

```sh
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/backup-dashboard && php artisan schedule:run >> /dev/null 2>&1
```

**systemd service (long-running alternative):** `/etc/systemd/system/backup-dashboard-scheduler.service`

```ini
[Unit]
Description=Backup Dashboard scheduler
After=network-online.target

[Service]
User=www-data
WorkingDirectory=/var/www/backup-dashboard
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload && sudo systemctl enable --now backup-dashboard-scheduler
```

Check it: `php artisan schedule:list` shows both `sites:sync` entries. Run a sync by hand at any time with
`sudo -u www-data php artisan sites:sync`.

> Run the scheduler as the **same user as PHP-FPM** (`www-data`). Otherwise log and cache files get the wrong owner
> and the website starts failing with permission errors.

---

## 9. Connect the WordPress sites

For each site (plugin installed and configured as described in `../plugin/DEPLOYMENT.md`):

1. WordPress → **SharePoint Backup → Settings › Central Dashboard** → **Copy key**.
2. Dashboard → **Add website** → name, exact public address (with `https://` and `www` if used) and the key.
3. Click **Check connection** on the website's page. It should turn **Healthy**, or show the exact problem
   (unreachable, key rejected, wrong address, SharePoint sign-in failed, no automatic schedule…).

Rules the dashboard enforces:

- Only `http(s)` addresses, without user names or passwords in the URL.
- Addresses that resolve to private, loopback or link-local IPs are refused unless `WP_ALLOW_PRIVATE_HOSTS=true`.
- Redirects are **not** followed, so the API key is never sent to another host. Register the final address the browser lands on.
- TLS certificates are verified (`WP_VERIFY_TLS=true`). Sites with self-signed or expired certificates fail until fixed.

---

## 10. Verify the deployment

- [ ] `https://backups.example.com/up` returns HTTP 200 (Laravel health check; use it for uptime monitoring).
- [ ] The sign-in page loads with styling, and signing in works.
- [ ] `php artisan migrate:status` shows every migration as **Ran**.
- [ ] `php artisan schedule:list` lists `sites:sync`; within 5 minutes each site's "Last checked" time updates.
- [ ] Each website shows **Healthy**, or a reason you understand and have a plan for.
- [ ] **Back up now** on one site produces a new backup that shows **Saved to SharePoint**.
- [ ] A test restore (ideally onto a staging site through the **Migrate** tab) completes and shows **Restored**.
- [ ] `APP_KEY` and the database are backed up (§12).

Optional automated check, on a build or CI machine with dev dependencies:

```sh
composer install && php artisan test     # all tests should pass
```

---

## 11. Updating to a new version

```sh
cd /var/www/backup-dashboard
php artisan down                                   # maintenance page for operators
git -C .. pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm install && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.3-fpm                   # clear OPcache
sudo systemctl restart backup-dashboard-scheduler  # only if you use the systemd scheduler
php artisan up
```

Back up the database (§12) before running migrations. Some migrations change stored data; for example, version 1.1
encrypted existing API keys.

To roll back, check out the previous commit, run `composer install`/`npm run build`, and restore the database backup
taken before `migrate`.

---

## 12. Backing up the dashboard itself

The dashboard's own data is small but worth keeping: the site list with encrypted keys, history and operators.

| What | How |
|---|---|
| `APP_KEY` (in `.env`) | Copy `.env` to a password manager or secrets vault **once**. Without it, saved API keys cannot be decrypted. |
| SQLite database | `sqlite3 database/database.sqlite ".backup '/var/backups/dashboard-$(date +%F).sqlite'"` (safe while running). Schedule it daily with cron. |
| MySQL database | `mysqldump --single-transaction backup_dashboard > dashboard-$(date +%F).sql` |

**Recovery:** restore the code (§4), the `.env` with the original `APP_KEY`, and the database file or dump, then run
`php artisan migrate --force` and the cache commands.

---

## 13. Hosting on Windows instead

For an always-on Windows machine (internal use):

1. Install PHP 8.3 (for example via Laragon, or the official build with the extensions from §2 enabled in `php.ini`),
   Composer and Node.js 22.
2. In the `dashboard` folder run §4.3 and §5–§6 (in PowerShell use `copy .env.example .env`).
3. Serve it with IIS + PHP (FastCGI) or Apache pointing at `dashboard\public`, with HTTPS. `php artisan serve` is
   for testing only.
4. Scheduler: **Task Scheduler → Create Task**. Trigger: daily, repeat every **1 minute** indefinitely. Action:
   `C:\path\to\php.exe` with arguments `artisan schedule:run` and "Start in" set to the dashboard folder.
   Tick **Run whether user is logged on or not**.
5. Turn off sleep and hibernation, or monitoring stops.

---

## 14. Security checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (debug pages would reveal configuration).
- [ ] HTTPS everywhere and `SESSION_SECURE_COOKIE=true`.
- [ ] **Restrict who can reach the dashboard**: it can restore or rewrite every website. Limit it to a VPN or office
      IP ranges (Nginx `allow`/`deny`, a cloud firewall, or Cloudflare Access) in addition to the login.
- [ ] Only the people who need it get operator accounts; there are no roles, so every account has full control.
      Remove an operator with `php artisan tinker` → `App\Models\User::where('email','x@y')->delete();`.
- [ ] `WP_VERIFY_TLS=true` and `WP_ALLOW_PRIVATE_HOSTS=false` unless you have a specific reason.
- [ ] `.env` is not web-accessible (only `public/` is served) and is `chmod 640`.
- [ ] `APP_KEY` and database backups are stored outside the server.
- [ ] Keep PHP, Nginx and Composer dependencies updated; run `composer audit` periodically.
- [ ] If a WordPress API key may have leaked: rotate it on that site (plugin guide §7), then **Edit** the site in the dashboard.

---

## 15. Troubleshooting

Logs: `storage/logs/laravel-*.log` (dashboard), `/var/log/nginx/error.log`, and each site's **Activity** in the dashboard.

| Symptom | Cause and fix |
|---|---|
| HTTP 500 right after deploying | Permissions (§4.4), missing `APP_KEY`, or stale caches: run `php artisan optimize:clear`, then the cache commands again. Read `storage/logs`. |
| `Vite manifest not found` / unstyled pages | `npm install && npm run build` was not run on this server or build. |
| `The MAC is invalid` / `DecryptException` | `APP_KEY` changed. Restore the original `.env`, or re-enter every site's API key under **Edit**. |
| `419 Page Expired` on sign-in | Session cookie not stored: `APP_URL`, domain and HTTPS must match; behind a proxy see §7.3. |
| Login loop on HTTP | `SESSION_SECURE_COOKIE=true` requires HTTPS. |
| *Too many login attempts* | Rate limit: 5 attempts per minute per email and IP. Wait one minute. |
| "Last checked" never changes | Scheduler not running (§8). Check `schedule:list` and the cron or systemd status. |
| Site: *resolves to a private or reserved network address* | The site is on a LAN/loopback address. Set `WP_ALLOW_PRIVATE_HOSTS=true` if that is intended, then re-run the cache commands. |
| Site: *cURL error 60 / SSL certificate problem* | The WordPress site's certificate is invalid or self-signed. Fix the certificate (preferred) or set `WP_VERIFY_TLS=false` (not recommended in production). |
| Site: *redirected to … (HTTP 301)* | Edit the site and use the address shown in the message. |
| Site: *did not answer at this address (HTTP 404)* | Plugin not activated, wrong address, or the REST API is blocked by a security plugin or firewall. |
| Site: *rejected the API key* | The key was rotated in WordPress. Paste the current key under **Edit**. |
| Restore button times out | Restores wait up to 300 s for WordPress to take its safety snapshot. Raise `fastcgi_read_timeout` and `max_execution_time` (§7.1). |
