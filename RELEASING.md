# Releases

This package uses the organisation-owned Profile A release lifecycle. Release
Please owns version selection, the changelog, managed release PR, manifest,
version tag and GitHub Release. Do not create or move release tags manually.

## First release

The empty manifest records that no version has been released. The PHP strategy's
`initial-version` is `1.0.0`; the bootstrap boundary is the repository's initial
commit, before the shared standards implementation. Release Please therefore
includes the implementation and subsequent fixes in the first release proposal.
There is no standing `release-as` override or prerelease policy. Later versions
follow Release Please's conventional-commit semantics.

## Qualification and publication

1. Merge reviewed changes through protected PRs. The existing `Quality` workflow
   retains PHP 7.4/8.5, locked package checks, fresh consumer installation, PHP
   syntax validation and the terminal `quality` check.
2. The pinned shared Profile A caller admits only successful same-repository
   `main` push runs of `.github/workflows/quality.yml` whose commit is still main.
   It invokes Release Please and dispatches the existing read-only Quality
   workflow for a bot-created release PR when its exact head lacks qualification.
   Quality's input-free dispatch checks out and verifies `github.sha`.
3. Before merging a release PR, obtain independent review and passing checks on
   its final head. Also attach proof of that exact candidate against both RAN
   Starter and RAN Booster, as required by AGENTS.md. Record candidate and
   consumer SHAs, executed checks and limitations. Package fixtures alone do not
   satisfy this prerequisite; a later candidate must not be labelled qualified
   merely because an earlier candidate passed. Coordinate reference testing with
   the consumer owners without modifying their active branches or locks.
4. Merge the reviewed release PR only with owner authorization. The merged
   release revision must pass main Quality before Profile A invokes Release
   Please to publish its tag and GitHub Release.
5. Verify the published tag and Composer resolution before consumer adoption.
   Consumers update their own locks through reviewed PRs. Publishing this package
   does not enable `RANOwnedMethods` implicitly or remove local naming debt.

The Starter/Booster proof is a maintainer release-review prerequisite; the shared
workflow does not automate that cross-repository proof. Do not treat green
package CI as its replacement. Preserve repository protection and organisation
immutable-release policy. Repair release failures through reviewed changes and
fresh qualification; do not add a parallel publisher or recovery state machine.
