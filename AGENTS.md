# AGENTS.md

## Purpose

This repository owns shared RAN PHP coding-policy configuration. It is not a runtime library.

## Boundaries

- Keep repository-specific prefixes, namespaces, text domains and runtime support ranges out of shared standards.
- Promote a rule only when it is broadly transferable across representative RAN repositories, including Starter and Booster.
- Do not copy Booster-specific legacy or release-product exceptions into the shared baseline.
- Treat `RocketsAreNostalgic/.github` quality policy as normative.

## Validation

Run the locked development contract before proposing changes:

```sh
composer install --no-interaction
composer check
```

A standards change must not be tagged, published, or otherwise released to consumers until the exact candidate revision has been proven against both RAN Starter and RAN Booster. Attach that exact-head evidence to the release decision; passing this repository's synthetic/package contract alone is not sufficient release evidence.

## CI

GitHub Actions is the workflow control plane; jobs run on RAN's Blacksmith runner (`blacksmith-2vcpu-ubuntu-2404`). Keep third-party actions pinned to immutable commit SHAs and checkout credentials disabled before project-controlled commands run.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.
