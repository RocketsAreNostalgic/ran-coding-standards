<?php

declare(strict_types=1);

$root  = dirname(__DIR__);
$phpcs = $root . '/vendor/bin/phpcs';

if (!is_file($phpcs)) {
    fwrite(STDERR, "Missing vendor/bin/phpcs. Run composer install first.\n");
    exit(1);
}

$rulesets = array(
    'RAN'                 => $root . '/RAN/ruleset.xml',
    'RANWordPress'        => $root . '/RANWordPress/ruleset.xml',
    'RANWordPressPlugin'  => $root . '/RANWordPressPlugin/ruleset.xml',
    'RANWordPressLibrary' => $root . '/RANWordPressLibrary/ruleset.xml',
);

$requiredRules = array(
    'RAN'                 => array('Generic.PHP.Syntax'),
    'RANWordPress'        => array('RAN', 'PHPCompatibilityWP', 'WordPress-Extra'),
    'RANWordPressPlugin'  => array('RANWordPress'),
    'RANWordPressLibrary' => array('RANWordPress'),
);

$forbiddenSettingPatterns = array(
    'PHPCompatibility testVersion'        => '/^testversion$/',
    'minimum supported WordPress version' => '/^minimum_(?:supported_)?wp_version$/',
    'prefix property or value'             => '/prefix(?:es)?/',
    'text-domain property or value'        => '/text_?domains?/',
    'namespace property'                   => '/namespace/',
    'product-specific support range'       => '/^(?:(?:php|wp|wordpress)_)?support(?:ed)?_(?:range|versions?)$/',
);

$repositoryNamespacePattern = '/^(?:RAN|RocketsAreNostalgic)\\\\[A-Za-z_][A-Za-z0-9_\\\\]*$/i';
$inspectedRulesets           = array();

$findSelectorViolation = static function ($document, string $standard): ?string {
    $selectorNodes = $document->xpath('//file | //include-pattern | //exclude-pattern');
    if (false === $selectorNodes) {
        return sprintf("Could not inspect file selectors in %s.", $standard);
    }

    foreach ($selectorNodes as $selectorNode) {
        if ('' !== trim((string) $selectorNode)) {
            return sprintf(
                "%s embeds a consumer-specific <%s> file selector.",
                $standard,
                $selectorNode->getName()
            );
        }
    }

    return null;
};

$inspectRuleset = static function (string $standard) use (&$inspectRuleset, &$inspectedRulesets, $root, $rulesets, $requiredRules, $forbiddenSettingPatterns, $repositoryNamespacePattern, $findSelectorViolation): void {
    $ruleset = $rulesets[$standard] ?? $standard;
    if (isset($inspectedRulesets[$ruleset])) {
        return;
    }

    $document = simplexml_load_file($ruleset, 'SimpleXMLElement', LIBXML_NONET);
    if (false === $document) {
        fwrite(STDERR, sprintf("Could not parse %s ruleset.\n", $standard));
        exit(1);
    }

    $ruleNodes = $document->xpath('/ruleset/rule[@ref]');
    if (false === $ruleNodes) {
        fwrite(STDERR, sprintf("Could not inspect active rules in %s.\n", $standard));
        exit(1);
    }

    $activeRules = array();
    foreach ($ruleNodes as $ruleNode) {
        $activeRules[] = (string) $ruleNode['ref'];
    }

    foreach ($requiredRules[$standard] ?? array() as $requiredRule) {
        if (!in_array($requiredRule, $activeRules, true)) {
            fwrite(STDERR, sprintf("%s is missing active rule %s.\n", $standard, $requiredRule));
            exit(1);
        }
    }

    $settingNodes = $document->xpath('//config[@name] | //property[@name]');
    if (false === $settingNodes) {
        fwrite(STDERR, sprintf("Could not inspect settings in %s.\n", $standard));
        exit(1);
    }

    foreach ($settingNodes as $settingNode) {
        $settingName = strtolower(str_replace('-', '_', (string) $settingNode['name']));

        foreach ($forbiddenSettingPatterns as $setting => $pattern) {
            if (1 === preg_match($pattern, $settingName)) {
                fwrite(STDERR, sprintf("%s embeds consumer-specific %s configuration.\n", $standard, $setting));
                exit(1);
            }
        }
    }

    $valueNodes = $document->xpath('//config[@value] | //property[@value] | //element[@value]');
    if (false === $valueNodes) {
        fwrite(STDERR, sprintf("Could not inspect values in %s.\n", $standard));
        exit(1);
    }

    foreach ($valueNodes as $valueNode) {
        if (1 === preg_match($repositoryNamespacePattern, (string) $valueNode['value'])) {
            fwrite(STDERR, sprintf("%s embeds a repository-specific namespace identity.\n", $standard));
            exit(1);
        }
    }

    $selectorViolation = $findSelectorViolation($document, $standard);
    if (null !== $selectorViolation) {
        fwrite(STDERR, $selectorViolation . "\n");
        exit(1);
    }

    $inspectedRulesets[$ruleset] = true;

    foreach ($activeRules as $activeRule) {
        if (isset($rulesets[$activeRule])) {
            $inspectRuleset($activeRule);
            continue;
        }

        // Inspect local file/directory references as well as public standard names.
        foreach (array(dirname($ruleset) . '/' . $activeRule, $root . '/' . $activeRule, $activeRule) as $candidate) {
            if (is_dir($candidate)) {
                $candidate .= '/ruleset.xml';
            }
            $resolved = realpath($candidate);
            if (false !== $resolved && is_file($resolved)
                && 0 === strpos($resolved, $root . '/')
                && 0 !== strpos($resolved, $root . '/vendor/')
                && 'xml' === pathinfo($resolved, PATHINFO_EXTENSION)
            ) {
                $inspectRuleset($resolved);
                break;
            }
        }
    }
};

