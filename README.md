# SharePoint Automated Backup & Restore System for WordPress

A enterprise-grade multi-website management system and WordPress plugin designed for automated database exports, file archiving, and cloud storage synchronization with **Microsoft SharePoint** via Microsoft Graph API.

---

## 🏗️ System Architecture

The architecture consists of two main components:

```
┌────────────────────────────────────────────────────────┐
│             Central Management Dashboard               │
│               (Laravel 11 / PHP 8.3)                   │
│                                                        │
│  - Multi-site monitoring & status aggregation         │
│  - Real-time live status polling (Active Jobs, Health) │
│  - Remote Triggering (Run Backup / Restore)            │
│  - Audit Trail Logging & Security Alert Feed           │
└──────────────────────────┬─────────────────────────────┘
                           │
             REST API (HTTP / HTTPS + X-SPSYNC-KEY)
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│           WordPress SharePoint Backup Plugin            │
│                (WordPress 6.0+ Plugin)                 │
│                                                        │
│  - Automated MySQL DB Dump                             │
│  - Split ZIP Archiving (ZipArchive / PclZip dual engine)│
│  - Microsoft Graph API Cloud Storage Integration       │
│  - Pre-Restore Safety Rollback Snapshot                │
│  - Remote Storage Verification (Detect missing folders) │
└──────────────────────────┬─────────────────────────────┘
                           │
                Microsoft Graph API v1.0
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│           Microsoft SharePoint Cloud Storage            │
│              (Azure App Registration OAuth2)           │
└────────────────────────────────────────────────────────┘
```

1. **Central Dashboard (`backup_dashboard`)**:
   - Built on **Laravel 11**.
   - Serves as the central command console to register, monitor, trigger backups/restores, and view health diagnostics across all WordPress websites.

2. **WordPress Plugin (`schedule_backup_plugin`)**:
   - Placed directly on the primary WordPress Admin side panel as **SharePoint Backup**.
   - Handles local database dumps, split file zipping, chunked cloud uploads to SharePoint, and automatic safety rollbacks.

---

## ⭐ Key Features

- **Primary WordPress Admin Sidebar Placement**: Clean, card-based interface with tabbed navigation (Overview, Credentials, Automation, Execution Logs).
- **Manual "Run Backup Now" Testing**: Instant execution trigger for testing database exports and cloud uploads on demand.
- **Dual Zipping Engine**: Uses native PHP `ZipArchive` when available, with automatic fallback to WordPress core `PclZip` for 100% compatibility across all web hosting environments.
- **Chunked Cloud Upload**: Supports large file uploads (>4MB) via Microsoft Graph API upload sessions.
- **Pre-Restore Safety Rollback**: Automatically creates an emergency snapshot (`/spsync-backups/rollback`) before restoring site files or database tables.
- **Remote SharePoint Verification**: Automatically queries Microsoft Graph API during site syncs to detect deleted SharePoint folders and flag missing remote storage.
- **Real-Time Live Dashboard Polling**: Central dashboard updates site availability, active running job badges, and progress counters live without requiring page refreshes.

---

## 🐧 Linux Server Deployment & Configuration Guide

Follow these steps to deploy both the **Central Laravel Dashboard** and the **WordPress Plugin** on a Linux server (e.g. **Ubuntu 22.04 / 24.04 LTS**, Debian, or RHEL/CentOS).

---

### 1. Linux Server Prerequisites

Ensure the following packages and PHP extensions are installed on your Linux server:

```bash
# Update package repositories
sudo apt update && sudo apt upgrade -y

# Install Nginx, MySQL, and PHP 8.3 dependencies
sudo apt install -y nginx mysql-server curl git unzip \
php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd \
php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl php8.3-bcmath

# Install Composer globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

### 2. Configuring PHP Settings (`php.ini`)

Edit your Linux PHP configuration files:
- FPM: `/etc/php/8.3/fpm/php.ini`
- CLI: `/etc/php/8.3/cli/php.ini`

Apply these recommended directives for handling large site backups:

```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
extension=zip
```

Restart PHP-FPM service:
```bash
sudo systemctl restart php8.3-fpm
```

---

### 3. Deploying Central Dashboard (`backup_dashboard`)

#### A. Copy & Environment Setup
```bash
# Navigate to web root directory
cd /var/www/

