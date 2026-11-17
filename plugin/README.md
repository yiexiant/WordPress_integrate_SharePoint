# WordPress SharePoint Backup Plugin

Version 1.5.1. Install `schedule_backup_plugin.zip` through WordPress **Plugins → Add New → Upload Plugin**, then open **SharePoint Backup**.

> **Production rollout** (Entra ID app registration, Graph permissions, server cron, Nginx rule, verification, upgrades): see [DEPLOYMENT.md](DEPLOYMENT.md).

## The admin screens

Open **SharePoint Backup** in the WordPress menu:

- **Dashboard**: one plain answer to “is this website backed up?” (for example *Your website is backed up*,
  *Backups are overdue*, *The last backup failed*, *SharePoint can’t be reached*), the next step to take, a setup
  checklist for new installs, and the last backup, next backup and SharePoint connection at a glance.
- **Backups**: **Back up now**, **Download a copy** to your computer, and every backup stored on SharePoint with its
  date, size, upload progress and a **Restore** button.
- **Restore**: upload a backup ZIP and restore it; the result of the last restore is shown at the top.
- **Settings**: **Schedule** (with a plain summary such as “Backs up every Monday at 02:00 and keeps the last 30”),
  **Backup Contents**, **SharePoint** (with help for finding the Entra ID values and a **Test connection** button)
  and **Central Dashboard** (show and copy the API key).
- **Activity**: everything the plugin did, in your timezone, with a **Problems only** filter.

Screens refresh themselves while a backup or restore runs. Only one backup runs at a time: while one is being
created or uploaded, **Back up now** is disabled and says why. An unfinished backup (for example one started by an
extra click) can be removed with **Cancel** in the Backups list; this deletes its files here and on SharePoint.
If a backup shows no progress for 30 minutes, a **Clear stuck backup** button appears at the top.

## Connect another dashboard through the API

Copy the key from **Settings › Central Dashboard** into the dashboard's **Add website** form with this WordPress site's URL. Authenticated requests send `X-SPSYNC-KEY` to `/wp-json/spsync/v1/…` or, on sites with Plain permalinks, `/?rest_route=/spsync/v1/…`. The SEO backend in the neighboring package now provides a dedicated `/backup-dashboard` page and client-scoped backup API; its SAC publishing token remains separate.

Version 1.2.1 keeps the ID returned by `POST backup/start` unchanged when its worker creates artifacts. `GET backup/status` reports a queued API request before packaging starts and reports identifiable pre-upload failures. The worker still needs WordPress cron or Action Scheduler. API backup commands create SharePoint backups; device downloads remain administrator actions inside WordPress.

## Several websites in one SharePoint library

Each website stores its backups in its own **Site Folder** inside the Root Backup Folder:

```
{Document Library}/{Root Backup Folder}/{Site Folder}/spsync-backup-YYYYMMDD-HHMMSS/
```

The site folder defaults to the website's host and path (for example `shop.example.com-store`), is saved on first use, and can be changed in **Credentials**. Retention, verification and restore only consider this website's folder, so websites sharing a root can never prune or claim each other's backups. Pick the site folder before the first backup. Renaming it later hides older backups from retention and verification.

