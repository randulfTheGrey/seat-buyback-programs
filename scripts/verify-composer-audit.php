<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
$payload = $path !== null && is_file($path) ? file_get_contents($path) : false;
$audit = $payload !== false ? json_decode($payload, true) : null;

if (! is_array($audit)) {
    fwrite(STDERR, "Composer audit output is missing or invalid JSON.\n");
    exit(1);
}

$allowed = [
    'PKSA-m5cs-t1y6-qpcs',
    'PKSA-3r5d-mb8f-1qw9',
    'PKSA-mdq4-51ck-6kdq',
];
$found = [];
$unexpected = [];

foreach (($audit['advisories'] ?? []) as $advisories) {
    foreach ($advisories as $advisory) {
        $id = $advisory['advisoryId'] ?? $advisory['composerId'] ?? null;
        if (! is_string($id)) {
            $unexpected[] = 'unknown advisory record';
            continue;
        }
        $found[] = $id;
        if (! in_array($id, $allowed, true)) {
            $unexpected[] = $id;
        }
    }
}

if ($unexpected !== []) {
    fwrite(STDERR, 'Unaccepted dependency advisories: ' . implode(', ', array_unique($unexpected)) . "\n");
    exit(1);
}

echo 'Composer audit contains no unaccepted advisories';
if ($found !== []) {
    echo '; reviewed ADR 0013 records remain visible: ' . implode(', ', array_unique($found));
}
echo ".\n";
