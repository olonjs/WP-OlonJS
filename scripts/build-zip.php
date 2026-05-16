<?php
/**
 * Build dist/wp-olonjs.zip with only the runtime files needed to install the
 * plugin. Reads exclusion patterns from .distignore (one pattern per line,
 * matched against paths relative to the plugin root).
 */
declare(strict_types=1);

$root      = dirname(__DIR__);
$pluginDir = 'wp-olonjs';
$zipPath   = $root . '/dist/wp-olonjs.zip';

if (file_exists($zipPath)) {
    unlink($zipPath);
}

$ignore = array_values(array_filter(array_map(
    'trim',
    explode("\n", (string) file_get_contents($root . '/.distignore'))
), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));

$shouldExclude = static function (string $relativePath) use ($ignore): bool {
    foreach ($ignore as $pattern) {
        if ($relativePath === $pattern || str_starts_with($relativePath, $pattern . '/')) {
            return true;
        }
    }
    return false;
};

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create $zipPath\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$added = 0;
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $absolute = $file->getPathname();
    $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root) + 1)), '/');
    if ($shouldExclude($relative)) {
        continue;
    }
    $zip->addFile($absolute, $pluginDir . '/' . $relative);
    $added++;
}

$zip->close();

printf("Built %s (%d files, %s)\n", $zipPath, $added, format_size(filesize($zipPath) ?: 0));

function format_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    return sprintf('%.1f MB', $bytes / 1024 / 1024);
}
