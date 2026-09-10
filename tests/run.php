<?php

declare(strict_types=1);

$root  = dirname(__DIR__);
$phpcs = $root . '/vendor/bin/phpcs';

if (!is_file($phpcs)) {
    fwrite(STDERR, "Missing vendor/bin/phpcs. Run composer install first.\n");
    exit(1);
}

$standards = array(
    'RAN',
    'RANWordPress',
    'RANWordPressPlugin',
    'RANWordPressLibrary',
);

foreach ($standards as $standard) {
    $command = escapeshellarg($phpcs) . ' -e --standard=' . escapeshellarg($standard) . ' 2>&1';
    exec($command, $output, $status);

    if (0 !== $status) {
        fwrite(STDERR, sprintf("Standard %s failed to resolve:\n%s\n", $standard, implode("\n", $output)));
        exit($status);
    }

    $output = array();
}

$tmp = sys_get_temp_dir() . '/ran-coding-standards-' . bin2hex(random_bytes(6));
if (!mkdir($tmp) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary fixture directory.\n");
    exit(1);
}

$valid   = $tmp . '/valid.php';
$invalid = $tmp . '/invalid.php';
file_put_contents($valid, "<?php\n\ndeclare(strict_types=1);\n\nfunction ran_fixture(): void {}\n");
file_put_contents($invalid, "<?php\n\nfunction ran_fixture( {\n");

$validCommand = escapeshellarg($phpcs) . ' --standard=RAN ' . escapeshellarg($valid) . ' 2>&1';
exec($validCommand, $output, $status);
if (0 !== $status) {
    fwrite(STDERR, "RAN rejected a syntactically valid fixture:\n" . implode("\n", $output) . "\n");
    exit($status);
}

$output = array();
$invalidCommand = escapeshellarg($phpcs) . ' --standard=RAN ' . escapeshellarg($invalid) . ' 2>&1';
exec($invalidCommand, $output, $status);
if (0 === $status) {
    fwrite(STDERR, "RAN failed to reject a syntax-error fixture.\n");
    exit(1);
}

@unlink($valid);
@unlink($invalid);
@rmdir($tmp);

fwrite(STDOUT, "All RAN PHPCS standards resolve and the root syntax gate behaves as expected.\n");
