# RAN Coding Standards

Shared PHP_CodeSniffer standards for Rockets Are Nostalgic (RAN) projects.

The package implements the PHP portion of the RAN organisation quality policy. It centralises rules that are genuinely common while keeping repository identity and support contracts local.

## Standards

- `RAN` — deliberately small technology-neutral PHP safety baseline.
- `RANWordPress` — WPCS + PHPCompatibilityWP baseline for maintained WordPress PHP.
- `RANWordPressPlugin` — WordPress plugin profile.
- `RANWordPressLibrary` — WordPress library profile.

The plugin and library profiles intentionally begin as thin named profiles over `RANWordPress`. Separate names allow future divergence to be explicit and versioned rather than inferred from consumer exceptions.

## Consumer responsibilities

A consumer remains responsible for its actual project contract, including:

- `minimum_supported_wp_version`;
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
    <config name="minimum_supported_wp_version" value="7.0"/>
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

## Version policy

WPCS 3.4.1 currently requires PHP_CodeSniffer 3.x, so the initial package stays on PHPCS `^3.13.6`. A PHPCS 4 migration should be a deliberate package major/minor change once the WordPress standards stack supports it cleanly.

PHPCompatibilityWP 3 remains pre-stable but is the generation that provides PHP 8 compatibility data. Consumers still define the compatibility range locally.

## Development

After generating and committing `composer.lock`:

```sh
composer install --no-interaction
composer check
```

`composer test` verifies that every exported PHPCS standard resolves through the installed dependency graph.
