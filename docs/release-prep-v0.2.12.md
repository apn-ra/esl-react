# Release Prep — v0.2.12

This note summarizes the current patch-release surface after the runtime
ingress boundary fix, reconnect/mutation hostile-path hardening, contract/doc
truth sync, and release-gate normalization work that landed after `v0.2.11`.

It is intended as a concise tag-prep aid and release-note source for the next
patch release after `v0.2.11`.

## Scope of this patch

This release is a bounded runtime hardening and release-truth patch.

Implemented in this release:

- runtime ingress no longer depends on unsupported `apntalk/esl-core`
  internal classification seams
- prepared bootstrap inbound pipelines now participate in the live ingress
  path for both startup and reconnect attempts on that runtime instance
- reconnect restore fails closed when a restore command receives server `-ERR`
  after re-authentication, so the runtime does not report a false healthy
  steady state
- live subscription/filter mutation commands now reject truthfully on server
  `-ERR` for `subscribe()`, `unsubscribe()`, `addFilter()`, and
  `removeFilter()`, without falsely updating local desired or observed state
- deterministic fake-server coverage now proves the reconnect-restore `-ERR`
  hostile path and the full live mutation `-ERR` matrix above
- public docs and contract wording are aligned with the implemented runtime
  truth for prepared ingress, reconnect restore behavior, mutation rejection
  semantics, `bgapi()` pre-auth/recovery rejection, health reporter methods,
  and health/status timestamp semantics
- repo-native formatting and aggregate release checks are green again

This patch does not introduce:

- new runtime features
- public API expansion
- reconnect policy redesign
- framework-specific integration behavior

## Downstream impact

Behavioral posture for downstream consumers:

- no intentional public API change in `esl-react`
- reconnect recovery is now more honest under restore failure: a restore-phase
  server `-ERR` keeps the runtime out of the healthy/live steady state and
  stays on the supervised recovery path
- live subscription/filter mutation failures on server `-ERR` now surface as
  truthful promise rejection without corrupting local desired/applied state
- higher layers using the prepared runner bootstrap seam now get the prepared
  inbound pipeline as the real live decode path for startup and reconnect on
  that runtime instance

## Validation status for this patch

The release surface has been verified with:

- `composer cs-fix`
- `composer cs-check`
- `composer check`

`composer check` currently covers the aggregate repo-native gate:

- formatting (`cs-check`)
- static analysis (`analyse`)
- PHPUnit (`test`)

## Suggested GitHub release text

- Remove the runtime ingress dependency on unsupported `apntalk/esl-core` internal seams and wire prepared inbound pipelines into the live startup/reconnect ingress path.
- Fail reconnect recovery closed when a restore command receives server `-ERR`, so the runtime does not report a false healthy/authenticated steady state.
- Harden live subscription and filter mutation failure handling so server `-ERR` rejects truthfully without corrupting desired or observed local state.
- Add deterministic fake-server regression coverage for reconnect restore failure and the full live subscription/filter mutation `-ERR` matrix.
- Sync public docs and release-facing wording with the tested runtime truth, and normalize the repo-native formatting/release gates.

## Tagging note

Latest released tag on this line is `v0.2.11`.

The next patch tag for this accumulated surface is `v0.2.12`.
