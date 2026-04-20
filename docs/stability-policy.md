# Stability policy

## Pre-1.0 policy

`apntalk/esl-react` is currently pre-1.0. The public API is not frozen. Breaking changes to the internal implementation may occur in any release.

However, stability is maintained for the documented stable public surface within a minor version. Consumers who import only the types listed in this document should experience no breaking changes between patch releases, and should receive advance notice of breaking changes between minor releases.

---

## Stable public surface

The following types are considered stable for pre-1.0:

### Contracts

- `Apntalk\EslReact\Contracts\AsyncEslClientInterface`
- `Apntalk\EslReact\Contracts\EventStreamInterface`
- `Apntalk\EslReact\Contracts\SubscriptionManagerInterface`
- `Apntalk\EslReact\Contracts\HealthReporterInterface`
- `Apntalk\EslReact\Contracts\RuntimeRunnerInterface`
- `Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface`
- `Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface`
- `Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface`
- `Apntalk\EslReact\Contracts\PreparedRuntimeRecoveryInputInterface`
- `Apntalk\EslReact\Contracts\PreparedRuntimeReplayCaptureInputInterface`
- `Apntalk\EslReact\Contracts\RuntimeFeedbackProviderInterface`
- `Apntalk\EslReact\Contracts\RuntimeStatusProviderInterface`

### Entry point

- `Apntalk\EslReact\AsyncEslRuntime::make(RuntimeConfig $config, ?LoopInterface $loop = null): AsyncEslClientInterface`
- `Apntalk\EslReact\AsyncEslRuntime::runner(): RuntimeRunnerInterface`

### Config objects

- `Apntalk\EslReact\Config\RuntimeConfig`
- `Apntalk\EslReact\Config\RetryPolicy`
- `Apntalk\EslReact\Config\HeartbeatConfig`
- `Apntalk\EslReact\Config\BackpressureConfig`
- `Apntalk\EslReact\Config\SubscriptionConfig`
- `Apntalk\EslReact\Config\CommandTimeoutConfig`

### Read models and DTOs

- `Apntalk\EslReact\Health\HealthSnapshot`
- `Apntalk\EslReact\Connection\ConnectionState`
- `Apntalk\EslReact\Session\SessionState`
- `Apntalk\EslReact\Bgapi\BgapiJobHandle`
- `Apntalk\EslReact\Runner\PreparedRuntimeInput`
- `Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput`
- `Apntalk\EslReact\Runner\PreparedRuntimeRecoveryContext`
- `Apntalk\EslReact\Runner\RuntimeRunnerHandle`
- `Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot`
- `Apntalk\EslReact\Runner\RuntimeFeedbackSnapshot`
- `Apntalk\EslReact\Runner\RuntimeStatusSnapshot`
- `Apntalk\EslReact\Runner\RuntimeRecoverySnapshot`
- `Apntalk\EslReact\Runner\RuntimeOperationSnapshot`
- `Apntalk\EslReact\Runner\RuntimeTerminalPublicationSnapshot`
- `Apntalk\EslReact\Runner\RuntimeLifecycleSemanticSnapshot`
- `Apntalk\EslReact\Runner\RuntimeSubscriptionStateSnapshot`
- `Apntalk\EslReact\Runner\RuntimeObservedSubscriptionStateSnapshot`
- `Apntalk\EslReact\Runner\RuntimeReconnectStateSnapshot`
- `Apntalk\EslReact\Runner\RuntimeReconnectPhase`
- `Apntalk\EslReact\Runner\RuntimeReconnectStopReason`
- `Apntalk\EslReact\Runner\RuntimeRunnerState`
- `Apntalk\EslReact\Runner\RuntimeSessionContext`
- `Apntalk\EslReact\Runner\RuntimeStatusPhase`

### Exceptions

- `Apntalk\EslReact\Exceptions\EslRuntimeException`
- `Apntalk\EslReact\Exceptions\ConnectionException`
- `Apntalk\EslReact\Exceptions\AuthenticationException`
- `Apntalk\EslReact\Exceptions\CommandTimeoutException`
- `Apntalk\EslReact\Exceptions\BackpressureException`
- `Apntalk\EslReact\Exceptions\ConnectionLostException`
- `Apntalk\EslReact\Exceptions\DrainException`

### Documented behavior

The following behaviors are considered stable even when the implementing types are internal:

