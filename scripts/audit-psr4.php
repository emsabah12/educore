<?php

declare(strict_types=1);

/**
 * PSR-4 Audit Script
 *
 * Tujuan:
 * - Memeriksa namespace terhadap struktur folder.
 * - Memeriksa class/interface/trait/enum terhadap nama file.
 * - Tidak mengubah file apa pun.
 *
 * Usage:
 * php scripts/audit-psr4.php
 */

$projectRoot = dirname(__DIR__);
$modulesPath = $projectRoot . DIRECTORY_SEPARATOR . 'Modules';

if (!is_dir($modulesPath)) {
    fwrite(
        STDERR,
        sprintf(
            "ERROR: Directory Modules tidak ditemukan: %s%s",
            $modulesPath,
            PHP_EOL
        )
    );

    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $modulesPath,
        FilesystemIterator::SKIP_DOTS
    )
);

$totalFiles = 0;
$phpFiles = 0;
$checkedClasses = 0;
$errors = 0;

echo "========================================" . PHP_EOL;
echo "EduCore PSR-4 Audit" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Project : {$projectRoot}" . PHP_EOL;
echo "Target  : {$modulesPath}" . PHP_EOL;
echo PHP_EOL;

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }

    if (!$file->isFile()) {
        continue;
    }

    $totalFiles++;

    if (strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $phpFiles++;

    $absolutePath = $file->getPathname();

    $relativePath = str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        substr(
            $absolutePath,
            strlen($modulesPath) + 1
        )
    );

    $content = file_get_contents($absolutePath);

    if ($content === false) {
        echo "[ERROR] Tidak dapat membaca: {$relativePath}" . PHP_EOL;
        $errors++;
        continue;
    }

    $namespace = null;

    if (preg_match(
        '/^\s*namespace\s+([^;]+);/m',
        $content,
        $namespaceMatch
    )) {
        $namespace = trim($namespaceMatch[1]);
    }

    if ($namespace === null) {
        continue;
    }

    $declarations = [];

    $patterns = [
        'class' => '/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m',
        'interface' => '/^\s*interface\s+([A-Za-z_][A-Za-z0-9_]*)/m',
        'trait' => '/^\s*(?:final\s+)?trait\s+([A-Za-z_][A-Za-z0-9_]*)/m',
        'enum' => '/^\s*enum\s+([A-Za-z_][A-Za-z0-9_]*)/m',
    ];

    foreach ($patterns as $type => $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $name) {
                $declarations[] = [
                    'type' => $type,
                    'name' => $name,
                ];
            }
        }
    }

    if ($declarations === []) {
        continue;
    }

    foreach ($declarations as $declaration) {
        $checkedClasses++;

        $expectedClass = $declaration['name'];

        $expectedRelativePath =
            str_replace(
                '\\',
                DIRECTORY_SEPARATOR,
                $namespace
            )
            . DIRECTORY_SEPARATOR
            . $expectedClass
            . '.php';

        $actualRelativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $relativePath
        );

        $expectedRelativePathNormalized = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $expectedRelativePath
        );

        $actualFullyQualifiedClass =
            $namespace . '\\' . $expectedClass;

        if ($actualRelativePath !== $expectedRelativePathNormalized) {
            echo PHP_EOL;
            echo "[FAIL] PSR-4 mismatch" . PHP_EOL;
            echo "  Type      : {$declaration['type']}" . PHP_EOL;
            echo "  Class     : {$actualFullyQualifiedClass}" . PHP_EOL;
            echo "  Actual    : Modules/{$actualRelativePath}" . PHP_EOL;
            echo "  Expected  : Modules/{$expectedRelativePathNormalized}" . PHP_EOL;

            $errors++;

            continue;
        }

        echo "[PASS] {$actualFullyQualifiedClass}" . PHP_EOL;
    }
}

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Audit Summary" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Total files scanned : {$totalFiles}" . PHP_EOL;
echo "PHP files scanned   : {$phpFiles}" . PHP_EOL;
echo "Declarations checked: {$checkedClasses}" . PHP_EOL;
echo "Problems found      : {$errors}" . PHP_EOL;
echo PHP_EOL;

if ($errors > 0) {
    echo "RESULT: PSR-4 AUDIT FAILED" . PHP_EOL;
    exit(1);
}

echo "RESULT: PSR-4 AUDIT PASSED" . PHP_EOL;
exit(0);
