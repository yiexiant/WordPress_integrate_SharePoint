<?php
/**
 * Build the installable plugin package.
 *
 *   php plugin/bin/build-zip.php            -> plugin/schedule_backup_plugin.zip
 *   php plugin/bin/build-zip.php out.zip    -> custom output path
 *
 * Packages application PHP, admin CSS/JS and README.md under schedule-backup-plugin/ (the folder name
 * WordPress installs into). Excludes tests/, bin/, SRS.md, DEPLOYMENT.md, dotfiles and ZIPs.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$source = realpath(dirname(__DIR__));
$output = $argv[1] ?? $source . DIRECTORY_SEPARATOR . 'schedule_backup_plugin.zip';
$excludedDirs = ['tests/', 'bin/'];
$excludedFiles = ['SRS.md', 'DEPLOYMENT.md'];

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
    $skip = $relative[0] === '.' || in_array($relative, $excludedFiles, true) || !preg_match('/\.(php|md|css|js)$/', $relative);
    foreach ($excludedDirs as $dir) {
        $skip = $skip || strpos($relative, $dir) === 0;
    }
    if (!$skip) {
        $files[] = $relative;
    }
}
sort($files);

$temporary = $output . '.tmp';
@unlink($temporary);
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write $temporary\n");
    exit(1);
}
foreach ($files as $relative) {
    $zip->addFile($source . '/' . $relative, 'schedule-backup-plugin/' . $relative);
}
if (!$zip->close() || !rename($temporary, $output)) {
    fwrite(STDERR, "Could not finish $output\n");
    exit(1);
}

preg_match("/define\\('SPSYNC_VERSION', '([^']+)'\\)/", (string) file_get_contents($source . '/schedule-backup-plugin.php'), $version);
printf("Packaged %d files (version %s) into %s\n", count($files), $version[1] ?? '?', $output);