- Event delivery ordering guarantee (socket order, synchronous sequential dispatch)
- Listener exception handling (caught, not re-thrown, passed to error handler)
- Reconnect behavior as documented in [reconnect-model.md](reconnect-model.md)
- Bgapi job survival across reconnect
- Subscription restore after reconnect
- `ConnectionLostException` on inflight api commands during unexpected transport loss
- Fail-closed `api()` timeout behavior: timeout rejects the command, treats the current reply slot as ambiguous, and closes the compromised connection before later command flow continues
- `DrainException` on accepted inflight work that is terminated by explicit bounded drain
- Promise-rejection semantics for normal subscription/filter mutation gating failures on the `SubscriptionManagerInterface` promise-returning methods
- `RuntimeRunnerHandle::onLifecycleChange()` immediate current-snapshot delivery and synchronous ordered callback semantics
- `RuntimeRunnerHandle::feedbackSnapshot()` as a stable packaging of existing health truth plus prepared runtime identity
- `RuntimeRunnerHandle::statusSnapshot()` as a stable export packaging of runtime-owned lifecycle/reconnect truth plus recent connect/disconnect/failure observations
- `RuntimeRunnerHandle::feedbackSnapshot()->recovery`, `activeOperations`, `recentTerminalPublications`, and `recentLifecycleSemantics` as stable bounded runtime-truth packaging for downstream export/persistence
- `RuntimeFeedbackSnapshot::subscriptionState()` as exact desired subscription/filter state for the current runtime instance
- `RuntimeFeedbackSnapshot::observedSubscriptionState()` as conservative locally observed-applied subscription/filter state for the current authenticated session, with explicit invalidation and rebuild semantics across reconnect
- `RuntimeFeedbackSnapshot::reconnectState()` as stable reconnect/backoff detail packaging exact runtime/scheduler truth plus approximate local due/remaining timing
- `RuntimeReconnectStateSnapshot::$isTerminallyStopped`, `$isRetryExhausted`, `$requiresExternalIntervention`, `$isFailClosedTerminalState`, `$terminalStopReason`, `$terminalStoppedAtMicros`, `$lastRetryAttemptStartedAtMicros`, `$lastScheduledRetryDueAtMicros`, `$lastScheduledBackoffDelaySeconds`, and `$terminalStoppedDurationSeconds` as stable additive reconnect terminal-state/timing truth on top of the reconnect detail surface
- `RuntimeFeedbackSnapshot::isReconnectRetryScheduled()` as exact supervisor truth for whether a reconnect retry timer is pending
- `RuntimeStatusSnapshot::$phase`, `$isRuntimeActive`, `$isRecoveryInProgress`, `$lastSuccessfulConnectAtMicros`, `$lastDisconnectAtMicros`, `$lastDisconnectReasonClass`, `$lastDisconnectReasonMessage`, `$lastFailureAtMicros`, `$lastFailureClass`, `$lastFailureMessage`, `toArray()`, and `jsonSerialize()` as stable export fields/helpers for downstream status feeds
- Live package-owned validation for runner startup and explicit drain-to-stop observation, plus live-verified opt-in automated reconnect recovery validation when safe disruption/restore commands are supplied by the environment
- Deterministic and opt-in live runner-surface validation for event subscription plus `bgapi()` completion activity against real FreeSWITCH event and `BACKGROUND_JOB` traffic
- Deterministic runner-surface validation for combined pending `bgapi()` plus event-subscription behavior during degraded liveness and reconnecting runtime states
- Opt-in live runner-surface validation for the reconnect + bgapi/event combined path when safe lab disrupt/restore commands are available; broader live fault injection beyond one safe pending-`bgapi()` reconnect path remains deferred
- Opt-in live runner-surface validation for one genuinely pending `bgapi()` handle crossing a reconnect boundary through a controlled unexpected transport close, with optional support for an external non-process-killing reconnect fault such as `reload mod_event_socket`
- Prepared-bootstrap replay injection through `PreparedRuntimeReplayCaptureInputInterface`, reusing the stable `ReplayCaptureSinkInterface` contract from `apntalk/esl-core`
- Deterministic runner-surface validation for heartbeat/liveness degradation and recovery, with optional live lab validation on quiet targets
- Deterministic runner-surface validation for the second-miss heartbeat dead/reconnect path, with optional live lab validation when the target can be made silent without immediate transport loss

---

## Internal types

Everything not listed above is internal. Internal types may:

- be renamed
- be moved to a different namespace
- have constructors or method signatures changed
- be split into multiple classes
- be merged or removed

Do not import internal classes. Do not rely on internal constructor signatures. Do not rely on internal method behavior beyond what is observable through public contracts.

---

## Replay hook contract

Replay hooks remain an internal implementation path, but the documented replay artifact contract in [replay-hooks.md](replay-hooks.md) is now treated as stable for the currently supported runtime-owned capture points.

Stable artifact names:

- `api.dispatch`
- `api.reply`
- `bgapi.dispatch`
- `bgapi.ack`
- `bgapi.complete`
- `command.reply`
- `event.raw`
- `subscription.mutate`
- `filter.mutate`

Future additions should be additive. Existing artifact names should not be renamed without a documented breaking change.

---

## Version change policy

### Patch releases (0.x.Y)

- Bug fixes to stable behavior
- Documentation corrections
- Internal implementation changes that do not affect stable public behavior
- No breaking changes to stable surface

### Minor releases (0.X.0)

- May add new methods to stable interfaces (additive)
- May add new config properties with documented defaults (additive)
- May add new exception classes
- May add new stable types
- May add replay-hook artifact types or metadata fields additively
- Should not break existing usage of the stable surface
- Will include a changelog entry for all stable surface changes

### Major release (1.0.0)

At 1.0.0, the stable surface is frozen under semantic versioning:

- Stable contracts will not gain breaking changes in any 1.x release
- New additions will be backward-compatible
- Deprecation notices will precede removal by at least one minor version

---

## 1.0 target criteria

The package will reach 1.0 when:

- The public API is frozen and considered production-ready
- Reconnect behavior is stable and regression-tested
- Event dispatch ordering guarantees are reliable and tested
- `bgapi` dispatch and completion tracking is stable
- The health model is stable and complete
- The replay hook artifact contract is stable or replay hooks are explicitly removed from scope
- `FakeEslServer` coverage is strong enough to protect against lifecycle regressions in CI without a live PBX
- All docs accurately reflect implemented behavior

---

## Consumer guidance

Write your consuming code against the contracts, not against the implementation.

```php
// Correct: typed against the contract
function runWorker(\Apntalk\EslReact\Contracts\AsyncEslClientInterface $client): void {}

// Incorrect: typed against the internal runtime class
function runWorker(\Apntalk\EslReact\Runtime\AsyncEslRuntime $client): void {}
```

If you find yourself needing to import an internal class, that is a signal either that the stable surface is missing something (open an issue) or that the consumer logic belongs in a higher layer such as `laravel-freeswitch-esl`.
