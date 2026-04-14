# Health model

## Overview

`HealthReporterInterface::snapshot()` returns a `HealthSnapshot` — a point-in-time read-only value object capturing the observable state of the runtime. Snapshots are cheap to produce and safe to read from any callback context.

```php
$snapshot = $client->health()->snapshot();
```

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

---

### reconnectAttempts

Type: `int`

The number of reconnect attempts made since the last successful authenticated connection. Resets to zero when recovery succeeds. Incremented each time the supervisor starts a new retry attempt after an unexpected disconnect or transient connect failure.

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

Type: `int`

Unix timestamp in microseconds when this snapshot was taken. Use this to determine how fresh the snapshot is if it is being cached or relayed across a system boundary.

---

### lastHeartbeatAtMicros

Type: `?int`

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