foreach (array_keys($rulesets) as $standard) {
    $output  = array();
    $command = escapeshellarg($phpcs) . ' -e --standard=' . escapeshellarg($standard) . ' 2>&1';
    exec($command, $output, $status);

    if (0 !== $status) {
        fwrite(STDERR, sprintf("Standard %s failed to resolve:\n%s\n", $standard, implode("\n", $output)));
        exit($status);
    }

    $inspectRuleset($standard);
}

foreach (array('file', 'include-pattern', 'exclude-pattern') as $selectorName) {
    $selectorFixture = simplexml_load_string(
        sprintf('<ruleset name="Selector negative"><%1$s>booster-only/</%1$s></ruleset>', $selectorName),
        'SimpleXMLElement',
        LIBXML_NONET
    );
    if (false === $selectorFixture) {
        fwrite(STDERR, sprintf("Could not parse the %s negative selector fixture.\n", $selectorName));
        exit(1);
    }

    if (null === $findSelectorViolation($selectorFixture, 'selector negative fixture')) {
        fwrite(STDERR, sprintf("The package boundary failed to reject an active <%s> selector.\n", $selectorName));
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/ran-coding-standards-' . bin2hex(random_bytes(6));
if (!mkdir($tmp) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary fixture directory.\n");
    exit(1);
}

$fixtures = array(
    'valid'         => $tmp . '/valid.php',
    'syntaxInvalid' => $tmp . '/syntax-invalid.php',
    'wordpress'     => $tmp . '/ran-shared-profile-fixture.php',
    'compatInvalid' => $tmp . '/compatibility-invalid.php',
    'prefixInvalid' => $tmp . '/prefix-invalid.php',
    'pluginRuleset' => $tmp . '/plugin-ruleset.xml',
    'libraryRuleset'=> $tmp . '/library-ruleset.xml',
);

register_shutdown_function(
    static function () use ($fixtures, $tmp): void {
        foreach ($fixtures as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($tmp)) {
            @rmdir($tmp);
        }
    }
);

$fixtureContents = array(
    'valid' => "<?php\n\ndeclare(strict_types=1);\n\nfunction ran_fixture(): void {}\n",
    'syntaxInvalid' => "<?php\n\nfunction ran_fixture( {\n",
    'wordpress' => <<<'PHP'
<?php
/**
 * Shared RAN WordPress profile fixture.
 *
 * @package RANFixture
 */

namespace RANFixture;

/**
 * Representative consumer fixture.
 */
final class Example {
	/**
	 * Return a stable fixture value.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return true;
	}
}
PHP,
    'compatInvalid' => <<<'PHP'
<?php
/**
 * Compatibility-negative fixture.
 *
 * @package RANFixture
 */

namespace RANFixture;

get_debug_type( true );
PHP,
    'prefixInvalid' => <<<'PHP'
<?php
/**
 * Prefix-negative fixture.
 *
 * @package RANFixture
 */

function unrelated_global_fixture() {
	return true;
}
PHP,
);

foreach ($fixtureContents as $fixture => $content) {
    if (false === file_put_contents($fixtures[$fixture], $content . "\n")) {
        fwrite(STDERR, sprintf("Could not write the %s fixture.\n", $fixture));
        exit(1);
    }
}

$consumerRulesetTemplate = <<<'XML'
<?xml version="1.0"?>
<ruleset name="RAN Consumer Fixture">
    <config name="minimum_wp_version" value="7.0"/>
    <config name="testVersion" value="7.4-7.4"/>
    <rule ref="%s"/>
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="RANFixture"/>
            </property>
        </properties>
    </rule>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="ran-fixture"/>
            </property>
        </properties>
    </rule>
</ruleset>
XML;

foreach (array('pluginRuleset' => 'RANWordPressPlugin', 'libraryRuleset' => 'RANWordPressLibrary') as $fixture => $standard) {
    if (false === file_put_contents($fixtures[$fixture], sprintf($consumerRulesetTemplate, $standard) . "\n")) {
        fwrite(STDERR, sprintf("Could not write the %s consumer ruleset.\n", $standard));
        exit(1);
    }
}

$runPhpcs = static function (string $standard, string $fixture) use ($phpcs): array {
    $output  = array();
    $command = escapeshellarg($phpcs) . ' --report=json --standard=' . escapeshellarg($standard) . ' ' . escapeshellarg($fixture) . ' 2>&1';
    exec($command, $output, $status);

    $report = json_decode(implode("\n", $output), true);
    if (!is_array($report) || !isset($report['files']) || !is_array($report['files'])) {
        fwrite(STDERR, "PHPCS did not return a valid JSON report:\n" . implode("\n", $output) . "\n");
        exit(0 === $status ? 1 : $status);
    }

    return array($status, $report);
};

$assertPhpcsPasses = static function (string $standard, string $fixture, string $message) use ($runPhpcs): void {
    list($status, $report) = $runPhpcs($standard, $fixture);
    if (0 !== $status) {
        fwrite(STDERR, $message . ":\n" . json_encode($report, JSON_PRETTY_PRINT) . "\n");
        exit($status);
    }
};

$assertPhpcsReports = static function (string $standard, string $fixture, string $sourcePattern, string $message) use ($runPhpcs): void {
    list($status, $report) = $runPhpcs($standard, $fixture);
    if (0 === $status) {
        fwrite(STDERR, $message . ".\n");
        exit(1);
    }

    foreach ($report['files'] as $file) {
        if (!isset($file['messages']) || !is_array($file['messages'])) {
            continue;
        }

        foreach ($file['messages'] as $diagnostic) {
            if (isset($diagnostic['source']) && 1 === preg_match($sourcePattern, $diagnostic['source'])) {
                return;
            }
        }
    }

    fwrite(STDERR, $message . ":\n" . json_encode($report, JSON_PRETTY_PRINT) . "\n");
    exit(1);
};

$assertPhpcsPasses('RAN', $fixtures['valid'], 'RAN rejected a syntactically valid fixture');
$assertPhpcsReports('RAN', $fixtures['syntaxInvalid'], '/^Generic\.PHP\.Syntax\./', 'RAN failed to report the syntax error');

foreach (array('pluginRuleset', 'libraryRuleset') as $profileRuleset) {
    $assertPhpcsPasses($fixtures[$profileRuleset], $fixtures['wordpress'], 'A WordPress profile rejected the clean consumer fixture');
    $assertPhpcsReports($fixtures[$profileRuleset], $fixtures['compatInvalid'], '/^PHPCompatibility\./', 'PHPCompatibilityWP failed to report syntax outside testVersion');
    $assertPhpcsReports($fixtures[$profileRuleset], $fixtures['prefixInvalid'], '/^WordPress\.NamingConventions\.PrefixAllGlobals\.NonPrefixedFunctionFound$/', 'The consumer-local prefix rule failed to report a non-prefixed global');
}

fwrite(STDOUT, "All RAN standards preserve active inheritance and consumer boundaries, and their behavioral fixtures pass.\n");