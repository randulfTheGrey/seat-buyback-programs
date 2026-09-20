<?php

declare(strict_types=1);

$distributionCheck = ($argv[2] ?? null) === '--distribution';
$root = isset($argv[1]) ? realpath($argv[1]) : realpath(dirname(__DIR__));

if ($root === false) {
    fwrite(STDERR, "Release-check root does not exist.\n");
    exit(1);
}

$errors = [];
$requiredFiles = [
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'LICENSE',
    'NOTICE.md',
    'README.md',
    'SECURITY.md',
    'composer.json',
    'docs/compatibility.md',
    'docs/configuration.md',
    'docs/installation.md',
    'docs/operations.md',
    'docs/release-checklist.md',
    'docs/releases/1.0.0-rc.1.md',
    'docs/releases/1.0.0-rc.2.md',
    'docs/releases/1.0.0-rc.3.md',
    'docs/releases/1.0.0-rc.4.md',
    'docs/releasing.md',
    'docs/upgrading.md',
];

foreach ($requiredFiles as $requiredFile) {
    if (! is_file($root . '/' . $requiredFile)) {
        $errors[] = "Required public file is missing: {$requiredFile}";
    }
}

if ($distributionCheck) {
    foreach ([
        '.composer-cache',
        '.gitlab-ci.yml',
        'AGENTS.md',
        'build',
        'composer.lock',
        'Dockerfile.validation',
        'vendor',
    ] as $excludedPath) {
        if (file_exists($root . '/' . $excludedPath)) {
            $errors[] = "Distribution archive contains excluded path: {$excludedPath}";
        }
    }
}

$composerPath = $root . '/composer.json';
$composer = is_file($composerPath)
    ? json_decode((string) file_get_contents($composerPath), true)
    : null;

if (! is_array($composer)) {
    $errors[] = 'composer.json is not valid JSON.';
} else {
    $expected = [
        'name' => 'randulfthegrey/seat-buyback-programs',
        'type' => 'seat-plugin',
        'license' => 'MIT',
        'homepage' => 'https://github.com/randulfTheGrey/seat-buyback-programs',
    ];

    foreach ($expected as $key => $value) {
        if (($composer[$key] ?? null) !== $value) {
            $errors[] = "composer.json {$key} must be {$value}.";
        }
    }

    $namespace = 'RandulfTheGrey\\Seat\\BuybackPrograms\\';
    if (($composer['autoload']['psr-4'][$namespace] ?? null) !== 'src/') {
        $errors[] = 'Production PSR-4 namespace is inconsistent.';
    }
    if (($composer['autoload-dev']['psr-4'][$namespace . 'Tests\\'] ?? null) !== 'tests/') {
        $errors[] = 'Test PSR-4 namespace is inconsistent.';
    }
    if (($composer['extra']['laravel']['providers'][0] ?? null) !== $namespace . 'BuybackProgramsServiceProvider') {
        $errors[] = 'Laravel discovery provider is inconsistent.';
    }
}

$license = is_file($root . '/LICENSE') ? (string) file_get_contents($root . '/LICENSE') : '';
if (! str_starts_with($license, "MIT License\n\nCopyright (c) 2026 RandulfTheGrey")) {
    $errors[] = 'LICENSE is not the expected RandulfTheGrey MIT license.';
}

$excludedDirectories = ['.git', '.phpunit.cache', 'build', 'vendor'];
$textExtensions = ['json', 'md', 'php', 'sh', 'xml', 'yml', 'yaml'];
$files = [];
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    $directory,
    static function (SplFileInfo $file) use ($excludedDirectories): bool {
        return ! ($file->isDir() && in_array($file->getFilename(), $excludedDirectories, true));
    }
));

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || ! $file->isFile()) {
        continue;
    }
    if (! in_array(strtolower($file->getExtension()), $textExtensions, true)) {
        continue;
    }
    $files[] = $file->getPathname();
}

$forbiddenPatterns = [
    '/' . 'SeatBuyback' . 'Programs/' => 'provisional PHP namespace',
    '/gitlab\\.' . 'ca1\\.' . 'montibus' . 'technology\\.net/i' => 'private GitLab hostname',
    '#/home/' . 'randulftg(?:/|$)#i' => 'private development path',
    '/montibus' . 'technology\\.com/i' => 'private organization domain',
    '/(?:^|[^A-Za-z0-9_])S' . 'MX(?:[^A-Za-z0-9_]|$)/' => 'private platform name',
    '/glpat-[A-Za-z0-9_-]{20,}/' => 'GitLab access token',
    '/ghp_[A-Za-z0-9]{30,}/' => 'GitHub access token',
    '/github_pat_[A-Za-z0-9_]{30,}/' => 'GitHub fine-grained token',
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/' => 'private key',
];

foreach ($files as $file) {
    $contents = (string) file_get_contents($file);
    $relative = ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR);
    foreach ($forbiddenPatterns as $pattern => $description) {
        if (preg_match($pattern, $contents) === 1) {
            $errors[] = "{$relative} contains {$description}.";
        }
    }

    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
        continue;
    }

    preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $contents, $matches);
    foreach ($matches[1] as $target) {
        $target = trim((string) $target, " <>\t\n\r\0\x0B");
        if ($target === '' || str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            continue;
        }
        $path = rawurldecode(explode('#', $target, 2)[0]);
        if ($path !== '' && ! file_exists(dirname($file) . '/' . $path)) {
            $errors[] = "{$relative} has a broken local link: {$target}";
        }
    }
}

if ($errors !== []) {
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

echo "Release identity, public files, local links, namespace, private-data, and secret checks passed.\n";
