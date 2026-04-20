# Health model

## Overview

`HealthReporterInterface::snapshot()` returns a `HealthSnapshot` — a point-in-time read-only value object capturing the observable state of the runtime. Snapshots are cheap to produce and safe to read from any callback context.

```php
$snapshot = $client->health()->snapshot();
```

Higher-layer packages that start runtimes through `RuntimeRunnerInterface` may
prefer `RuntimeRunnerHandle::lifecycleSnapshot()`. That snapshot includes the
same `HealthSnapshot` truth plus runner startup state and prepared session
context.
For downstream health/reporting integration, `RuntimeRunnerHandle::feedbackSnapshot()`
packages the same health truth with prepared runtime identity in one stable
read model.
For downstream runtime-status export, `RuntimeRunnerHandle::statusSnapshot()`
packages that same health truth with a coarse status phase, reconnect posture,
and exact runtime-recorded connect/disconnect/failure timestamps.
That same runner feedback/status seam now also exposes bounded recovery truth,
active accepted-work identity, recent terminal-publication facts, and recent
lifecycle-semantic observations.
Opt-in live runner harnesses now validate the subset of those additive surfaces
that can be proven safely against a real FreeSWITCH target: startup/drain
recovery truth, reconnect generation/retry truth, one real accepted `bgapi`
operation, bounded recent terminal-publication export after real completion,
and lifecycle-semantic export only when a lab can safely emit one supported
semantic event.
That feedback surface also adds exact desired subscription/filter state and
exact retry-scheduling truth that are specific to the package-owned runner
integration seam.
Release-facing semantic shorthand for that feedback surface:

- exact: current runtime-owned truth or explicitly retained local scheduler state
- approximate: local wall-clock/event-loop packaging that may drift slightly
- derived: values computed from recorded timestamps at snapshot time
- policy-derived: runtime policy decisions such as explicit shutdown or retry exhaustion
- bounded runtime-known: only the failure categories the runtime can truly distinguish today

Status-snapshot shorthand:

- exportable: safe to serialize for downstream persistence/reporting
- runtime-recorded timestamp: captured by this runtime instance at the time the transition was observed
- optional disconnect reason: may be `null` on clean close paths where the runtime has no richer cause object

---

## HealthSnapshot fields

### connectionState

Type: `ConnectionState` (backed enum)

The current `ConnectionState` of the runtime. Reflects the TCP connection and authentication phase.

Values: `Disconnected`, `Connecting`, `Connected`, `Authenticating`, `Authenticated`, `Reconnecting`, `Draining`, `Closed`.

See [docs/runtime-lifecycle.md](runtime-lifecycle.md) for transition rules.

---

### sessionState

Type: `SessionState` (backed enum)

The current `SessionState` of the active session.

Values: `NotStarted`, `Authenticating`, `Active`, `Disconnected`, `Failed`.

When `ConnectionState` is `Authenticated`, `SessionState` will be `Active`. When disconnected, `SessionState` will be `Disconnected` or `Failed`.

---

### isLive

Type: `bool`

Whether the heartbeat monitor currently considers the runtime live.

Implemented meaning in the current slice:

- `true` when inbound traffic or a heartbeat probe response has kept the connection inside the configured liveness window
- `false` when the connection has gone idle long enough to degrade liveness, or when the runtime is disconnected/recovering

`false` does not immediately mean the socket has closed. During the first missed liveness window, `ConnectionState` may still be `Authenticated` while `isLive` is `false`. In the current model, one missed check degrades liveness and may trigger a probe; the second consecutive missed check closes the socket.

---

### inflightCommandCount

Type: `int`

The number of `api` commands currently awaiting a reply from FreeSWITCH. Because `api` commands are serial, this value is either 0 or 1 in normal operation. A value greater than 1 indicates commands are queued in the `AsyncCommandBus` waiting for the current inflight command to complete.

This is also the runtime-wide accepted-work value used when enforcing `BackpressureConfig::$maxInflightCommands`.

---

### pendingBgapiJobCount

Type: `int`

The number of accepted `bgapi` handles that have not yet reached a terminal outcome. This includes:

- handles still waiting for the bgapi acceptance reply
- accepted jobs waiting for `BACKGROUND_JOB`
- jobs that have survived an unexpected reconnect and are still pending

---

### totalInflightCount

Type: `int`

The runtime-wide accepted-work count used by overload and drain decisions.

Current definition:

- `inflightCommandCount`
- plus `pendingBgapiJobCount`

This is the number that `BackpressureConfig::$maxInflightCommands` is compared against.

---

### isOverloaded

Type: `bool`

Whether the runtime would currently reject new work for backpressure reasons.

When `true`, new `api()`, `bgapi()`, and live-session subscription/filter mutations are rejected with `BackpressureException`.

---

### activeSubscriptions

Type: `array<string>`

The list of event names currently subscribed, as tracked by `SubscriptionManager`. This reflects the in-memory desired state. After a reconnect, this list is the target set the runtime restores onto the new session.

Important nuance:

- this is desired event-name state only
- when the runtime is in `subscribeAll` mode, this list is intentionally empty
- for runner-facing integrations that need the stronger desired-state shape,
  `RuntimeRunnerHandle::feedbackSnapshot()->subscriptionState()` exposes
  `subscribeAll`, `eventNames`, and desired filters together
- for runner-facing integrations that also need conservative current-session
  applied truth, `RuntimeRunnerHandle::feedbackSnapshot()->observedSubscriptionState()`
  exposes what the runtime believes it has successfully applied on the current
  authenticated session after command replies complete
