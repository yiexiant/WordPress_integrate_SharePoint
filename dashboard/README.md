# SharePoint Backup — Central Dashboard

Laravel 13 (PHP 8.3) dashboard for monitoring and operating every WordPress site that runs the
**WordPress SharePoint Backup Plugin** (`../plugin`, version 1.4.0 or later recommended).
The dashboard never talks to SharePoint itself. It calls each site's `wp-json/spsync/v1/*` API with that
site's plugin key (`X-SPSYNC-KEY`).

> **Guides:** [GETTING_STARTED.md](GETTING_STARTED.md) (run it on your PC with Laragon) · [DEPLOYMENT.md](DEPLOYMENT.md) (production server, HTTPS, scheduler, updates, backups).

## Setup

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan dashboard:user you@example.com --name="Your Name"   # prompts for a password (12+ chars)
npm install && npm run build
```

All pages require sign-in. Use `php artisan dashboard:user` to create operators or reset a password.
Login is rate-limited to 5 attempts per minute per email and IP address.

> **Upgrading an existing install:** run `php artisan migrate` before opening the dashboard. One migration
> encrypts the stored plugin API keys with `APP_KEY`. Keep `APP_KEY` safe: if it is lost, every site key must be re-entered.

## Unattended monitoring

`php artisan sites:sync` checks every site's health and backup status. It records the time of the newest *fully
uploaded* SharePoint backup, the site's schedule and next run, and flags sites as **Backup Overdue**. A site is
overdue when its newest backup is older than its schedule (daily / weekly / monthly) plus
`WP_BACKUP_OVERDUE_GRACE_HOURS` (default 6). Activity entries are written only when a site's state changes:
unreachable or recovered, overdue or caught up.

The scheduler runs it every five minutes, and refreshes diagnostics daily. Run the scheduler with either:

```sh
php artisan schedule:work                                   # long-running process (containers, dev)
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1   # cron
```

Options: `--website=ID` (repeatable) and `--with-system-info`.

## Multiple sites in one SharePoint library

Plugin 1.4.0 stores each site's backups in its own **site folder** under the shared root folder. The dashboard
shows each site's folder. The **Restore a backup from another website** form on a site's page restores a completed
backup of another registered site, for migrations. That sends `source_site_folder` to the plugin, so both sites must
use the same library and root folder. Afterwards, run the URL search-and-replace on the same page.

## Network safety

| Setting | Default | Purpose |
|---|---|---|
| `WP_VERIFY_TLS` | `true` (`false` when `APP_ENV=local`) | Verify WordPress TLS certificates |
| `WP_ALLOW_PRIVATE_HOSTS` | `false` (`true` when `APP_ENV=local`) | Allow site URLs that resolve to private/loopback/link-local addresses |
| `WP_BACKUP_OVERDUE_GRACE_HOURS` | `6` | Grace period before a scheduled site counts as overdue |

Site URLs must be absolute http(s) URLs without credentials. They are checked when saved and again before every
request. Redirects are not followed, so the API key is never sent to another host. Register each site's final
canonical URL, for example `https://www.example.com` rather than `http://example.com`.

## Tests

```sh
php artisan test
```

Feature tests cover the authentication boundary, key encryption, SSRF rules, status/overdue synchronization,
verification states, cross-site restore and the scheduled monitor. All WordPress calls are faked with `Http::fake()`.
