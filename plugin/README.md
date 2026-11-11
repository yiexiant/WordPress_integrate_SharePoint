# WordPress SharePoint Backup Plugin

Version 1.1.0. Install `schedule_backup_plugin.zip` through WordPress **Plugins → Add New → Upload Plugin**, then open **SharePoint Backup**.

## Choose backup contents

Open **Backup Contents**. **Entire website and database** is selected by default, preserving the original full-backup behavior and its existing cache, temporary-file, log and archive exclusions.

To back up selected content, choose **Only selected wp-content directories**, select plugins, themes, uploads or any other discovered readable directory, and save. Selected-directory mode includes regular files, including archives, within the selected directories. The plugin's backup storage and symlinks are excluded.

The database is optional in selected-directory mode. Check **Include the WordPress database too** when needed; selecting only that checkbox creates a database-only backup. These settings apply to manual, scheduled and central-dashboard backups. Empty selections are rejected.

## Restore a local backup folder

1. Download the entire backup folder from SharePoint, keeping `manifest.json` and every file named in its `parts` list together.
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