- `observedSubscriptionState()` is invalidated on reconnect/session loss and
  rebuilt only after the restore path completes on the new session
- this remains weaker than a deeper transport receipt ledger and should be
  treated as local runtime-applied truth, not independent server acknowledgement

---

### reconnectAttempts

Type: `int`

The number of reconnect attempts made since the last successful authenticated connection. Resets to zero when recovery succeeds. Incremented each time the supervisor starts a new retry attempt after an unexpected disconnect or transient connect failure.

For runner-facing integrations that also need to know whether a retry timer is
currently pending, `RuntimeRunnerHandle::feedbackSnapshot()->isReconnectRetryScheduled()`
exposes that exact supervisor truth.

For runner-facing integrations that need stronger reconnect/backoff detail,
`RuntimeRunnerHandle::feedbackSnapshot()->reconnectState()` packages:

- exact reconnect phase truth (`idle`, `waiting_to_retry`, `attempting_reconnect`, `restoring_session`, `exhausted`)
- exact scheduled or active attempt number while recovery is underway
- exact local scheduler truth for whether a retry timer is pending
- exact backoff delay for the scheduled or active reconnect attempt
- approximate local wall-clock next-due and remaining-delay values when a retry timer is pending

The wall-clock due time and remaining delay are local event-loop scheduler
packaging, not a hard real-time guarantee. They may drift slightly with loop
latency.

The same reconnect detail surface now also distinguishes terminal reconnect-stop
truth:

- `isTerminallyStopped` means the runtime has stopped autonomous reconnect
- `isRetryExhausted` is exact bounded-retry exhaustion truth
- `requiresExternalIntervention` means recovery now needs a new explicit caller
  action or runtime replacement
- `isFailClosedTerminalState` distinguishes fail-closed terminal outcomes from
  explicit shutdown
- `terminalStopReason` is a conservative runtime-known category only; it does
  not claim deeper transport diagnosis beyond what the runtime itself knows
- `terminalStoppedAtMicros` is the exact recorded runtime transition timestamp
  for terminal reconnect stop
- `lastRetryAttemptStartedAtMicros` is the exact recorded local timestamp for
  the most recent reconnect attempt start, when one occurred
- `lastScheduledRetryDueAtMicros` and `lastScheduledBackoffDelaySeconds` are
  the exact last retained scheduler values for retry timing context
- `lastScheduledRetryDueAtMicros` is exact local scheduler state, but still
  reflects the scheduled target rather than a hard real-time execution receipt
- `terminalStoppedDurationSeconds` is a derived local elapsed duration since
  terminal stop and may drift slightly with wall-clock/event-loop timing

For runner-facing integrations that also need bounded reconstruction/recovery
truth, `RuntimeRunnerHandle::feedbackSnapshot()->recovery` packages:

- current recovery generation identity
- retry/drain/reconstruction/replay-continuity posture
- prepared-context application state
- recoverable-after-reconnect vs recoverable-only-with-prepared-context vs terminal-non-recoverable status
- last bounded recovery and drain causes/outcomes known to the runtime

---

### isDraining

Type: `bool`

Whether the runtime is currently in drain mode. `true` when `ConnectionState` is `Draining`. In this state, new commands are rejected and the runtime is closing explicitly rather than recovering.
Accepted inflight work may still be present while this flag is true.
Once the runtime finishes closing and `ConnectionState` becomes `Closed`, this flag returns to `false`.

---

### lastErrorClass

Type: `?string`

The fully-qualified class name of the most recent exception encountered by the runtime, or `null` if no error has occurred. This is updated when connection failures, auth failures, handshake failures, command timeouts, or liveness-triggered disconnects are recorded.

---

### lastErrorMessage

Type: `?string`

The message of the most recent exception, or `null`. Accompanies `lastErrorClass`.

---

### snapshotAtMicros

Type: `float`

Unix timestamp in microseconds when this snapshot was taken. Use this to determine how fresh the snapshot is if it is being cached or relayed across a system boundary.

---

### lastHeartbeatAtMicros

Type: `?float`

Unix timestamp in microseconds when the most recent inbound activity was recorded by the heartbeat monitor, or `null` if no inbound frame has been seen since the current runtime started.

---

## Liveness versus connection state

`isLive` and `connectionState` are independent:

| connectionState | isLive | Meaning |
|---|---|---|
| `Authenticated` | `true` | Fully operational |
| `Authenticated` | `false` | Connected but liveness degraded; one probe may be in progress for the current idle episode |
| `Draining` | `true` or `false` | Explicit terminal shutdown path; new work is rejected |
| `Reconnecting` | `false` | Disconnected, waiting for the next retry attempt |
| `Connecting` | `false` | Retry timer has fired and a new socket attempt is underway |
| `Disconnected` | `false` | Not connected, no retry pending |

Consumers that need to gate work on runtime health should check both fields.

---

## Usage example

```php
$snapshot = $client->health()->snapshot();

if ($snapshot->connectionState !== \Apntalk\EslReact\Connection\ConnectionState::Authenticated) {
    // not ready
}

if (!$snapshot->isLive) {
    // heartbeat degraded
}

if ($snapshot->isDraining) {
    // shutting down
}

echo sprintf(
    "inflight=%d api=%d bgapi=%d overloaded=%s reconnects=%d",
    $snapshot->totalInflightCount,
    $snapshot->inflightCommandCount,
    $snapshot->pendingBgapiJobCount,
    $snapshot->isOverloaded ? 'yes' : 'no',
    $snapshot->reconnectAttempts,
);
```
