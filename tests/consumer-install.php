<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/ran-coding-standards-consumer-' . bin2hex(random_bytes(6));

if (!mkdir($tmp) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create the consumer fixture directory.\n");
    exit(1);
}

register_shutdown_function(
    static function () use ($tmp): void {
        if (!is_dir($tmp)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isDir()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($tmp);
    }
);

$composerConfig = array(
    'name'         => 'ran/consumer-install-fixture',
    'type'         => 'project',
    'require-dev'  => array(
        'ran/coding-standards' => '1.0.0',
    ),
    'repositories' => array(
        array(
            'type'    => 'path',
            'url'     => str_replace('\\', '/', $root),
            'options' => array(
                'symlink'  => false,
                'versions' => array(
                    'ran/coding-standards' => '1.0.0',
                ),
            ),
        ),
    ),
    'config'       => array(
        'allow-plugins' => array(
            'dealerdirect/phpcodesniffer-composer-installer' => true,
        ),
    ),
);

$json = json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (false === $json || false === file_put_contents($tmp . '/composer.json', $json . "\n")) {
    fwrite(STDERR, "Could not write the consumer Composer fixture.\n");
    exit(1);
}

$composer = getenv('COMPOSER_BINARY');
if (false === $composer || '' === $composer) {
    $composer = 'composer';
}

$composerCommand = 'composer' === $composer ? $composer : escapeshellarg($composer);
$output          = array();
$command         = 'cd ' . escapeshellarg($tmp)
    . ' && ' . $composerCommand
    . ' install --no-interaction --prefer-dist --no-progress 2>&1';
exec($command, $output, $status);

if (0 !== $status) {
    fwrite(STDERR, "A stable consumer root could not install ran/coding-standards:\n" . implode("\n", $output) . "\n");
    exit($status);
}

$installedPackage = $tmp . '/vendor/ran/coding-standards/composer.json';
if (!is_file($installedPackage)) {
    fwrite(STDERR, "The consumer fixture did not install ran/coding-standards.\n");
    exit(1);
}

$installed = file_get_contents($tmp . '/composer.lock');
if (false === $installed) {
    fwrite(STDERR, "Could not read the consumer fixture lockfile.\n");
    exit(1);
}

foreach (array('phpcompatibility/php-compatibility', 'phpcompatibility/phpcompatibility-paragonie', 'phpcompatibility/phpcompatibility-wp') as $developmentPackage) {
    if (false !== strpos($installed, '"name": "' . $developmentPackage . '"')) {
        fwrite(STDERR, sprintf("Stable consumers unexpectedly install development package %s.\n", $developmentPackage));
        exit(1);
    }
}

fwrite(STDOUT, "A stable consumer root installs the package with root-owned plugin permission and without development-only alpha dependencies.\n");