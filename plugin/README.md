# WordPress SharePoint Backup Plugin

Version 1.2.1. Install `schedule_backup_plugin.zip` through WordPress **Plugins → Add New → Upload Plugin**, then open **SharePoint Backup**.

## Connect another dashboard through the API

Copy the **Secret API Key** from Overview into the dashboard's backup-plugin connection form with this WordPress site's URL. Authenticated requests use `X-SPSYNC-KEY` under `/wp-json/spsync/v1/`. The SEO backend in the neighboring package now provides a dedicated `/backup-dashboard` page and client-scoped backup API; its SAC publishing token remains separate.

Version 1.2.1 keeps the ID returned by `POST backup/start` unchanged when its worker creates artifacts. `GET backup/status` reports a queued API request before packaging starts and reports identifiable pre-upload failures. The worker still needs WordPress cron or Action Scheduler. API backup commands create SharePoint backups; device downloads remain administrator actions inside WordPress.

## Choose manual or automatic backups

Open **Backup Method & Schedule** and choose:

- **Manual backups to my device:** automatic backups are turned off. Use **Manual Backup & Download → Create Backup for My Device**, refresh the status, then click **Download ZIP to My Device**. No SharePoint credentials are required.
- **Automatic backups to SharePoint:** choose your frequency, day/date and time, configure SharePoint, and save. The existing scheduled cloud-backup workflow runs without keeping the browser open.

Manual-only is the default for a new installation. Existing automatic schedules are preserved during upgrade. You can create a device download at any time without changing an automatic schedule. Saving SharePoint credentials does not reset your chosen method or schedule.

Device backups use the same **Backup Contents** selection. The plugin prepares a ZIP in private server storage, without queuing a SharePoint upload. Only the requesting administrator can download that copy through the authenticated download action. The browser controls the save location; the plugin cannot silently write to a computer or phone or send unattended backups to a closed browser.

Download the file within 24 hours. The plugin schedules temporary-copy cleanup using WordPress cron; access expires even when cron cleanup is delayed. Starting another device backup replaces your previous completed temporary copy. You can also remove the server copy yourself. Keep the downloaded ZIP safely on your device; automatic server cleanup does not delete that downloaded file.

## Choose backup contents

Open **Backup Contents**. **Entire website and database** is selected by default, preserving the original full-backup behavior and its existing cache, temporary-file, log and archive exclusions.

To back up selected content, choose **Only selected wp-content directories**, select plugins, themes, uploads or any other discovered readable directory, and save. Selected-directory mode includes regular files, including archives, within the selected directories. The plugin's backup storage and symlinks are excluded.

The database is optional in selected-directory mode. Check **Include the WordPress database too** when needed; selecting only that checkbox creates a database-only backup. These settings apply to manual, scheduled and central-dashboard backups. Empty selections are rejected.

## Restore a local backup folder

1. Download the entire backup folder from SharePoint, or extract your device-download ZIP. Keep `manifest.json` and every file named in its `parts` list together inside the backup folder.
2. Open **Manual Restore**. Choose that folder, or select its individual files together. A ZIP of the whole folder and backups from other plugins are not accepted. Native plugin ZIP parts remain zipped.
3. Click **Upload and Verify Backup**. The plugin stages files outside the website, verifies the manifest, sizes and SHA-256 checksums, and checks ZIP paths.
4. Review the verified components, confirm the overwrite notice, then click **Start Manual Restoration**.
5. Refresh the tab to monitor status and check **Execution Logs**. WordPress cron or Action Scheduler must be working. SharePoint credentials are not required for this restore.

Only the files present in the backup and its database, if included, are restored. Other existing files are left in place. Database restoration replaces site content/settings/users, so the restored administrator account or URL can differ. Migration between hosts may also require environment configuration and URL search-and-replace after restoration.

Before changing the site, manual restoration snapshots the existing destination files and, when needed, the database. An ordinary restore failure attempts rollback, including removal of newly created files. Private recovery artifacts are retained on failure and their server location appears in the tab. An interrupted/killed server process may require administrator recovery; this is not a transactional filesystem/database restore.

## Server requirements and upload limits

- The plugin declares PHP 7.4+ and WordPress 6.0+. New regression checks were run with PHP 8.3.
- ZipArchive is recommended; the existing WordPress PclZip fallback is retained.
- Browser folder upload requires directory-upload support. The individual-files input is the alternative.
- PHP `upload_max_filesize`, `post_max_size`, `max_file_uploads`, web-server request limits and available disk space must accommodate the whole upload. The tab displays the PHP limits; missing/truncated parts fail verification.
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
