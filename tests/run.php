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
    $output  = array();
    $command = escapeshellarg($phpcs) . ' -e --standard=' . escapeshellarg($standard) . ' 2>&1';
    exec($command, $output, $status);

    if (0 !== $status) {
        fwrite(STDERR, sprintf("Standard %s failed to resolve:\n%s\n", $standard, implode("\n", $output)));
        exit($status);
    }
}

$rulesets = array(
    'RAN'                 => $root . '/RAN/ruleset.xml',
    'RANWordPress'        => $root . '/RANWordPress/ruleset.xml',
    'RANWordPressPlugin'  => $root . '/RANWordPressPlugin/ruleset.xml',
    'RANWordPressLibrary' => $root . '/RANWordPressLibrary/ruleset.xml',
);

$requiredFragments = array(
    'RAN'                 => array('<rule ref="Generic.PHP.Syntax"/>'),
    'RANWordPress'        => array('<rule ref="RAN"/>', '<rule ref="PHPCompatibilityWP"/>', '<rule ref="WordPress-Extra">'),
    'RANWordPressPlugin'  => array('<rule ref="RANWordPress"/>'),
    'RANWordPressLibrary' => array('<rule ref="RANWordPress"/>'),
);

$forbiddenPatterns = array(
    '/<config\b[^>]*\bname\s*=\s*["\']minimum_supported_wp_version["\']/',
    '/<config\b[^>]*\bname\s*=\s*["\']testVersion["\']/',
    '/<property\b[^>]*\bname\s*=\s*["\']prefixes["\']/',
    '/<property\b[^>]*\bname\s*=\s*["\']text_domain["\']/',
);

foreach ($rulesets as $standard => $ruleset) {
    $content = file_get_contents($ruleset);
    if (false === $content) {
        fwrite(STDERR, sprintf("Could not read %s ruleset.\n", $standard));
        exit(1);
    }

    foreach ($requiredFragments[$standard] as $fragment) {
        if (false === strpos($content, $fragment)) {
            fwrite(STDERR, sprintf("%s is missing required inheritance fragment: %s\n", $standard, $fragment));
            exit(1);
        }
    }

    foreach ($forbiddenPatterns as $pattern) {
        if (1 === preg_match($pattern, $content)) {
            fwrite(STDERR, sprintf("%s embeds consumer-specific project configuration.\n", $standard));
            exit(1);
        }
    }
}

$tmp = sys_get_temp_dir() . '/ran-coding-standards-' . bin2hex(random_bytes(6));
if (!mkdir($tmp) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary fixture directory.\n");
    exit(1);
}

$valid          = $tmp . '/valid.php';
$invalid        = $tmp . '/invalid.php';
$wordpress      = $tmp . '/ran-shared-profile-fixture.php';
$pluginRuleset  = $tmp . '/plugin-ruleset.xml';
$libraryRuleset = $tmp . '/library-ruleset.xml';
$temporaryFiles = array($valid, $invalid, $wordpress, $pluginRuleset, $libraryRuleset);

register_shutdown_function(
    static function () use ($temporaryFiles, $tmp): void {
        foreach ($temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($tmp)) {
            @rmdir($tmp);
        }
    }
);

if (false === file_put_contents($valid, "<?php\n\ndeclare(strict_types=1);\n\nfunction ran_fixture(): void {}\n")) {
    fwrite(STDERR, "Could not write the valid PHPCS fixture.\n");
    exit(1);
}

if (false === file_put_contents($invalid, "<?php\n\nfunction ran_fixture( {\n")) {
    fwrite(STDERR, "Could not write the invalid PHPCS fixture.\n");
    exit(1);
}

$wordpressFixture = <<<'PHP'
<?php
/**
 * Shared RAN WordPress profile fixture.
 *
 * @package RAN
 */

/**
 * Return a stable fixture value.
 *
 * @return bool
 */
function ran_shared_profile_fixture() {
	return true;
}
PHP;

if (false === file_put_contents($wordpress, $wordpressFixture . "\n")) {
    fwrite(STDERR, "Could not write the WordPress profile fixture.\n");
    exit(1);
}

$consumerRulesetTemplate = <<<'XML'
<?xml version="1.0"?>
<ruleset name="RAN Consumer Fixture">
    <config name="minimum_supported_wp_version" value="7.0"/>
    <config name="testVersion" value="7.4-8.5"/>
    <rule ref="%s"/>
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="ran"/>
            </property>
        </properties>
    </rule>
</ruleset>
XML;

if (false === file_put_contents($pluginRuleset, sprintf($consumerRulesetTemplate, 'RANWordPressPlugin') . "\n")) {
    fwrite(STDERR, "Could not write the plugin consumer ruleset.\n");
    exit(1);
}

if (false === file_put_contents($libraryRuleset, sprintf($consumerRulesetTemplate, 'RANWordPressLibrary') . "\n")) {
    fwrite(STDERR, "Could not write the library consumer ruleset.\n");
    exit(1);
}

$output       = array();
$validCommand = escapeshellarg($phpcs) . ' --standard=RAN ' . escapeshellarg($valid) . ' 2>&1';
exec($validCommand, $output, $status);
if (0 !== $status) {
    fwrite(STDERR, "RAN rejected a syntactically valid fixture:\n" . implode("\n", $output) . "\n");
    exit($status);
}

$output         = array();
$invalidCommand = escapeshellarg($phpcs) . ' --standard=RAN ' . escapeshellarg($invalid) . ' 2>&1';
exec($invalidCommand, $output, $status);
if (0 === $status) {
    fwrite(STDERR, "RAN failed to reject a syntax-error fixture.\n");
    exit(1);
}

foreach (array('plugin' => $pluginRuleset, 'library' => $libraryRuleset) as $profile => $consumerRuleset) {
    $output  = array();
    $command = escapeshellarg($phpcs) . ' --standard=' . escapeshellarg($consumerRuleset) . ' ' . escapeshellarg($wordpress) . ' 2>&1';
    exec($command, $output, $status);

    if (0 !== $status) {
        fwrite(STDERR, sprintf("The %s WordPress profile rejected the clean consumer fixture:\n%s\n", $profile, implode("\n", $output)));
        exit($status);
    }
}

fwrite(STDOUT, "All RAN standards resolve, preserve the shared/local boundary, and execute cleanly through the public WordPress profiles.\n");
