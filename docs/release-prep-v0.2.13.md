# Release Prep — v0.2.13

This note summarizes the current patch-release surface after the runtime
ingress boundary fix, reconnect/mutation hostile-path hardening, contract/doc
truth sync, release-gate normalization work, and the additive runner recovery/
runtime-truth surface uplift that landed after `v0.2.11`, plus the bounded
startup recovery-truth correction and the follow-up live runner bgapi/event
proof hardening that closed the next release-facing live confidence gap.

It is intended as a concise tag-prep aid and release-note source for the next
patch release after `v0.2.12`.

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
- runner feedback/status now also expose bounded queue/retry/drain/recovery
  truth, active accepted-work identity, recent terminal-publication facts,
  and recent lifecycle-semantic observations using `apntalk/esl-core`
  vocabulary
- first authenticated startup no longer reports reconnect-recoverable truth or
  a recovered-after-reconnect outcome before any reconnect path has actually
  begun
- prepared bootstrap can now carry bounded prepared recovery context for
  recovery-generation/reconstruction truth without moving durable replay or
  orchestration responsibilities into `esl-react`
- public docs and contract wording are aligned with the implemented runtime
  truth for prepared ingress, reconnect restore behavior, mutation rejection
  semantics, `bgapi()` pre-auth/recovery rejection, health reporter methods,
  health/status timestamp semantics, and the new bounded recovery-truth
  surfaces
- the opt-in live runner lifecycle harness now passes against the real
  FreeSWITCH lab target for the corrected startup/drain truth surface
- the opt-in live runner bgapi/event harness now passes against the real
  FreeSWITCH lab target for event delivery, accepted-work tracking, and
  bounded terminal-publication export; its default example now uses a short
  safe `msleep` window so the pending accepted-work proof is observable
- repo-native formatting and aggregate release checks are green again

This patch does not introduce:

- new runtime features
- public API break
- reconnect policy redesign
- framework-specific integration behavior

## Downstream impact

Behavioral posture for downstream consumers:

- no intentional breaking public API change in `esl-react`
- additive runner feedback/status read-model expansion now gives downstreams
  stable bounded recovery/reconstruction/export truth without granting them
  runtime ownership
- first authenticated startup is now described and reported truthfully: it is
  a live startup state, not reconnect recovery
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

- `ESL_REACT_LIVE_RUNNER_TEST=1 vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerLifecycleCompatibilityTest.php`
- `ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TEST=1 vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerBgapiEventCompatibilityTest.php`
- `composer cs-fix`
- `composer cs-check`
- `composer check`

`composer check` currently covers the aggregate repo-native gate:

- formatting (`cs-check`)
- static analysis (`analyse`)
- PHPUnit (`test`)

The two opt-in live runner proofs above are not part of the mandatory default
CI gate, but they have both been exercised successfully against the current
FreeSWITCH lab target for this patch line.

## Suggested GitHub release text

- Remove the runtime ingress dependency on unsupported `apntalk/esl-core` internal seams and wire prepared inbound pipelines into the live startup/reconnect ingress path.
- Fail reconnect recovery closed when a restore command receives server `-ERR`, so the runtime does not report a false healthy/authenticated steady state.
- Harden live subscription and filter mutation failure handling so server `-ERR` rejects truthfully without corrupting desired or observed local state.
- Add deterministic fake-server regression coverage for reconnect restore failure and the full live subscription/filter mutation `-ERR` matrix.
- Correct startup recovery truth so first authenticated startup no longer reports reconnect recovery before any reconnect path has begun.
- Prove the public runner startup lifecycle seam and the public runner bgapi/event seam against the real FreeSWITCH lab target, including accepted-work and terminal-publication export on the bgapi path.
- Sync public docs, live examples, and release-facing wording with the tested runtime truth, and normalize the repo-native formatting/release gates.

## Tagging note

Latest released tag on this line is `v0.2.12`.

The next patch tag for this accumulated surface is `v0.2.13`.
