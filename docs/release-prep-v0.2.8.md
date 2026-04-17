# Release Prep — v0.2.8 candidate

This note summarizes the accumulated runner-feedback and prepared-bootstrap
integration-hardening work since `v0.2.7`.

It is intended as a checkpoint-oriented release note and tag-prep aid for the
next release candidate on this line.

## Scope of this checkpoint

This checkpoint is about making the public runner-facing integration surface
more explicit and safer to consume.

Implemented in this candidate:

- explicit prepared-bootstrap replay capture injection
- bounded runtime/session identity propagation
- stable runner-facing `RuntimeFeedbackSnapshot`
- exact desired subscription/filter state
- conservative observed current-session subscription/filter state
- explicit reconnect retry scheduling truth
- reconnect/backoff timing detail
- explicit terminal reconnect-stop and retry-exhaustion truth
- retained terminal reconnect timing context
- release-facing docs and contract coverage for those surfaces

This checkpoint does not introduce:

- a metrics/export framework
- a diagnostics/history subsystem
- a reconnect policy redesign
- replay persistence or replay execution
- framework-specific health/reporting adapters

## Stable public runner-feedback surface

The release-facing stable surfaces for this checkpoint are:

- `RuntimeRunnerHandle::feedbackSnapshot()`
- `RuntimeFeedbackSnapshot`
- `RuntimeSubscriptionStateSnapshot`
- `RuntimeObservedSubscriptionStateSnapshot`
- `RuntimeReconnectStateSnapshot`
- `RuntimeReconnectPhase`
- `RuntimeReconnectStopReason`
- `PreparedRuntimeReplayCaptureInputInterface`
- `RuntimeFeedbackProviderInterface`

Consumers should type against these documented contracts/read models rather
than internal runtime implementation classes.

## Semantic categories

Use the runner feedback fields with these meanings:

- exact:
  - current runtime-owned truth
  - explicitly retained local scheduler state
- approximate:
  - local wall-clock/event-loop packaging that may drift slightly
- derived:
  - values computed at snapshot time from recorded timestamps
- policy-derived:
  - runtime policy decisions such as explicit shutdown or retry exhaustion
- bounded runtime-known:
  - only the failure categories the runtime can truly distinguish today

### Desired vs observed subscription truth

- `subscriptionState()` is exact in-memory desired subscription/filter intent
- `observedSubscriptionState()` is conservative current-session locally
  observed-applied truth after successful local command replies
- neither surface is a deeper server-side receipt ledger

### Reconnect transient vs terminal truth

- `reconnectState()` packages both transient reconnect timing and terminal stop
  state in one stable read model
- `nextRetryDueAtMicros` and `remainingDelaySeconds` are approximate local
  scheduler packaging
- `terminalStoppedAtMicros`, `lastRetryAttemptStartedAtMicros`, and
  `lastScheduledBackoffDelaySeconds` are exact recorded or retained local
  values
- `terminalStoppedDurationSeconds` is derived local elapsed time
- `terminalStopReason` is a conservative runtime-known or policy-derived
  category, not a transport diagnostics taxonomy

## Validation status for this checkpoint

The release-facing surface is covered by:

- public API contract tests
- deterministic runner integration tests
- deterministic replay-hooks integration tests
- static analysis via PHPStan
- full non-live PHPUnit coverage

Validation commands expected for release-prep:

```bash
vendor/bin/phpunit --no-coverage tests/Contract/PublicApiContractTest.php tests/Integration/RuntimeRunnerIntegrationTest.php
vendor/bin/phpunit --no-coverage tests/Integration/ReplayHooksIntegrationTest.php
vendor/bin/phpstan analyse
ESL_REACT_LIVE_TEST=0 vendor/bin/phpunit --no-coverage tests/Integration
ESL_REACT_LIVE_TEST=0 vendor/bin/phpunit --no-coverage
composer validate --strict
git diff --check
```

Live harnesses remain opt-in and environment-dependent. They are not part of
the mandatory release-prep gate for this checkpoint.

## Explicit non-goals / deferred items

Still deferred after this checkpoint:

- deeper transport/server root-cause diagnostics
- cross-process reconnect history retention
- remote receipt ledger for subscription application
- replay persistence, replay execution, or restart recovery
- framework-owned health presentation/control-plane policy

## Tagging note

Latest released tag on this line is `v0.2.7`.

The next checkpoint candidate for this accumulated surface is therefore
`v0.2.8`, assuming no additional feature work is merged before tag prep.
