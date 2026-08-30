# SharePoint Automated Backup & Multi-Site Central Dashboard for WordPress

An enterprise-grade disaster recovery, migration, and fleet management suite for WordPress. It combines a standalone **Central Laravel Dashboard** with a high-performance **WordPress Plugin** to automate database dumps, split ZIP archiving, chunked Microsoft SharePoint cloud synchronization, and 1-click restorations with serialized-safe database URL migrations.

---

## 📁 Repository Structure

```text
├── dashboard/                  # Central Fleet Management Application (Laravel 11 / TailwindCSS)
│   ├── app/                    # Controllers, Models, WordPress API Client
│   ├── config/                 # Application & database configurations
│   ├── database/               # Migrations & seeders
│   ├── resources/views/        # Clean White & Red Blade UI views
│   ├── routes/                 # Web routes
│   └── .env.example            # Environment configuration template
│
├── plugin/                     # WordPress Plugin Source Code & Packages
│   ├── admin/                  # WP Admin Settings & Configuration Tabs
│   ├── api/                    # REST API Endpoints (Health, Verify, Restore, URL Migration)
│   ├── auth/                   # Microsoft Graph OAuth 2.0 Client Credentials
│   ├── backup/                 # MySQL Dump & Zip Compression Engines
│   ├── database/               # Schema Installer & Database URL Migrator
│   ├── helpers/                # Settings & Encryption Helpers
│   ├── logger/                 # Database Log Engine
│   ├── queue/                  # Background Upload & ActionScheduler Queue
│   ├── restore/                # Download, Checksum, Batch SQL & File Restore Engine
│   ├── scheduler/              # Automated Cron Scheduler
│   ├── storage/                # Microsoft SharePoint Chunked Storage Engine
│   ├── schedule-backup-plugin.php # Main Plugin Entry Point
│   └── schedule_backup_plugin.zip # Pre-built installable plugin package
│
├── .gitignore                  # Global gitignore (excludes all .env & secrets)
└── README.md                   # Full documentation & deployment guides
```

---

## 📑 Table of Contents

