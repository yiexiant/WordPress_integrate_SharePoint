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

Deploying the **Central Laravel Dashboard** on a production Linux server (Ubuntu 22.04 / 24.04 LTS):

### 1. Install System Dependencies
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server curl git unzip \
php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd \
php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl php8.3-bcmath php8.3-sqlite3

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. Deploy Dashboard Codebase
```bash
sudo mkdir -p /var/www/dashboard
sudo cp -r /path/to/dashboard/* /var/www/dashboard/
cd /var/www/dashboard

# Install Composer packages
composer install --no-dev --optimize-autoloader

# Environment setup
cp .env.example .env
php artisan key:generate

# Set permissions
sudo chown -R www-data:www-data /var/www/dashboard
sudo chmod -R 775 /var/www/dashboard/storage /var/www/dashboard/bootstrap/cache

# Run database migrations
php artisan migrate --force
```

### 3. Configure Nginx Virtual Host
Create `/etc/nginx/sites-available/dashboard`:
```nginx
server {
    listen 80;
    server_name dashboard.yourdomain.com;
    root /var/www/dashboard/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
Enable site:
```bash
sudo ln -s /etc/nginx/sites-available/dashboard /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 🖥️ Central Dashboard Configuration & Site Pairing

### Pairing a WordPress Site with Central Dashboard
1. Open your WordPress Admin &rarr; **SharePoint Backup** &rarr; **Overview & Testing**.
2. Locate and copy the **Central Dashboard Secret API Key** (`X-SPSYNC-KEY`).
3. Open your Central Dashboard (`http://127.0.0.1:8000` or `https://dashboard.yourdomain.com`).
4. Click **Register New Website**.
5. Select Purpose: **Routine Backup** or **Migration Target**.
6. Provide Website Name, WordPress Site URL, and Secret API Key.
7. Click **Save & Test Connection**.

---

## 📄 License & Credits

Developed with enterprise resilience standards for WordPress disaster recovery and Microsoft SharePoint cloud synchronization.
Licensed under the MIT License.
