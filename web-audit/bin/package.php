<?php
declare(strict_types=1);

/**
 * Build an upload-ready zip. FTP over a few dozen small files is slow and
 * half-finished uploads are the usual cause of a mystery 500, so ship one
 * archive and unzip it on the server.
 *
 *   php bin/package.php              dist/mobile-web-audit.zip, split layout
 *   php bin/package.php --flat       everything in one folder, for hosts that
 *                                    will not let you move the document root
 *
 * Never packs config/local.php or .env: the credentials are typed on the
 * server, so they cannot be lost in a chat log or a shared download.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
if (!class_exists('ZipArchive')) {
    exit("This PHP has no zip extension. Install php-zip, or upload the folder over FTP as-is.\n");
}

$root = dirname(__DIR__);
$flat = in_array('--flat', $argv, true);
$dist = $root . '/dist';
$name = $flat ? 'mobile-web-audit-flat.zip' : 'mobile-web-audit.zip';
$out  = $dist . '/' . $name;

if (!is_dir($dist) && !mkdir($dist, 0775, true) && !is_dir($dist)) {
    exit("Could not create $dist\n");
}

/** Things that must never reach a server, or would only waste upload time. */
$skipFile = static function (string $rel): bool {
    if ($rel === 'config/local.php' || $rel === '.env') {
        return true;                                  // credentials
    }
    if (str_starts_with($rel, 'dist/') || str_starts_with($rel, '.git/')) {
        return true;
    }
    if (str_starts_with($rel, 'bin/') && (
        str_ends_with($rel, 'selftest.php') || str_ends_with($rel, 'integration-test.php')
    )) {
        return true;                                  // test suites, not runtime
    }
    return in_array(basename($rel), ['.DS_Store', 'Thumbs.db'], true);
};

$files = [];
$walk  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $path) {
    if (!$path->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($root) + 1));
    if ($skipFile($rel)) {
        continue;
    }
    $files[] = $rel;
}
sort($files);

/**
 * The flat layout puts the contents of public/ at the top and everything else
 * beside it, because the document root is all you get on some hosts. The app
 * detects which layout it is in at runtime - see public/_init.php.
 */
$target = static function (string $rel) use ($flat): string {
    if (!$flat) {
        return $rel;
    }
    return str_starts_with($rel, 'public/') ? substr($rel, strlen('public/')) : $rel;
};

/*
 * Flattening collapses public/.htaccess onto the project's own .htaccess.
 * ZipArchive drops a duplicate name without a word, so the loser - the
 * security headers - would go missing and nobody would notice until an audit.
 * Merge them instead.
 */
$merged = null;
if ($flat) {
    $merged = "# Built by bin/package.php - the project and public rules merged, because\n"
        . "# the flat layout has only one document root to put them in.\n\n"
        . file_get_contents($root . '/.htaccess') . "\n"
        . file_get_contents($root . '/public/.htaccess');
    $files = array_values(array_filter(
        $files,
        static fn (string $rel): bool => $rel !== '.htaccess' && $rel !== 'public/.htaccess'
    ));
}

// Any other collision is a packaging mistake, so stop rather than lose a file.
$seen = [];
foreach ($files as $rel) {
    $to = $target($rel);
    if (isset($seen[$to])) {
        exit("Two files would be packed as $to: $seen[$to] and $rel\n");
    }
    $seen[$to] = $rel;
}

@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    exit("Could not open $out for writing\n");
}
foreach ($files as $rel) {
    if (!$zip->addFile($root . '/' . $rel, $target($rel))) {
        exit("Could not add $rel to the archive\n");
    }
}
if ($merged !== null) {
    $zip->addFromString('.htaccess', $merged);
}
$zip->close();

printf(
    "%s\n  %d files, %s\n  layout: %s\n",
    $out,
    count($files) + ($merged !== null ? 1 : 0),
    number_format(filesize($out) / 1024, 1) . ' KB',
    $flat ? 'flat (unzip into the document root)' : 'split (unzip above the document root, point it at public/)'
);
echo "\nNext: DEPLOY.md, \"Putting it on your own server\".\n";
