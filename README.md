# RAN Coding Standards

Shared PHP_CodeSniffer standards for Rockets Are Nostalgic (RAN) projects.

The package implements the PHP portion of the RAN organisation quality policy. It centralises rules that are genuinely common while keeping repository identity and support contracts local.

## Standards

- `RAN` — deliberately small technology-neutral PHP safety baseline.
- `RANWordPress` — WPCS + PHPCompatibilityWP baseline for maintained WordPress PHP.
- `RANWordPressPlugin` — WordPress plugin profile.
- `RANWordPressLibrary` — WordPress library profile.
- `RANOwnedMethods` — opt-in additional method-name enforcement for selected first-party code, including inherited classes. Use alongside a WordPress profile, not instead of one.

The plugin and library profiles intentionally begin as thin named profiles over `RANWordPress`. Separate names allow future divergence to be explicit and versioned rather than inferred from consumer exceptions.

PHPCS is the authoritative PHP style check; PHPCBF applies fixes using the same consumer ruleset and file scope. Both WordPress profiles report assignment and array alignment violations as errors, so hiding warnings with `-n` cannot silently disable those formatting checks. Consumers do not need a second PHP formatter for this guarantee.

## Effective policy and applicability

The [organisation quality policy](https://github.com/RocketsAreNostalgic/.github/blob/3d355edac056effd4a08a51f6e13e2ec05e80ebb/QUALITY_STANDARDS.md#next-beta-booster-php-acceptance)
defines the next-beta Booster acceptance boundary. The existing profiles express
that policy; code-surface differences do not require new public standards.
`RANWordPress` imports `WordPress-Extra`, which includes `WordPress-Core`, plus
PHP syntax and WordPress-aware compatibility checks. It does not import optional
`WordPress-Docs`. Accurate public contracts and useful type information remain
required; stronger documentation checks adopted by a consumer remain in force.

The shared deviations from WordPress-Extra are:

| Rule | Shared decision |
| --- | --- |
| `WordPress.Files.FileName.NotHyphenatedLowercase` and `InvalidClassFileName` | Excluded to retain Composer/PSR-4 class filenames. |
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | Excluded: a Throwable payload is diagnostic data, not HTML output. Preserve the payload and escape when it is rendered. |
| `Generic.Formatting.MultipleStatementAlignment` and `WordPress.Arrays.MultipleStatementAlignment` | Alignment violations are errors, including when warnings are hidden. |

The exception-message decision does not exempt `echo`, `print` or other actual
output from escaping checks, and does not certify an application's exception
handler. Consumers must verify their rendering boundaries and avoid exposing
private diagnostics, including uncaught exceptions through `display_errors` or
debug output. Existing local exception-message exclusions or annotations
become redundant when adopting this version; their removal belongs to the
consumer's reviewed upgrade, not an automatic source rewrite by this package.

| Consumer code surface | Application of the existing rules |
| --- | --- |
| WordPress runtime and templates | Common formatting, naming and compatibility, with applicable input, output, nonce, SQL and localisation checks. |
| Standalone PHP and CLI | Retain common formatting, naming and compatibility; scope only demonstrably inapplicable framework checks locally. Use standalone PHPCompatibility where WordPress polyfills are unavailable. |
| Tests and fixtures | Retain the common convention with narrow foreign lifecycle, controlled-global, diagnostic or intentional synthetic-contract allowances. Ordinary helpers are not exempt because they live in tests. |

`RAN` alone checks syntax; it is not the common formatting/naming profile for
standalone tools. Consumers own path selection, including root files, templates,
tests, tools and extensionless PHP entrypoints. Every maintained first-party PHP
file needs an accounted-for checked scope; syntax-only coverage requires a
concrete reviewed justification. Generated first-party copies may avoid duplicate
style checking only when the authoritative source is checked and parity is
verified. Distinguish source-distribution files from runtime-archive files.

WordPress Yoda conditions remain enabled; preserve evaluation order when fixing
them. Native filesystem, JSON and base64 operations have no category-wide shared
waiver: a required locking/atomicity invariant, serialization contract or opaque
protocol value needs its precise local reason and scope. SQL and rendered-output
boundaries need source/behavioral evidence when the checker cannot trace them.
No passing PHPCS result substitutes for PHPStan, behavior tests or these proofs.

Use existing annotations and ruleset comments for narrow local exceptions, with
matching check/fix scope and regression controls. Blanket all-rule suppressions
are not permitted in maintained code; intentionally malformed fixtures need an
explicit fixture boundary. A named file-wide exception requires a file-wide
reason. Public visibility alone is not a foreign API contract. Transitional debt
needs an owner and removal condition; an unresolved rationale is not acceptance.
These decisions do not reopen beta.31 or authorize consumer upgrades/releases.

## Consumer installation

A consumer can install the package from a root that keeps Composer's default stable minimum:

```json
{
    "require-dev": {
        "ran/coding-standards": "^1.0",
        "phpcompatibility/php-compatibility": "10.0.0-alpha2",
        "phpcompatibility/phpcompatibility-paragonie": "2.0.0-alpha2",
        "phpcompatibility/phpcompatibility-wp": "3.0.0-alpha2"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

Composer ignores dependency packages' `minimum-stability` and `config.allow-plugins` settings. The distributable package therefore has only stable unconditional dependencies. A consumer using `RANWordPress`, `RANWordPressPlugin`, or `RANWordPressLibrary` must explicitly require the exact reviewed PHPCompatibility generation above in its root development dependencies, or deliberately adopt an equivalent later stable generation after review. The consumer root must also grant `dealerdirect/phpcodesniffer-composer-installer` permission itself.

## Consumer responsibilities

A consumer remains responsible for its actual project contract, including:

- `minimum_wp_version`;
- PHPCompatibility `testVersion` matching its declared PHP support;
- namespace/global prefixes;
- text domain;
- source and generated/vendor paths;
- fixture, inherited-API, legacy or product-specific exceptions.

Those values must not be promoted into this package merely because one repository needs them.

Example:

```xml
<?xml version="1.0"?>
<ruleset name="Project">
    <config name="minimum_wp_version" value="7.0"/>
    <config name="testVersion" value="8.4-8.5"/>

    <file>.</file>
    <exclude-pattern>/vendor/</exclude-pattern>
    <exclude-pattern>/node_modules/</exclude-pattern>

    <rule ref="RANWordPressPlugin"/>

    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="my_plugin"/>
                <element value="My\\Plugin\\"/>
            </property>
        </properties>
    </rule>
</ruleset>
```

## Staged owned-method enforcement

WPCS 3.4.1 skips method-name checks for classes that extend another class or
implement an interface. That also skips unrelated private methods owned by the
project. `RANOwnedMethods` closes this gap for the first-party source selected by
the consumer. It checks declarations in classes, interfaces, traits, anonymous
classes and enums, regardless of inheritance or deprecation annotations. PHP
magic methods remain valid. Global functions and calls are outside this additive
check; the ordinary WordPress profile remains responsible for its other rules.

None of the four existing profiles enables this check implicitly. Once an owned
scope is migrated and its callers are qualified, opt in explicitly:

```xml
<rule ref="RANWordPressLibrary"/>
<rule ref="RANOwnedMethods"/>
```

Use the same consumer ruleset and paths for PHPCS and PHPCBF. These errors are
blocking even with `-n` and are deliberately not autofixable: a declaration-only
rename cannot safely update callers, named arguments or dynamic references.
Standalone methods may also receive the equivalent WPCS diagnostic; this check
supplements, rather than disables, upstream enforcement.

The check does not load application classes or infer which methods implement
third-party contracts. A demonstrated external signature needs a narrow local
exception, for example:

```php
// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle signature.
protected function setUp(): void {}
```

That exception does not exempt other methods in the class. Do not globally allow
names such as `setUp`, exempt every derived class, or preserve owned camelCase
methods simply by marking them deprecated. Keep vendor/generated selection and
real external-contract exceptions in the consumer. Any transitional exclusion
must identify its scope, reason, owning issue and removal slice.

The rollout is owned by
[the existing quality programme](https://github.com/RocketsAreNostalgic/.github/issues/65#rollout-plan-and-agent-handoffs).
Prepare candidate tooling independently; consumer activation and lock updates
follow each release lane's recorded handoff. This opt-in delivery is migration
staging, not an approved permanent naming exemption. The package's exact
RAN Starter/RAN Booster proof and release-authorization requirements still apply.

## Version policy

WPCS 3.4.1 currently requires PHP_CodeSniffer 3.x, so the initial package stays on PHPCS `^3.13.6`. A PHPCS 4 migration should be a deliberate package major/minor change once the WordPress standards stack supports it cleanly.

PHPCompatibilityWP 3 remains pre-stable but is the generation that provides PHP 8 compatibility data. It is pinned in this repository's development dependencies for package verification, while WordPress-profile consumers explicitly own their reviewed PHPCompatibility generation and compatibility range.

## Development

After generating and committing `composer.lock`:

```sh
composer install --no-interaction
composer check
```

`composer check` performs strict Composer validation, proves stable-root consumer installation without transitive alpha dependencies, verifies every exported PHPCS standard resolves with active inheritance and no consumer configuration (including repository-owned referenced XML rulesets), and runs positive and negative behavioral fixtures through both public WordPress profiles.

CI also installs `tests/consumer.json` as a fresh WordPress consumer root, without a lockfile or inherited Composer home. This tests the documented root-level alpha requirements and plugin permission against an archive of the exact checked-out revision, then verifies registration of all four standards. Its `dev-under-test` version and path repository are test-only, not release configuration.

Owned-method identifiers use the explicit ASCII pattern `[_a-z][_a-z0-9]*`.
This opt-in RAN policy is independent of Unicode database and mbstring versions;
it is not a claim that upstream WPCS requires ASCII-only identifiers. Non-ASCII
and malformed byte identifiers are rejected, with their bytes rendered as hex
in diagnostics. Safe ASCII identifiers remain readable in diagnostics. Genuine
PHP magic methods retain their exemption; other double-underscore prefixes
remain reserved.

The package's own `composer analyze` runs locked PHPStan at level 8 over the
repository root, including the custom sniff and all maintained test programs.
Only the root dependency directory is excluded; new PHP files enter automatically.
The existing test runner independently compares maintained-file discovery with
the analyzer's actual CLI file trace, checks the effective level, and exercises
new root/nested files and an exclusion that would otherwise leave clean analysis.
Nonstandard PHP extensions require deliberate scope review rather than silently
escaping discovery. No analysis baselines or maintained-file exemptions are added.

Caller-provided include variables have explicit PHPDoc contracts. PHPDoc types
are not treated as certain, preserving defensive SimpleXML failure checks.
PHPCS's real autoloader and locked PHPCSUtils supply dependency symbols without
claiming those dependencies are directly analyzed. PHPStan remains a development
dependency and does not install transitively in consumers. This quality-only
change does not alter or release any exported sniff or ruleset.

## Package source profile

The repository-local `.phpcs.xml` checks every maintained PHP file using the
existing library and owned-method profiles, with the declared PHP 7.4 floor.
`composer standards` and `composer standards:fix` share that root scope and
ruleset; only the root `vendor/` dependency tree is excluded. This configuration
is not an exported consumer standard and does not alter any exported ruleset.

The three CLI test programs retain a file-local exemption only for the exact
global-variable prefix diagnostic: their local state lives in an isolated CLI
process. Snake-case variables, declarations, methods and future files stay
checked. Owned local names were migrated without changing embedded fixture
identities, API callback names or PHPCS diagnostic expectations.

Occurrence-specific exceptions have these concrete boundaries:

| Boundary | Reason |
| --- | --- |
| Native filesystem and pipes | Read repository bytes, create/remove private disposable fixtures, and exchange inert checker input without loading WordPress. |
| Process and JSON operations | Execute the selected CLI checkers/isolated Composer proof and encode machine-readable fixture evidence. |
| CLI termination and cleanup | Integer `exit` arguments are status codes, not rendered output; suppressed shutdown cleanup warnings must not replace an earlier test failure. |
| Literal diagnostic data | Two hexadecimal strings are expected identifier bytes, never arithmetic operands. |

Every exception uses an exact diagnostic at its occurrence. The existing tests
read actual source annotations and append outside-scope violations through the
locked full local profile; changing to a broad suppression fails independently.
The retained CLI variable allowance cannot hide new declarations or snake-case
violations. Effective file selection, new root/nested paths, inherited owned
methods and matching canonical check/fix commands have regression controls.
This candidate requires review of these concrete reasons; a comment alone does
not establish wider exception acceptance.

## Releases

Release Please manages releases through the shared Profile A workflow. See
[RELEASING.md](RELEASING.md) for the first-release configuration and required
Starter/Booster qualification before publication.