Backups created before 1.4.0 sit directly in the root folder. They are still found for restore, verification and retention when this website's own upload history recorded their ID, and they are ignored otherwise. To restore another website's backup (migration), call `POST restore/start` with `backup_id` and `source_site_folder` (the other website's site folder name). The central dashboard does this for you.

## Protecting local backup files

While uploading, backups are staged under `wp-content/uploads/spsync-backups/<random token>/`. Deny rules are written for Apache 2.2/2.4 and IIS. **Nginx ignores them.** The random token prevents guessing, but on Nginx you should also add:

```nginx
location ~* /wp-content/uploads/spsync-backups/ { deny all; }
```

## Choose manual or automatic backups

Open **Settings › Schedule** and choose:

- **Only when I start one:** automatic backups are turned off. Use **Backups → Back up now**, or **Backups → Download a copy → Create Backup for My Device**; when it is ready (the page refreshes itself) click **Download ZIP to My Device**. No SharePoint credentials are required.
- **Automatically, to SharePoint:** choose how often, the day/date and time, and how many backups to keep, then **Save schedule**. The existing scheduled cloud-backup workflow runs without keeping the browser open.

Manual-only is the default for a new installation. Existing automatic schedules are preserved during upgrade. You can create a device download at any time without changing an automatic schedule. Saving SharePoint credentials does not reset your chosen method or schedule.

Device backups use the same **Backup Contents** selection. The plugin prepares a ZIP in private server storage, without queuing a SharePoint upload. Only the requesting administrator can download that copy through the authenticated download action. The browser controls the save location; the plugin cannot silently write to a computer or phone or send unattended backups to a closed browser.

Download the file within 24 hours. The plugin schedules temporary-copy cleanup using WordPress cron; access expires even when cron cleanup is delayed. Starting another device backup replaces your previous completed temporary copy. You can also remove the server copy yourself. Keep the downloaded ZIP safely on your device; automatic server cleanup does not delete that downloaded file.

## Choose backup contents

Open **Settings › Backup Contents**. **The whole website** is selected by default, preserving the original full-backup behavior and its existing cache, temporary-file, log and archive exclusions.

To back up selected content, choose **Only some folders**, select plugins, themes, uploads or any other discovered readable directory, and save. Selected-directory mode includes regular files, including archives, within the selected directories. The plugin's backup storage and symlinks are excluded.

The database is optional in selected-directory mode. Check **Include the database** when needed; selecting only that checkbox creates a database-only backup. These settings apply to manual, scheduled and central-dashboard backups. Empty selections are rejected.

## Upload, verify and restore a backup

1. Open **Restore** and choose (or drag in) the ZIP downloaded by this plugin, or a ZIP containing exactly one plugin backup folder. Under **Other ways to upload** you can select the folder itself or its individual files instead. Use only one upload option.
2. Check the overwrite confirmation on the upload form, then click **Upload, Verify & Restore**.
3. The plugin receives and unpacks the backup privately, validates safe archive paths, and verifies `manifest.json`, all declared files, sizes and SHA-256 checksums. Nothing is restored if verification fails.
4. Once verification succeeds, restoration starts automatically after the upload response. There is no second Start button. A recovery snapshot is created before site files or database data are overwritten.
5. The **Restore** screen shows the result and **Activity** logs every step. The immediate dispatch does not wait for a cron visit; a scheduled action remains as a fallback. On servers that cannot finish the HTTP response early, the page may keep loading while restoration runs. SharePoint credentials are not required.

Accepted ZIPs may have components at the archive root or within one backup folder. They must contain one `manifest.json`, every named `backup_part*.zip`, and `database.sql` if included by that manifest. Multiple backups in one ZIP, arbitrary website ZIPs, and backups from other plugins are rejected. Do not unzip the inner ZIP parts.

If a verified upload cannot start because another operation is running or scheduling fails, its files are retained. Use **Restore Verified Backup Now** when ready. This also works for a previously verified upload from an older plugin version, without reuploading.

Only the files present in the backup and its database, if included, are restored. Other existing files are left in place. Database restoration replaces site content/settings/users, so the restored administrator account or URL can differ. Migration between hosts may also require environment configuration and URL search-and-replace after restoration.

Before changing the site, manual restoration snapshots the existing destination files and, when needed, the database. An ordinary restore failure attempts rollback, including removal of newly created files. Private recovery artifacts are retained on failure and their server location appears in the tab. An interrupted/killed server process may require administrator recovery; this is not a transactional filesystem/database restore.

## Server requirements and upload limits

- The plugin declares PHP 7.4+ and WordPress 6.0+. New regression checks were run with PHP 8.3.
- ZipArchive is recommended; the existing WordPress PclZip fallback is retained.
- Browser folder upload requires directory-upload support. The individual-files input is the alternative.
- PHP `upload_max_filesize`, `post_max_size`, `max_file_uploads`, web-server request limits and available disk space must accommodate the whole upload. The tab displays the PHP limits; missing/truncated parts fail verification.
- A single uploaded ZIP counts as one file, avoiding the multipart file-count limit for backups with many parts. The ZIP still has to fit the request-size limit. Outer package expanded size is checked against `spsync_restore_max_expanded_bytes`; ZIP uploads do not increase PHP/server limits automatically.
- Manual restoration needs a writable private temporary directory outside both the WordPress installation and document root. By default this is the system temporary directory. A deployment can override it with the `spsync_restore_temp_dir` WordPress filter.
- ZIP parts are limited to 2 GiB expanded size each by default; deployments can adjust `spsync_restore_max_expanded_bytes`. Leave enough space for uploaded artifacts, extracted files and recovery snapshots.
- Directory discovery supports regular directories within the WordPress installation. External/symlinked content roots are not included by the scanner.

## Verification

Run the isolated regression checks with:

```sh
php plugin/tests/run.php
```

The harness uses temporary fixture files and WordPress/database stubs. It does not connect to a real database, WordPress site or SharePoint tenant. A staging-site restore remains necessary before production use.

## Release notes — 1.1.0

- Added Backup Contents and Manual Restore tabs, scoped backup manifests, file-only and database-only backups.
- Added private local upload staging, manifest/hash/ZIP validation, administrator review and background local restoration.
- Added recovery snapshots of the actual local-restore destinations and complete batched rollback import.
- SQL restore now reports missing files, incomplete statements and query failures, and preserves plugin control tables when reading legacy backups.
- Restore status is returned even when the site has no prior backup records; remote restore supports database-only backups.
- Fixed namespace-to-directory casing for case-sensitive servers and rejected incomplete ZIP creation.

## Release notes — 1.2.0

- Added explicit manual-device versus automatic-SharePoint backup choices.
- Added background device-backup preparation, owner-only ZIP downloads, cancellation/removal and 24-hour expiry/cleanup.
- Device backups reuse full/selected/database-only scope without requiring cloud credentials or adding SharePoint queue items.
- Downloaded ZIPs contain a plugin-compatible backup folder for the existing manual-restoration feature.
- Separated credential saving from schedule saving so configuration changes preserve the user's choice.
- Shared private temporary-storage validation between manual upload and device download flows.

## Release notes — 1.2.1

- Preserved API backup IDs from acceptance through worker execution, manifests and polling.
- Added pending API-command reservation, honest scheduling errors and pre-upload failure status.
- Explicit API backups no longer reset a separate one-time automatic schedule.
- Remote verification follows Graph pagination and does not mark backups missing after a failed/incomplete listing.

## Release notes — 1.3.0

- Added direct upload of plugin download ZIPs and ZIP-wrapped backup folders, with ZipArchive streaming and WordPress PclZip fallback.
- Replaced the normal two-step upload/review/start flow with **Upload, Verify & Restore** and an upfront overwrite confirmation.
- Added immediate response-shutdown restore dispatch, retaining the scheduled worker as a fallback and preventing duplicate execution.
- Preserved retry support for existing verified uploads when another job or a scheduling problem prevents immediate start.
- Added package-layout, multiple-manifest, traversal, expanded-size and incomplete-part checks before restoration.

## Release notes — 1.4.0

- **Per-site SharePoint folders.** Retention, verification and restore are limited to this website. Legacy root-level backups are claimed only if this site recorded them. Cross-site restore needs an explicit `source_site_folder`.
- Retention follows Graph pagination and drops local tracking only after SharePoint confirms the delete.
- Verification reports `incomplete_backups` (folder has fewer files than uploaded) and `pending_backups` (still uploading) instead of flagging them missing, and marks reappearing backups present again.
- Upload completion is judged per backup, so a failed component keeps the local copy and a different backup is never pruned.
- Remote restore validates manifest filenames and checksums, finds the backup before the rollback snapshot, and never creates SharePoint folders.
- Backup folders are created with `conflictBehavior=fail` instead of `replace`.
- URL migration binds its search value in SQL (fixes an injection via `database/search-replace`) and accepts only http(s) URLs.
- Local staging moved to an unguessable token directory, with Apache 2.4/2.2 and IIS deny rules.
- `backup/status` adds `last_successful_backup`, `schedule` (frequency, next run), `site_folder`, `plugin_version` and `restore.error`. `health` and `system/info` add `site_url` and `site_folder`.
- Monthly schedules stay on the chosen calendar day. Deactivation clears cron events; activation and upgrade restore them.
- Changing the library or root folder clears cached SharePoint IDs.

## Release notes — 1.5.1

- **One backup at a time.** The running-backup lock now lives as long as the backup shows progress (stale only after
  30 minutes of silence, previously a fixed 10 minutes), and no new backup starts while an earlier one still has
  files to upload. Starting is atomic, so double clicks or two dashboards clicking together start a single backup.
- **Back up now runs in the background** and returns at once; the button is disabled with the reason while busy.
- **Cancel an unfinished backup** from the Backups list: removes its upload queue, local copy and partial SharePoint folder.
- **Uploads no longer stall.** With Action Scheduler (e.g. WooCommerce) the queue stopped after every 5 files until
  another backup restarted it. One upload run now keeps sending files for up to 150 s and then schedules the next;
  a single active run is guaranteed and an admin-side watchdog resumes a stalled queue.
- **10 MiB upload chunks** (Microsoft’s recommended size, was 3.2 MiB); network errors, 429 and 5xx are retried with
  back-off, and interrupted chunks resume from SharePoint’s `nextExpectedRanges`.
- **Faster archiving:** photos, video, audio, archives and fonts are stored in the ZIP parts without re-compression.
- Filters: `spsync_upload_chunk_bytes`, `spsync_upload_time_budget`.

## Release notes — 1.5.0

- Redesigned and restructured admin: **Dashboard · Backups · Restore · Settings · Activity** replace the seven
  overlapping tabs. Old links (`tab=overview`, `credentials`, `automation`, `download`, `contents`, `logs`) still work.
- Dashboard answers “is this website backed up?” in plain words with the next action, plus a setup checklist.
- Backups lists this site’s SharePoint backups with dates, sizes and progress, and can restore one (with confirmation).
- Settings are split into Schedule, Backup Contents, SharePoint and Central Dashboard; the schedule shows a live summary,
  the SharePoint section explains where to find each value and remembers the last connection test, and the client
  secret is never sent back to the browser (leave it empty to keep it).
- Activity shows site-timezone times, a problems-only filter and expandable technical details.
- The stuck-backup reset only appears when a backup has run for over 10 minutes.
- WordPress-native buttons and admin colour scheme; responsive layout; CSS/JS moved to `admin/assets/`.