# Copy or clone backup_dashboard folder
sudo cp -r /path/to/backup_dashboard /var/www/backup_dashboard
cd /var/www/backup_dashboard

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Create environment configuration
cp .env.example .env
php artisan key:generate
```

#### B. Database Configuration
Create a MySQL database and user for the dashboard:

```sql
CREATE DATABASE backup_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dashboard_user'@'localhost' IDENTIFIED BY 'YourSecurePasswordHere';
GRANT ALL PRIVILEGES ON backup_dashboard.* TO 'dashboard_user'@'localhost';
FLUSH PRIVILEGES;
```

Update your `/var/www/backup_dashboard/.env` file:
```ini
APP_NAME="Backup Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashboard.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=backup_dashboard
DB_USERNAME=dashboard_user
DB_PASSWORD=YourSecurePasswordHere
```

Run database migrations:
```bash
php artisan migrate --force
```

#### C. Directory Permissions
Set proper Linux ownership and file permissions for Nginx & PHP-FPM:

```bash
sudo chown -R www-data:www-data /var/www/backup_dashboard
sudo chmod -R 775 /var/www/backup_dashboard/storage /var/www/backup_dashboard/bootstrap/cache
```

#### D. Nginx Server Block Configuration
Create `/etc/nginx/sites-available/backup_dashboard`:

```nginx
server {
    listen 80;
    server_name dashboard.yourdomain.com;
    root /var/www/backup_dashboard/public;

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

Enable site and reload Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/backup_dashboard /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### E. System Cron Daemon Setup (For Automated Scheduler)
Add Laravel task scheduler to the Linux system crontab:

```bash
sudo crontab -u www-data -e
```

Add the following line:
```cron
* * * * * cd /var/www/backup_dashboard && php artisan schedule:run >> /dev/null 2>&1
```

---

### 4. Deploying WordPress Plugin (`schedule_backup_plugin`)

#### A. Installation
1. Copy `schedule_backup_plugin` folder into your Linux WordPress installation:
   ```bash
   sudo cp -r /path/to/schedule_backup_plugin /var/www/html/wp-content/plugins/
   sudo chown -R www-data:www-data /var/www/html/wp-content/plugins/schedule_backup_plugin
   ```
2. Activate the plugin:
   - Log into WordPress Admin -> **Plugins** -> Activate **SharePoint Backup**.
   - Or using WP-CLI:
     ```bash
     wp plugin activate schedule_backup_plugin --path=/var/www/html
     ```

#### B. Plugin Configuration
1. In WordPress Admin sidebar, click **SharePoint Backup**.
2. Go to **SharePoint & OAuth Config** tab.
3. Enter your Microsoft Azure App credentials:
   - **Tenant ID**: Azure Active Directory Directory (tenant) ID.
   - **Client ID**: Azure Application (client) ID.
   - **Client Secret**: Azure Client Secret value.
   - **Site URL**: Full SharePoint Site URL (e.g., `https://yourdomain.sharepoint.com/sites/YourSite`).
   - **Document Library**: SharePoint Library Name (default: `Documents`).
   - **Root Folder**: Destination folder name (default: `WordPress Backups`).
4. Click **Save Configuration**, then click **Test Connection**.

---

### 5. Connecting WordPress Site to Central Dashboard

1. On your WordPress site, go to **SharePoint Backup** -> **Overview & Testing**.
2. Copy the generated **API Key** (`X-SPSYNC-KEY`).
3. Log into your Central Dashboard (`https://dashboard.yourdomain.com`).
4. Click **Add New Website**.
5. Enter Website Name, URL (`https://yourwordpresssite.com`), and paste the **API Key**.
6. Click **Save Website**, then click **Sync Connection**.

---

## 🔒 Security Best Practices

- **Enforce HTTPS**: Always run both the Central Dashboard and WordPress sites under SSL/TLS (HTTPS).
- **API Key Security**: Keep the `X-SPSYNC-KEY` confidential. It authenticates REST API calls between Dashboard and WordPress.
- **Restrict File Permissions**: Ensure `/spsync-backups` directory in `wp-content/uploads/` is protected from direct web browsing.
