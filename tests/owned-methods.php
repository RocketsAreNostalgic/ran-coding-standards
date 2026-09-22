<?php

declare(strict_types=1);

// Run inside tests/run.php, using its disposable fixture directory and cleanup.
$owned_source = 'RANOwnedMethods.NamingConventions.ValidMethodName';
$owned_file = $fixtures['ownedMethods'];

$owned_fixture = <<<'PHP'
<?php
interface Contract { public function is_ready(); }
class ParentType { public function is_ready() {} }
class Implementation extends ParentType implements Contract {
    public function is_ready() {}
    private function internal_helper() {}
    private function ä_helper() {}
    private function 处理_项目2() {}
    private function café_helper() {}
    public function __toString() { return ''; }
    public function __debugInfo() { return array(); }
    public static function __callStatic($name, $arguments) {}
    public function closures() {
        $callback = function () {};
        function nestedGlobalFunction() {}
    }
}
// The declaration is an actual external lifecycle signature; only this method is excepted.
class ExternalTest extends \PHPUnit\Framework\TestCase {
    // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle signature.
    protected function setUp(): void {}
    private function owned_helper() {}
}
function unrelatedGlobalFunction() {}
PHP;

$owned_assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$owned_run = static function (string $contents, array $arguments = array()) use ($phpcs, $owned_file, $owned_assert): array {
    $owned_assert(false !== file_put_contents($owned_file, $contents . "\n"), 'Could not write owned-method fixture.');
    // Explicitly prove Unicode checks without the optional mbstring helper.
    $command = escapeshellarg(PHP_BINARY) . ' -d disable_functions=mb_strtolower ' . escapeshellarg($phpcs)
        . ' -n --report=json --standard=RANOwnedMethods';
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = array();
    exec($command . ' ' . escapeshellarg($owned_file) . ' 2>&1', $output, $status);
    $report = json_decode(implode("\n", $output), true);
    $owned_assert(is_array($report) && isset($report['files']), 'Owned-method check did not return JSON: ' . implode("\n", $output));
    $messages = array();
    foreach ($report['files'] as $file) {
        $messages = array_merge($messages, $file['messages']);
    }
    return array($status, $messages);
};

list($owned_status, $owned_messages) = $owned_run($owned_fixture);
$owned_assert(0 === $owned_status && array() === $owned_messages, 'Compliant methods, magic methods or narrow external signature were rejected.');

// One bad declaration in each scope. Nested/global functions are deliberately outside this check.
$owned_bad = <<<'PHP'
interface OwnedContract { public function contractName(); }
interface ChildContract extends Contract { public function childContractName(); }
class ImplementsContract implements Contract {
    public function is_ready() {}
    private function unrelatedCamelCase() {}
}
class ExceptionType extends \RuntimeException {
    public static function fromFailure() {}
}
trait OwnedTrait { protected function traitName() {} }
$anonymous = new class extends ParentType { public function anonymousName() {} };
class OwnedPlain {
    public function ordinaryName() {}
    /** @deprecated Historical naming is not an external contract. */
    public function deprecatedName() {}
    public function __owned_name() {}
    public function ___owned_helper() {}
    public function Ä() {}
    public function ǅ() {}
}
class OtherTest extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void {}
}
PHP;

list($owned_status, $owned_messages) = $owned_run($owned_fixture . "\n" . $owned_bad);
$owned_expected = array('contractName', 'childContractName', 'unrelatedCamelCase', 'fromFailure', 'traitName', 'anonymousName', 'ordinaryName', 'deprecatedName', '__owned_name', '___owned_helper', 'Ä', 'ǅ', 'setUp');
$owned_assert(1 === $owned_status && count($owned_expected) === count($owned_messages), 'Owned declarations were missed or unrelated declarations were reported: ' . json_encode($owned_messages));
foreach ($owned_expected as $owned_index => $owned_name) {
    $message = $owned_messages[$owned_index];
    $code = 0 === strpos($owned_name, '__') ? 'ReservedPrefix' : 'NotSnakeCase';
    $owned_assert(
        $owned_source . '.' . $code === $message['source']
        && false !== strpos($message['message'], '"' . $owned_name . '"')
        && 'ERROR' === $message['type'] && false === $message['fixable'],
        'Expected a non-fixable, blocking diagnostic for ' . $owned_name . ': ' . json_encode($message)
    );
}

// PHPCS tokenizes newer syntax on the supported PHP 7.4 tool runtime too.
list($owned_status, $owned_messages) = $owned_run('<?php enum State { case Ready; public function enumName() {} }');
$owned_assert(1 === $owned_status && 1 === count($owned_messages) && $owned_source . '.NotSnakeCase' === $owned_messages[0]['source'], 'Enum method naming escaped the check.');

// An explicit lifecycle exception must not hide a new method beside it.
list($owned_status, $owned_messages) = $owned_run(str_replace('function owned_helper()', 'function ownedHelper()', $owned_fixture));
$owned_assert(1 === $owned_status && 1 === count($owned_messages) && false !== strpos($owned_messages[0]['message'], '"ownedHelper"'), 'A narrow external override exception hid an owned sibling method.');

// Removing comments must expose the external name: there is no global setUp allowlist.
list($owned_status, $owned_messages) = $owned_run($owned_fixture, array('--ignore-annotations'));
$owned_assert(1 === $owned_status && 1 === count($owned_messages) && false !== strpos($owned_messages[0]['message'], '"setUp"'), 'External signatures bypassed explicit exception handling.');

// This additive standard must not be silently inherited by any existing profile.
foreach (array('RAN', 'RANWordPress', 'RANWordPressPlugin', 'RANWordPressLibrary') as $owned_profile) {
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpcs) . ' -e --standard=' . escapeshellarg($owned_profile) . ' 2>&1', $output, $owned_status);
    $owned_assert(0 === $owned_status && false === strpos(implode("\n", $output), 'RANOwnedMethods'), 'Existing profile unexpectedly activates owned-method checks: ' . $owned_profile);
}

fwrite(STDOUT, "Opt-in owned-method checks cover inherited, implementing, trait, anonymous and enum scopes without broad signature exemptions.\n");

// PHP accepts arbitrary high bytes: the naming policy must reject deceptive names.
foreach (array("Ⅰ", "Ⓐ", "safe\u{202E}evil", "safe😀", "safe\u{200B}name", "safe·name", "safe\xFFname") as $invalid_name) {
    list($owned_status, $owned_messages) = $owned_run('<?php class BadName { public function ' . $invalid_name . '() {} }');
    $owned_assert(1 === $owned_status && 1 === count($owned_messages) && $owned_source . '.NotSnakeCase' === $owned_messages[0]['source'], 'A nonconforming Unicode/byte identifier escaped the check.');
}