1. [Architecture & System Overview](#-architecture--system-overview)
2. [Key Features](#-key-features)
3. [Microsoft Azure & SharePoint Setup Guide](#-microsoft-azure--sharepoint-setup-guide)
4. [WordPress Plugin User Guide](#-wordpress-plugin-user-guide)
5. [Windows Setup & Deployment Guide (Dashboard)](#-windows-setup--deployment-guide-dashboard)
6. [Linux Server Deployment Guide (Dashboard)](#-linux-server-deployment-guide-dashboard)
7. [Central Dashboard Configuration & Site Pairing](#-central-dashboard-configuration--site-pairing)
8. [Automation & Retention Explained](#-automation--retention-explained)
9. [Troubleshooting & FAQ](#-troubleshooting--faq)

---

## 🏗️ Architecture & System Overview

```
┌─────────────────────────────────────────────────────────────┐
│                 Central Management Dashboard                │
│                 (Laravel 11 / TailwindCSS)                  │
│                                                             │
│  - Multi-site KPI statistics (SharePoint Synced vs Missing) │
│  - Fleet-wide status monitoring & live AJAX polling         │
│  - Single & Bulk backup triggers with confirmation modals   │
│  - Remote SharePoint file scan ("Removed from SharePoint")  │
│  - 1-Click Restoration & Duration tracking                  │
│  - Serialized-Safe Database URL Migration Assistant         │
└──────────────────────────────┬──────────────────────────────┘
                               │
                 REST API (HTTPS + X-SPSYNC-KEY)
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│              WordPress SharePoint Backup Plugin             │
│               (Schedule Backup Plugin / SPSync)             │
│                                                             │
│  - Native MySQL dump engine                                 │
│  - Dual compression engine (ZipArchive with PclZip fallback)│
│  - Resilient 3.2MB chunked upload stream with retry logic   │
│  - Flexible scheduler (Daily, Weekly, Monthly, Specific Date)│
│  - Auto-retention pruning on Microsoft SharePoint           │
│  - Pre-restore safety rollback snapshot                     │
│  - Recursive Serialized-Safe Database URL Search & Replace  │
└──────────────────────────────┬──────────────────────────────┘
                               │
                     Microsoft Graph API v1.0
                     (OAuth 2.0 Client Credentials)
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│               Microsoft SharePoint Cloud Storage            │
│               (Document Library / Drive API)                │
└─────────────────────────────────────────────────────────────┘
```

---

## ⭐ Key Features

- **Centralized Multi-Site Fleet Management**: Manage unlimited WordPress websites from one unified white-and-red dashboard.
- **Deep Cloud Verification**: Detects if backups were manually deleted from SharePoint and tags them as `"Removed from SharePoint"`.
- **Automated Website Migration Assistant**: Recursively updates URLs across all database tables (including `siteurl`, `home`, and post content) while preserving PHP serialized string lengths.
- **Restoration Duration Metrics**: Tracks exact execution time for every restore operation.
- **Specific Time & Calendar Scheduling**: Schedule backups daily at a specific hour, weekly on a chosen day, monthly on a specific date, or as a one-time scheduled backup.
- **Smart Cloud Retention Policy**: Automatically prunes older backups on SharePoint once your chosen limit (e.g. 10, 30, 50) is reached.
- **Resilient Upload Engine**: Streams archives in optimized **3.2 MB chunks** with 120-second timeout buffers and exponential backoff retries.
- **Pre-Restoration Safety Rollback**: Automatically creates an emergency local snapshot before overwriting files or tables.

---

## 🔑 Microsoft Azure & SharePoint Setup Guide

To allow the plugin to upload backups to your SharePoint Document Library, register an application in Microsoft Entra ID (Azure AD):

### Step 1: Register Application in Microsoft Entra ID
1. Log in to the [Microsoft Entra Admin Center](https://entra.microsoft.com/) or [Azure Portal](https://portal.azure.com/).
2. Navigate to **Identity** &rarr; **Applications** &rarr; **App registrations** &rarr; **New registration**.
3. Name: `WordPress SharePoint Backup`.
4. Supported account types: **Accounts in this organizational directory only (Single tenant)**.
5. Click **Register**.
6. Copy the **Application (client) ID** and **Directory (tenant) ID** from the Overview page.

### Step 2: Create a Client Secret
1. In your app registration sidebar, click **Certificates & secrets** &rarr; **Client secrets** &rarr; **New client secret**.
2. Description: `WP-Backup-Key`.
3. Set expiration (e.g., 180 days or 24 months).
4. Click **Add** and immediately **copy the Secret Value** (it is only shown once).

### Step 3: Grant API Permissions
1. In the app registration sidebar, click **API permissions** &rarr; **Add a permission**.
2. Select **Microsoft Graph** &rarr; **Application permissions**.
3. Add the following permissions:
   - `Files.ReadWrite.All` (To create backup folders and upload chunked files)
   - `Sites.ReadWrite.All` (To resolve SharePoint site IDs and document libraries)
4. Click **Add permissions**.
5. Click **Grant admin consent for [Your Organization]** and confirm.

---

## 🔌 WordPress Plugin User Guide

### 1. Installation
1. Locate [`plugin/schedule_backup_plugin.zip`](file:///c:/backup_plugin/plugin/schedule_backup_plugin.zip).
2. In WordPress Admin, navigate to **Plugins** &rarr; **Add New Plugin** &rarr; **Upload Plugin**.
3. Select `schedule_backup_plugin.zip` and click **Install Now** &rarr; **Activate Plugin**.
4. A new menu item **SharePoint Backup** will appear in the main WordPress sidebar.

### 2. Configuration (Tab 2: SharePoint & OAuth Config)
Fill in your credentials from Azure:
- **Tenant ID**: Azure Directory (tenant) ID
- **Client ID**: Azure Application (client) ID
- **Client Secret**: Azure Client Secret value
- **SharePoint Site URL**: e.g., `https://yourorg.sharepoint.com/sites/MarketingSite`
- **Document Library**: Destination library (default: `Documents` or `Shared Documents`)
- **Remote Root Folder**: Cloud folder name (default: `WordPress Backups`)
- Click **Save Configuration**, then switch to Tab 1 to run **Test SharePoint Connection**.

### 3. Setting Up Automation (Tab 3: Automation & Retention)
- **Backup Frequency**:
  - `Manual Only`: Automatic backups disabled.
  - `Daily at Specific Time`: Choose execution time (e.g., `02:00` for 2:00 AM).
  - `Weekly on Specific Day & Time`: Select day of the week (Sunday–Saturday) and time.
  - `Monthly on Specific Date & Time`: Select day of month (1–28) and time.
  - `One-Time on Specific Date & Time`: Select exact calendar date and time.
- **Retention Policy**: Select how many backups to keep on SharePoint (e.g., `Keep last 30 backups`).

### 4. Viewing Logs & Testing (Tab 1 & Tab 4)
- Click **Run Backup Now (Test Workflow)** on Tab 1 to test immediate execution.
- Switch to **Execution Logs** (Tab 4) to monitor real-time database exports, ZIP creation, and SharePoint upload progress.

---

## 🪟 Windows Setup & Deployment Guide (Dashboard)

You can run the Central Dashboard on Windows using **Laragon**, **XAMPP**, or standalone **PHP 8.3 CLI**.

### Option A: Laragon (Recommended for Windows)
1. Place the `dashboard` folder inside `C:\laragon\www\dashboard`.
2. In Laragon terminal (or PowerShell):
   ```powershell
   cd C:\laragon\www\dashboard
   composer install
   cp .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   ```
3. Laragon will automatically map the dashboard to `http://dashboard.test` (or run `php artisan serve`).

---

## 🐧 Linux Server Deployment Guide (Dashboard)

This guide takes you step-by-step from a fresh, clean Linux server (Ubuntu 22.04 / 24.04 LTS or Debian 12) through **`git clone`**, dependency installation, database configuration, asset compilation, and web server setup until the **Central Dashboard is fully live in production with HTTPS**.

---

### 📋 Prerequisites & Server Requirements

| Component | Minimum Requirement | Recommended |
| :--- | :--- | :--- |
| **OS** | Ubuntu 22.04 LTS / 24.04 LTS or Debian 12 | Ubuntu 24.04 LTS |
| **CPU / RAM** | 1 vCPU, 1 GB RAM (with swap) | 2 vCPU, 2 GB+ RAM |
| **PHP** | PHP 8.3 (CLI & FPM) with standard extensions | PHP 8.3 FPM |
| **Node.js** | Node.js 18+ & NPM (for compiling Vite assets) | Node.js 20+ LTS |
| **Web Server** | Nginx 1.18+ or Apache 2.4+ | Nginx 1.24+ |
| **Database** | SQLite 3 (simplest) OR MySQL 8.0 / MariaDB 10.11 | SQLite (Fleet < 50 sites) / MySQL (50+ sites) |
| **Firewall** | Inbound TCP ports: `22` (SSH), `80` (HTTP), `443` (HTTPS) | UFW or Cloud Security Group |

---

### Step 1: Update Server & Install System Dependencies

Update packages and install essential utilities, Git, Nginx, and PHP 8.3.

> [!NOTE]
> Ubuntu 22.04 repositories default to PHP 8.1. Adding the `ondrej/php` PPA ensures access to PHP 8.3 and all required extensions.

```bash
# 1. Update package lists and upgrade system
sudo apt update && sudo apt upgrade -y

# 2. Install core utilities & Git
sudo apt install -y software-properties-common curl git unzip ufw ca-certificates gnupg

# 3. Add Ondřej Surý PHP PPA
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

# 4. Install Nginx and PHP 8.3 with required extensions
sudo apt install -y nginx \
  php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mysql php8.3-sqlite3 php8.3-curl \
  php8.3-mbstring php8.3-xml php8.3-zip \
  php8.3-intl php8.3-bcmath php8.3-gd

# 5. Verify PHP version
php -v
# Output should show: PHP 8.3.x
```

---

### Step 2: Install Composer & Node.js (LTS)

The dashboard requires **Composer** for backend dependencies and **Node.js/NPM** to compile Tailwind CSS and Vite assets.

```bash
# --- Install Composer globally ---
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
composer --version

# --- Install Node.js 20 LTS (NodeSource repository) ---
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

---

### Step 3: Clone the Repository via Git

Clone the project repository into the standard Linux web root directory (`/var/www`).

```bash
# 1. Navigate to /var/www
cd /var/www

# 2. Clone the repository
sudo git clone https://github.com/yiexiant/WordPress_integrate_SharePoint.git wp-sharepoint-suite

# 3. Switch into the central dashboard directory
cd /var/www/wp-sharepoint-suite/dashboard
```

> [!IMPORTANT]
> The repository contains two folders: `dashboard/` (the Laravel application) and `plugin/` (the WordPress plugin). All server commands below must be executed from within `/var/www/wp-sharepoint-suite/dashboard`.

---

### Step 4: Install PHP & Node Dependencies

Install production backend packages and compile front-end assets with Vite.

```bash
# 1. Install Composer dependencies (optimized for production)
composer install --no-dev --optimize-autoloader

# 2. Install NPM packages and build production assets
npm install
npm run build
```

> [!TIP]
> Running `npm run build` is critical. It compiles Tailwind CSS and outputs the `public/build/manifest.json` bundle. Without this step, visiting the dashboard will return a `ViteManifestNotFoundException` 500 error.

---

### Step 5: Environment Configuration (`.env`)

Copy the environment template and generate a unique application encryption key:

```bash
# 1. Copy environment template
cp .env.example .env

# 2. Generate unique application key
php artisan key:generate

# 3. Edit .env with your production domain and settings
nano .env
```

Set the following variables in `/var/www/wp-sharepoint-suite/dashboard/.env`:

```ini
APP_NAME="SharePoint Backup Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashboard.yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=error

# Choose your database connection below (Step 6)
DB_CONNECTION=sqlite
```

---

### Step 6: Database Setup & Migrations

Choose either **Option A (SQLite)** or **Option B (MySQL)**:

#### Option A: SQLite (Recommended — Fastest & Zero Maintenance)

For most fleets (< 100 WordPress sites), SQLite is lightweight, self-contained, and requires zero external database server management.

```bash
# 1. Create SQLite database file
touch database/database.sqlite

# 2. Verify in .env that DB_CONNECTION is set to sqlite:
# DB_CONNECTION=sqlite
# (Ensure DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD remain commented out)

# 3. Run database migrations
php artisan migrate --force
```

#### Option B: MySQL / MariaDB (Enterprise Fleets)

If you prefer a dedicated MySQL database:

```bash
# 1. Install MySQL Server (if not already installed)
sudo apt install -y mysql-server

# 2. Create database and user in MySQL
sudo mysql -e "CREATE DATABASE wp_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'wp_dash_user'@'localhost' IDENTIFIED BY 'StrongSecretPassword123!';"
sudo mysql -e "GRANT ALL PRIVILEGES ON wp_dashboard.* TO 'wp_dash_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# 3. Update .env variables:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=wp_dashboard
# DB_USERNAME=wp_dash_user
# DB_PASSWORD=StrongSecretPassword123!

# 4. Run database migrations
php artisan migrate --force
```

---

### Step 7: Permissions & Security Hardening

Nginx and PHP-FPM run as the `www-data` user on Ubuntu/Debian. The web server must own the dashboard files and have write permissions for storage, cache, and the database.

```bash
# 1. Set directory ownership to www-data
sudo chown -R www-data:www-data /var/www/wp-sharepoint-suite/dashboard

# 2. Set standard file and folder permissions
sudo find /var/www/wp-sharepoint-suite/dashboard -type f -exec chmod 644 {} \;
sudo find /var/www/wp-sharepoint-suite/dashboard -type d -exec chmod 755 {} \;

# 3. Grant write permissions to writable storage and bootstrap cache
sudo chmod -R 775 /var/www/wp-sharepoint-suite/dashboard/storage
sudo chmod -R 775 /var/www/wp-sharepoint-suite/dashboard/bootstrap/cache

# 4. (If using SQLite) Grant write permissions to the database folder and file
sudo chmod -R 775 /var/www/wp-sharepoint-suite/dashboard/database
sudo chmod 664 /var/www/wp-sharepoint-suite/dashboard/database/database.sqlite

# 5. Restrict access to sensitive .env file
sudo chmod 640 /var/www/wp-sharepoint-suite/dashboard/.env
```

---

### Step 8: Optimize Laravel for Production

Cache application configuration, routes, and views into compiled files to maximize performance:

```bash
cd /var/www/wp-sharepoint-suite/dashboard

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> [!TIP]
> Whenever you modify your `.env` file or pull new code, run `php artisan optimize:clear` followed by `php artisan optimize` to refresh the compiled cache.

---

### Step 9: Configure Nginx Virtual Host

Create a dedicated Nginx configuration for the dashboard.

```bash
sudo nano /etc/nginx/sites-available/dashboard.conf
```

Paste the following production configuration (replace `dashboard.yourdomain.com` with your actual domain or server IP):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name dashboard.yourdomain.com;

    # Document root MUST point to Laravel's public directory
    root /var/www/wp-sharepoint-suite/dashboard/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php index.html;
    charset utf-8;

    # Allow larger payloads if needed
    client_max_body_size 64M;

    # Main routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    # Pass PHP scripts to FastCGI server
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Increased timeouts for long-running remote backup triggers and verification
        fastcgi_connect_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_read_timeout 300s;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
    }

    # Deny access to hidden files (.env, .git, etc.)
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the configuration, test for syntax errors, and reload Nginx:

```bash
# 1. Link site to sites-enabled
sudo ln -s /etc/nginx/sites-available/dashboard.conf /etc/nginx/sites-enabled/

# 2. Test Nginx syntax
sudo nginx -t

# 3. Reload Nginx
sudo systemctl reload nginx
```

---

### Step 10: Enable HTTPS with Free Let's Encrypt SSL

Secure your dashboard with an automated SSL certificate using Certbot:

```bash
# 1. Install Certbot and the Nginx plugin
sudo apt install -y certbot python3-certbot-nginx

# 2. Obtain and automatically configure SSL certificate
sudo certbot --nginx -d dashboard.yourdomain.com

# 3. Test automatic certificate renewal
sudo certbot renew --dry-run
```

Certbot will automatically modify your Nginx virtual host to redirect all HTTP traffic to HTTPS.

---

### Step 11: Setup Automated Laravel Scheduler (Cron)

The dashboard scheduler handles automatic polling, backup verification, and database cleanups:

```bash
# Add Laravel scheduler to www-data crontab
(sudo crontab -u www-data -l 2>/dev/null; echo "* * * * * php /var/www/wp-sharepoint-suite/dashboard/artisan schedule:run >> /dev/null 2>&1") | sudo crontab -u www-data -

# Verify crontab entry
sudo crontab -u www-data -l
```

---

### Step 12: Production Smoke Test & Verification

1. Open your browser and navigate to `https://dashboard.yourdomain.com`.
2. You should be greeted by the clean **Red & White Central Management Dashboard**.
3. Verify that cards for **Total Managed Sites**, **SharePoint Synced**, and **Missing / Desynced** load cleanly without errors.
4. Click **Register New Website** to pair your first WordPress site using its `X-SPSYNC-KEY`.

---

### 🔄 Updating the Production Dashboard (Future Git Pulls)

Whenever you push updates or new features to GitHub, update your production server with this single workflow:

```bash
cd /var/www/wp-sharepoint-suite/dashboard

# 1. Put Laravel into maintenance mode (optional, for zero downtime)
php artisan down || true

# 2. Pull the latest commits
git pull origin main

# 3. Update Composer & NPM packages
composer install --no-dev --optimize-autoloader
npm install
npm run build

# 4. Run any new database migrations
php artisan migrate --force

# 5. Clear and refresh production caches
php artisan optimize:clear
php artisan optimize

# 6. Restart PHP-FPM to release OPcache
sudo systemctl restart php8.3-fpm

# 7. Bring application back online
php artisan up
```

---

## 🖥️ Central Dashboard Configuration & Site Pairing

### Pairing a WordPress Site with Central Dashboard

1. Open your target WordPress Admin &rarr; **SharePoint Backup** &rarr; **Overview & Testing**.
2. Locate and copy the **Central Dashboard Secret API Key** (`X-SPSYNC-KEY`).
3. Open your Central Dashboard (`https://dashboard.yourdomain.com`).
4. Click **Register New Website** (or **+ Add Site**).
5. Select Purpose:
   - **Routine Backup**: Monitored production website scheduled for periodic disaster recovery backups.
   - **Migration Target**: Staging, development, or new server target for cloning and URL search-and-replace.
6. Provide:
   - **Website Name**: e.g., `Corporate Main Site`
   - **WordPress Site URL**: e.g., `https://example.com` (must include `https://`)
   - **Secret API Key**: Paste the `X-SPSYNC-KEY` from Step 2.
7. Click **Save & Test Connection**. The dashboard will perform an immediate handshake with the WordPress REST API endpoint (`/wp-json/spsync/v1/health`).

---

## ⚙️ Automation & Retention Explained

### Backup Schedule Options

Backups are executed natively via the WordPress ActionScheduler engine or WordPress pseudo-cron without requiring external server triggers:

- **Daily at Specific Time**: Runs every 24 hours at the chosen hour and minute (e.g., `02:00` for 2:00 AM server time).
- **Weekly on Specific Day & Time**: Runs once every 7 days on the selected day of the week (e.g., Sunday at `03:00`).
- **Monthly on Specific Date & Time**: Runs on a designated day of the month (e.g., the 1st of each month at `01:00`).
- **One-Time on Specific Date & Time**: Sets an isolated one-off execution timestamp and automatically transitions to `Manual Only` once completed.

### Cloud Retention Pruning Mechanism

To prevent infinite storage consumption in your Microsoft SharePoint Document Library:

1. After every successful backup upload, the plugin queries Microsoft Graph API for all items located in your configured SharePoint folder (`Remote Root Folder`).
2. It filters items matching the naming pattern `backup-*.zip`.
3. It sorts the files by creation date (newest first).
4. If total files exceed your configured threshold (e.g., **30 backups**), the oldest excess files are deleted via `DELETE /drives/{drive-id}/items/{item-id}`.
5. All pruned files are logged in the plugin's Execution Logs table.

---

## 🛠️ Troubleshooting & FAQ

### 1. `ViteManifestNotFoundException` (500 Internal Server Error)
- **Cause**: Frontend assets have not been built on the server.
- **Fix**: Run `npm install && npm run build` inside `/var/www/wp-sharepoint-suite/dashboard`.

### 2. SQLite: `General error: 14 unable to open database file`
- **Cause**: PHP-FPM (`www-data`) lacks write permissions to the database file or the enclosing `database/` directory (SQLite requires directory write permissions to manage journal and lock files).
- **Fix**:
  ```bash
  sudo chown -R www-data:www-data /var/www/wp-sharepoint-suite/dashboard/database
  sudo chmod -R 775 /var/www/wp-sharepoint-suite/dashboard/database
  sudo chmod 664 /var/www/wp-sharepoint-suite/dashboard/database/database.sqlite
  ```

### 3. Nginx: `504 Gateway Time-out` during Remote Backup or Verification
- **Cause**: The backup operation or SharePoint scan exceeded Nginx's default 60-second FastCGI timeout.
- **Fix**: Ensure your Nginx configuration contains increased timeouts inside the `location ~ \.php$` block:
  ```nginx
  fastcgi_read_timeout 300s;
  ```
  Then reload Nginx with `sudo systemctl reload nginx`.

### 4. Dashboard Shows `WordPress Connection: Failed (401 Unauthorized)`
- **Cause**: The `X-SPSYNC-KEY` entered in the Central Dashboard does not match the secret key shown in WordPress Admin.
- **Fix**: Open WordPress Admin &rarr; **SharePoint Backup** &rarr; **Overview & Testing**, copy the key, and update it in Dashboard &rarr; **Edit Website**.

### 5. Dashboard Shows `cURL error 28: Operation timed out`
- **Cause**: The server hosting the Central Dashboard cannot reach the WordPress server on port 443, or a security firewall (e.g., Cloudflare WAF, Wordfence) is blocking incoming requests with custom headers.
- **Fix**: Whitelist your Central Dashboard server IP in Cloudflare or your WordPress security plugin.

---

## 📄 License & Credits

Developed with enterprise resilience standards for WordPress disaster recovery and Microsoft SharePoint cloud synchronization.
Licensed under the MIT License.
