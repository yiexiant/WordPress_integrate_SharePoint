# Deployment Guide: WordPress SharePoint Backup Plugin

This guide takes the plugin (version **1.4.0**) from this repository to a production WordPress site. The site ends
up backing up automatically to SharePoint and reporting to the central dashboard.

Deploy in this order: **(1)** prepare Microsoft Entra ID and SharePoint once per tenant, **(2)** check the server,
**(3)** install the plugin on each website, **(4)** configure it, **(5)** connect it to the dashboard
(see [`../dashboard/DEPLOYMENT.md`](../dashboard/DEPLOYMENT.md)), **(6)** verify with a test restore.

---

## Contents

1. [How it works](#1-how-it-works)
2. [Requirements](#2-requirements)
3. [One-time: Microsoft Entra ID and SharePoint](#3-one-time-microsoft-entra-id-and-sharepoint)
4. [Prepare the WordPress server](#4-prepare-the-wordpress-server)
5. [Build and install the plugin](#5-build-and-install-the-plugin)
6. [Configure the plugin](#6-configure-the-plugin)
7. [Connect the site to the central dashboard](#7-connect-the-site-to-the-central-dashboard)
8. [Verify the deployment](#8-verify-the-deployment)
9. [Rolling out to many websites](#9-rolling-out-to-many-websites)
10. [Upgrading the plugin](#10-upgrading-the-plugin)
11. [Security checklist](#11-security-checklist)
12. [Troubleshooting](#12-troubleshooting)
13. [Deactivation and removal](#13-deactivation-and-removal)

---

## 1. How it works

```
WordPress site ── plugin ──► Microsoft Entra ID (client-credentials token)
                     │
                     └──────► Microsoft Graph ──► SharePoint document library
                                                   └─ {Root folder}/{Site folder}/spsync-backup-YYYYMMDD-HHMMSS/
                                                        ├─ database.sql
                                                        ├─ backup_part001.zip …
                                                        └─ manifest.json (sizes + SHA-256)
Central dashboard ── HTTPS + X-SPSYNC-KEY ──► plugin REST API (?rest_route=/spsync/v1/…)
```

- Backups are built on the WordPress server and staged in `wp-content/uploads/spsync-backups/<random token>/`.
  Background uploads then send them to SharePoint in chunks, and the local copy is removed once every file has arrived.
- Each website writes into **its own site folder** under a shared root folder, so many websites can share one
  SharePoint library without seeing, verifying or pruning each other's backups.
- Scheduling uses **WP-Cron**, or **Action Scheduler** when another plugin (for example WooCommerce) provides it.

---

## 2. Requirements

| Item | Requirement |
|---|---|
| WordPress | 6.0 or newer, single site (multisite networks are not supported) |
| PHP | 7.4 or newer (tested on 8.3) |
| PHP extensions | `openssl` (required: encrypts the client secret and talks to Microsoft), `curl`, `zip` (recommended; WordPress's PclZip is a slower fallback), `json`, `mysqli` |
| Outbound HTTPS | `login.microsoftonline.com` and `graph.microsoft.com` (port 443), plus SharePoint download hosts (`*.sharepoint.com`) for restores |
| Disk space | At least **2–3× the site size** free: database dump + ZIP parts while uploading, more during a restore (downloaded backup + safety snapshot) |
| PHP limits | `memory_limit` ≥ 256M, `max_execution_time` 300+ (or 0 for CLI cron); for uploads in **Manual Restore**, `upload_max_filesize`/`post_max_size` larger than the backup ZIP |
| Temp directory | A writable directory **outside** the WordPress folder and web root, for manual restores and device downloads (defaults to the system temp directory) |
| Microsoft 365 | A SharePoint Online site and an Entra ID account that can register apps and grant admin consent |

---

## 3. One-time: Microsoft Entra ID and SharePoint

Do this **once per Microsoft 365 tenant**. Every website can reuse the same app registration and library; each
site gets its own folder.

### 3.1 Create the SharePoint location

1. Choose or create a SharePoint site, for example `https://contoso.sharepoint.com/sites/WebsiteBackups`.
2. Note the **document library** name. The default library is called **Documents**; its URL segment is often
   `Shared Documents`, but the plugin needs the display name, `Documents`.
3. The plugin creates the root folder (default `WordPress Backups`) and the per-site folders automatically.

### 3.2 Register the application

1. Go to **https://entra.microsoft.com** → **Identity → Applications → App registrations → New registration**.
2. Name: `WordPress SharePoint Backup`. Supported account types: **Single tenant**. Leave Redirect URI empty. Click **Register**.
3. On the **Overview** page copy:
   - **Directory (tenant) ID** → plugin field *Tenant ID*
   - **Application (client) ID** → plugin field *Client ID*
4. **Certificates & secrets → Client secrets → New client secret**. Choose an expiry (for example 12 or 24 months)
   and copy the secret's **Value** immediately; it is shown only once. → plugin field *Client Secret*.
   Put the expiry date in your calendar: **when the secret expires, every backup fails** (see §11).

### 3.3 Grant Microsoft Graph permissions

The plugin signs in as the application (client-credentials flow, scope `https://graph.microsoft.com/.default`) and
needs to read, create and delete files in the library. Choose **one** option.

**Option A, simplest: `Sites.ReadWrite.All` (Application)**

1. **API permissions → Add a permission → Microsoft Graph → Application permissions → `Sites.ReadWrite.All`** → Add.
2. Click **Grant admin consent for <tenant>** and confirm. Status must show a green check.

This lets the app write to **every** SharePoint site in the tenant. Use Option B if that is too broad.

**Option B, least privilege: `Sites.Selected` (Application)**

1. Add the Microsoft Graph **Application** permission **`Sites.Selected`** and grant admin consent.
2. A SharePoint/Global administrator then grants the app **write** access to the one backup site. For example, with
   Microsoft Graph PowerShell:

   ```powershell
   Connect-MgGraph -Scopes "Sites.FullControl.All"
   $site = Get-MgSite -SiteId "contoso.sharepoint.com:/sites/WebsiteBackups"
   New-MgSitePermission -SiteId $site.Id -BodyParameter @{
       roles = @("write")
       grantedToIdentities = @(@{ application = @{ id = "<Application (client) ID>"; displayName = "WordPress SharePoint Backup" } })
   }
   ```

   Without this site grant the plugin authenticates successfully, but SharePoint calls fail with *Access denied* (HTTP 403).

> Permission changes can take a few minutes to apply. If **Test SharePoint Connection** fails right after granting
> consent, wait 5–10 minutes and try again.

---

## 4. Prepare the WordPress server

### 4.1 Reliable scheduling (strongly recommended)

WP-Cron only runs when someone visits the site, so a quiet site may back up late or stall mid-upload. In production,
run cron from the operating system instead:

1. In `wp-config.php`, above `/* That's all, stop editing! */`:

   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. Add a system cron job that fires every 5 minutes:

   ```cron
   */5 * * * * cd /var/www/example.com && wp cron event run --due-now --quiet
   # or, without WP-CLI:
   */5 * * * * curl -fsS https://www.example.com/wp-cron.php?doing_wp_cron >/dev/null
   ```

   On Windows/IIS use Task Scheduler with the same `wp` or `curl` command. Managed hosts usually offer a "cron jobs" panel.

### 4.2 PHP limits

Raise these in `php.ini`, `.user.ini` or your host's control panel when needed:

```ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 2G   ; only needed for Manual Restore uploads
post_max_size = 2G
```

### 4.3 Protect the local staging folder (Nginx only)

The plugin writes deny rules for **Apache** (2.2/2.4) and **IIS** automatically, and stores files under an
unguessable folder name. **Nginx ignores those rules**, so add this to the site's `server { }` block and reload Nginx:

```nginx
location ~* ^/wp-content/uploads/spsync-backups/ {
    deny all;
    return 404;
}
```

Test it: `curl -I https://www.example.com/wp-content/uploads/spsync-backups/` must **not** return `200`.

### 4.4 Private temporary directory (optional)

Manual restores and device downloads need a writable directory outside the WordPress folder and web root. The system
temp directory is used by default. To pick another one, add to a must-use plugin (`wp-content/mu-plugins/spsync.php`):

```php
<?php
add_filter('spsync_restore_temp_dir', fn () => '/var/lib/spsync-tmp'); // must exist, be writable, outside the web root
// Optional: allow larger expanded backup parts during restore (default 2 GiB).
add_filter('spsync_restore_max_expanded_bytes', fn () => 5 * 1024 ** 3);
```

---

## 5. Build and install the plugin

### 5.1 Build the package

The repository contains a ready-made package: `plugin/schedule_backup_plugin.zip`. If you changed any plugin code,
run the tests and rebuild it first:

```sh
php plugin/tests/run.php          # must print: PASS: N regression checks
php plugin/bin/build-zip.php      # rewrites plugin/schedule_backup_plugin.zip
```

The ZIP contains one folder, `schedule-backup-plugin/`, with the application code and README. Tests, build tools and
specs are excluded.

### 5.2 Install

**WordPress admin (any host):**
1. **Plugins → Add New Plugin → Upload Plugin** → choose `schedule_backup_plugin.zip` → **Install Now**.
2. Click **Activate**.

**WP-CLI:**

```sh
wp plugin install /path/to/schedule_backup_plugin.zip --activate
```

Activation creates four tables (`{prefix}spsync_settings`, `spsync_logs`, `spsync_items`, `spsync_restore_items`)
and generates the site's **Secret API Key**. A new install defaults to **manual backups only**; automatic backups
start once you configure them in §6.

---

## 6. Configure the plugin

Open **WordPress admin → SharePoint Backup**.

### 6.1 SharePoint & OAuth Config tab

| Field | Value |
|---|---|
| Tenant ID | Directory (tenant) ID from §3.2 |
| Client ID | Application (client) ID from §3.2 |
| Client Secret | Secret **Value** from §3.2 (keep **Encrypt Client Secret in Database** ticked) |
| SharePoint Site URL | Full site URL, for example `https://contoso.sharepoint.com/sites/WebsiteBackups` |
| Document Library | Library display name, usually `Documents` |
| Root Backup Folder | Shared folder for all websites, for example `WordPress Backups` |
| Site Folder | This website's own folder. Defaults to its host name (e.g. `www.example.com`). **Choose it before the first backup.** Renaming later hides older backups from retention and verification. Never give two websites the same site folder. |

Click **Save**, then go to **Overview & Testing → Test SharePoint Connection**. Success means the plugin signed in,
found the site and library, and created or confirmed the root folder and this site's folder.

### 6.2 Backup Contents tab

- **Entire website and database** (default): full backup. Caches, logs, `.git`, `node_modules` and existing archives are skipped.
- **Only selected wp-content directories**: pick folders (plugins, themes, uploads…) and optionally the database.

### 6.3 Backup Method & Schedule tab

1. Choose **Automatic backups to SharePoint**.
2. Pick **Daily**, **Weekly** (choose the day) or **Monthly** (day 1–28), and a time. Times use the timezone in
   **Settings → General**.
3. Set the **SharePoint Retention Policy**: how many of this website's backups to keep on SharePoint (default 30; older ones are deleted
   after each successful backup; `0` keeps everything).
4. **Save**. The dashboard marks the site **Backup overdue** if no successful backup arrives within the schedule plus 6 hours.

### 6.4 First backup

**Overview & Testing → Back Up to SharePoint Now**, then watch **Execution Logs**. A healthy run logs the database export,
ZIP parts, and `All backup files uploaded to SharePoint successfully`. In SharePoint you should now see
`WordPress Backups/<site folder>/spsync-backup-…/` containing `manifest.json`, `database.sql` and `backup_part*.zip`.

---

## 7. Connect the site to the central dashboard

1. **Overview & Testing** tab → copy the **Central Dashboard Secret API Key**.
2. In the dashboard: **Add website** → name, the site's exact public address (including `https://` and `www` if
   used) and the API key.
3. Click **Check connection**. The site should show **Healthy**, or a plain-language reason.

The dashboard calls `https://<site>/?rest_route=/spsync/v1/...`, which works with every permalink setting. If a
firewall or security plugin (Wordfence, Cloudflare WAF…) filters REST requests, allow requests to `rest_route=/spsync/v1/`
that carry the `X-SPSYNC-KEY` header, from the dashboard server's IP address.

**Rotating the key.** The admin screen has no regenerate button. Rotate the key with WP-CLI, copy the new key from
**Overview & Testing**, and paste it under **Edit** in the dashboard. The dashboard is disconnected until you do.

```sh
wp eval 'SPSync\Helpers\Settings::set("api_key", bin2hex(random_bytes(32))); echo "API key rotated" . PHP_EOL;'
```

---

## 8. Verify the deployment

Tick every item before relying on a site:

- [ ] **Test SharePoint Connection** succeeds.
- [ ] A manual backup completes and its folder appears under `<root>/<site folder>/` in SharePoint.
- [ ] The dashboard shows the site as **Healthy** with the right schedule and "Last successful backup".
- [ ] (Nginx) `/wp-content/uploads/spsync-backups/` is not downloadable (§4.3).
- [ ] System cron is configured (§4.1) and the next scheduled backup actually runs. Check the next day.
- [ ] **Restore test on a staging copy:** restore the latest backup onto a staging site (dashboard **Migrate** tab,
      or plugin **Manual Restore** with a downloaded ZIP) and confirm the site works.
      *An untested backup is not a backup.*

---

## 9. Rolling out to many websites

- Reuse the same Entra ID app, SharePoint site, library and root folder for every website. Only the **Site Folder**
  must be unique.
- With WP-CLI you can script installation (the client secret is entered once per site in the admin screen so it is
  stored encrypted with that site's salts):

  ```sh
  wp plugin install /path/to/schedule_backup_plugin.zip --activate --url=https://www.example.com
  ```

- Stagger schedule times (e.g. 01:00, 01:20, 01:40…) so many sites do not compete for bandwidth at once.
- Add each site to the dashboard and confirm it becomes **Healthy**.

---

## 10. Upgrading the plugin

1. Read the release notes in `README.md`.
2. Take a backup first (**Overview & Testing → Back Up to SharePoint Now**).
3. Upload the new ZIP through **Plugins → Add New Plugin → Upload Plugin** and choose **Replace current with uploaded**,
   or run `wp plugin install schedule_backup_plugin.zip --force`.
4. Settings, API key, schedule and history are kept. Plugin upgrades re-arm the saved schedule automatically.
5. Click **Check connection** in the dashboard; the plugin version shown should be the new one.

**Upgrading from < 1.4.0:** existing backups stay in the root folder and are still recognised by this site. New
backups go into the site folder. Confirm the **Site Folder** name right after upgrading, before the next backup runs.

---

## 11. Security checklist

- [ ] HTTPS on the WordPress site (the API key travels in a header).
- [ ] **Client secret expiry tracked**: create a new secret in Entra ID before the old one expires, paste it in the
      plugin, run **Test SharePoint Connection**, then delete the old secret.
- [ ] Prefer `Sites.Selected` (§3.3 Option B) over tenant-wide `Sites.ReadWrite.All`.
- [ ] Only trusted administrators have `manage_options`: they can download full backups and restore the site.
- [ ] Treat the **Secret API Key** like a password. Rotate it if it leaks (§7), then update the dashboard.
- [ ] Nginx deny rule in place (§4.3).
- [ ] Downloaded backup ZIPs contain the full database (including user password hashes). Store them securely.
- [ ] Keep `AUTH_KEY`/`SECURE_AUTH_KEY` in `wp-config.php` stable: the stored client secret is encrypted with them.
      If they change, re-enter the client secret.

---

## 12. Troubleshooting

Start with **SharePoint Backup → Execution Logs**: every step is logged.

| Symptom | Cause and fix |
|---|---|
| `OAuth Authentication Error … AADSTS7000215` | Wrong client secret: the secret **ID** was pasted instead of its **Value**. Create a new secret. |
| `AADSTS7000222` | Client secret expired. Create a new one (§11). |
| `AADSTS90002` / tenant not found | Wrong Tenant ID. |
| Graph `HTTP 403 Access denied` | Admin consent missing, or with `Sites.Selected` the site grant is missing (§3.3). |
| `Could not resolve SharePoint Site ID` | Site URL wrong. Use `https://<tenant>.sharepoint.com/sites/<name>`, no trailing page path. |
| `Document library not found` | Use the library's display name (`Documents`), not `Shared Documents`. |
| Backups never start / stall at "Uploading" | WP-Cron not running. Set up system cron (§4.1). In **Overview & Testing**, use **Clear / Reset Stale Lock** if a crashed run left a lock. |
| `Backup aborted: Another backup process is currently running` | A run is in progress or a stale lock exists (cleared automatically after 10 minutes, or manually as above). |
| PHP fatal error / out of memory during backup | Raise `memory_limit` and `max_execution_time`; use selected-directory mode to exclude huge folders. |
| Dashboard: *did not answer at this address (HTTP 404)* | Plugin not activated, wrong site address, or a security plugin blocking the REST API. |
| Dashboard: *rejected the API key* | Key regenerated or mistyped. Paste the current key under **Edit** in the dashboard. |
| Manual Restore upload fails immediately | ZIP larger than `upload_max_filesize`/`post_max_size` or the web server's body limit (e.g. Nginx `client_max_body_size`). |
| Restore failed | The plugin restores its safety snapshot automatically; the reason appears in Execution Logs and on the dashboard's **Restores** tab. |

---

## 13. Deactivation and removal

- **Deactivate:** stops all scheduled backup, upload and restore jobs. Settings, history and the API key are kept,
  and re-activating restores the schedule.
- **Delete the plugin:** removes the code. The plugin's database tables remain. Drop them manually if you are sure:
  `wp db query "DROP TABLE wp_spsync_settings, wp_spsync_logs, wp_spsync_items, wp_spsync_restore_items;"`
  (adjust the `wp_` prefix).
- Backups already on SharePoint are **never** deleted by deactivation or removal.
- Remove the site from the dashboard (**Edit → Remove website**) so it is no longer monitored.
