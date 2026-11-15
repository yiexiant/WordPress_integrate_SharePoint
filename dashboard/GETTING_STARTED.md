# Getting Started: Running the Backup Dashboard

This guide shows how to start the central dashboard on your Windows PC (with Laragon), sign in, connect WordPress
sites and keep monitoring running. All commands are run from the `dashboard` folder:

```powershell
cd C:\04-backup_plugin\dashboard
```

---

## 0. One-time: make `php` available

PHP comes with Laragon but is not on your Windows `PATH`. Choose **one** option:

- **Easiest:** open Laragon → **Terminal** (the Cmder window). `php` and `composer` already work there.
- **Or add an alias to the current PowerShell window:**

  ```powershell
  Set-Alias php "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
  ```

  The alias only lasts until you close that window. To make it permanent, add the same line to your PowerShell profile
  (`notepad $PROFILE`).

Check it works:

```powershell
php -v    # should print PHP 8.3.x
```

---

## 1. First-time setup (only once)

Skip any step that is already done.

```powershell
composer install                      # PHP dependencies (vendor/)
copy .env.example .env                # only if .env does not exist yet
php artisan key:generate              # only for a NEW .env; never on an existing one (see warning below)
php artisan migrate                   # create or upgrade the database tables
php artisan dashboard:user you@example.com --name="Your Name"
```

The last command asks for a password (at least 12 characters). That is your dashboard login.

> ⚠️ **Never run `key:generate` on an existing install.** `APP_KEY` in `.env` encrypts the stored WordPress API
> keys. If it changes, every saved site key becomes unreadable and must be re-entered.

### After pulling new code

```powershell
composer install
php artisan migrate
```

---

## 2. Start the dashboard (every time)

You need **two** terminal windows, both in the `dashboard` folder.

**Window 1: web server**

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

**Window 2: scheduler** (runs the automatic site monitor every 5 minutes)

```powershell
php artisan schedule:work
```

Open **http://127.0.0.1:8000** and sign in with the account from step 1.

Keep both windows open while you use the dashboard. To stop, press **Ctrl + C** in each window.

> Without window 2 the dashboard still works, but sites are only checked when you click buttons. The
> **Backup Overdue** alerts and the "site went offline" activity entries come from the scheduler.

---

## 3. Connect a WordPress website

1. **Install the plugin on the WordPress site:** WordPress admin → Plugins → Add New → Upload Plugin →
   choose `C:\04-backup_plugin\plugin\schedule_backup_plugin.zip` → Activate.
2. In WordPress, open **SharePoint Backup**:
   - **Credentials** tab: enter Tenant ID, Client ID, Client Secret, SharePoint Site URL, Document Library and Root
     Backup Folder. Check the **Site Folder** name: every website needs its own. Save, then **Test Connection**.
   - **Backup Method & Schedule** tab: choose *Automatic backups to SharePoint* and a schedule.
     The dashboard only flags *Backup Overdue* for sites with an automatic schedule.
   - **Overview** tab: copy the **Secret API Key**.
3. In the dashboard, click **Add Website**, then enter a name, the site's exact URL (for example `http://mysite.test`)
   and the API key.
4. On the website's page, click **Test All Connections**. You should see *WordPress Online* and *SharePoint Connected*.

---

## 4. Everyday tasks

| I want to… | Do this |
|---|---|
| See all sites at a glance | **Dashboard** page (red banner = overdue backups) |
| Back up one site now | Website page → **Run Backup** |
| Back up several sites | **Connected Websites** → tick sites → bulk backup |
| Check backups still exist on SharePoint | Website page → **Verify Backups** |
| Restore a site | Website page → backup history → **Restore** |
| Copy one site onto another (migration) | Target website page → **Restore a backup from another website**, then run the URL update |
| Refresh every site right now | `php artisan sites:sync` |
| Add another operator / reset a password | `php artisan dashboard:user someone@example.com` |

---

## 5. Troubleshooting

| Problem | Cause and fix |
|---|---|
| `php` is not recognized | See step 0. |
| `Could not resolve host: mysite.test` | Laragon has no virtual host for that name. The folder must exist in `C:\laragon\www`. In Laragon, click **Menu → Apache → Reload** (or **Stop All / Start All**). The first time, Laragon may ask for administrator rights to update the Windows hosts file; allow it. Check that `http://mysite.test` opens in your browser. |
| *The website URL resolves to a private or reserved network address* | Only happens when `APP_ENV` is not `local`. For internal sites add `WP_ALLOW_PRIVATE_HOSTS=true` to `.env`. |
| SSL / certificate error for a `https://….test` site | Laragon uses self-signed certificates. `APP_ENV=local` already skips the check; otherwise set `WP_VERIFY_TLS=false` (local only). |
| `HTTP 301` / `302` when testing a site | The plugin API must be called at the site's final URL. Use exactly what the browser ends up on (`https://`, with or without `www`). |
| `HTTP 401 Unauthorized API Key` | The API key changed in WordPress. Edit the website in the dashboard and paste the new key. Leave the field blank to keep the old one. |
| `The MAC is invalid` / `DecryptException` | `APP_KEY` in `.env` changed. Restore the previous `.env`, or re-enter every site's API key. |
| `419 Page Expired` after signing in | The page was open too long. Reload it and try again. |
| `Address already in use` on port 8000 | Something else is using the port. Use `php artisan serve --port=8001` and open `http://127.0.0.1:8001`. |
| *Too many login attempts* | Wait one minute (5 attempts per minute). |
| Dashboard looks unstyled | The layout loads Tailwind and fonts from the internet. Check your internet connection. |

### Useful checks

```powershell
php artisan migrate:status     # all migrations should say "Ran"
php artisan schedule:list      # shows the sites:sync monitor
php artisan test               # dashboard test suite
```

---

## 6. Settings reference (`.env`)

| Setting | Default | Meaning |
|---|---|---|
| `APP_ENV` | `local` | `local` relaxes TLS and private-address checks for `.test` sites. Use `production` on a server. |
| `WP_VERIFY_TLS` | `true` (`false` when local) | Verify WordPress HTTPS certificates. |
| `WP_ALLOW_PRIVATE_HOSTS` | `false` (`true` when local) | Allow sites on 127.0.0.1 / LAN addresses. |
| `WP_BACKUP_OVERDUE_GRACE_HOURS` | `6` | Extra hours past a schedule before a site counts as overdue. |

See `README.md` for the architecture and `SRS.md` for the full requirements.
