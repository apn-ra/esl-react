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

Whether the heartbeat monitor considers the connection alive. `true` when the last heartbeat was acknowledged within the configured timeout period.

`false` does not immediately mean the connection is lost — it means the heartbeat has not been acknowledged recently. This can indicate a congested or degraded FreeSWITCH instance. `ConnectionState` may still be `Authenticated` when `isLive` is `false`.

When heartbeat monitoring is disabled (`HeartbeatConfig::$enabled = false`), this field is always `true`.

---

### inflightCommandCount

Type: `int`

The number of `api` commands currently awaiting a reply from FreeSWITCH. Because `api` commands are serial, this value is either 0 or 1 in normal operation. A value greater than 1 indicates commands are queued in the `AsyncCommandBus` waiting for the current inflight command to complete.

This is also the value tracked by `BackpressureController` when enforcing `BackpressureConfig::$maxInflightCommands`.

---

### pendingBgapiJobCount

Type: `int`

The number of `bgapi` jobs that have been dispatched and are awaiting their `BACKGROUND_JOB` completion event. This count includes jobs that have survived a reconnect and are still waiting for their completion.

---

### activeSubscriptions

Type: `array<string>`

The list of event names currently subscribed, as tracked by `SubscriptionManager`. This reflects what is recorded in memory, not necessarily what has been confirmed by FreeSWITCH in the current connection. After a reconnect, this list represents the target subscription set that `ResubscriptionPlanner` will restore.

---

### reconnectAttempts

Type: `int`

The number of reconnect attempts made since the last successful connection. Resets to zero when `ConnectionState` transitions to `Authenticated` successfully. Incremented each time the supervisor starts a new connection attempt after a disconnect.

---

### isDraining

Type: `bool`

Whether the runtime is currently in drain mode. `true` when `ConnectionState` is `Draining`. In this state, new commands are rejected with `DrainException`, and the runtime is waiting for inflight operations to complete before closing.

---

### lastErrorClass

Type: `?string`

The fully-qualified class name of the most recent exception encountered by the runtime, or `null` if no error has occurred. This is updated when connection failures, auth failures, command timeouts, or listener exceptions are recorded.

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

Unix timestamp in microseconds when the last heartbeat acknowledgment was received, or `null` if no heartbeat has been received since the runtime started. When `HeartbeatConfig::$enabled` is `false`, this field is always `null`.

---

## Liveness versus connection state

`isLive` and `connectionState` are independent:

| connectionState | isLive | Meaning |
|---|---|---|
| `Authenticated` | `true` | Fully operational |
| `Authenticated` | `false` | Connected but heartbeat degraded; FreeSWITCH may be under load |
| `Reconnecting` | `false` | Disconnected, retrying |
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
    "inflight=%d pending_bgapi=%d reconnects=%d",
    $snapshot->inflightCommandCount,
    $snapshot->pendingBgapiJobCount,
    $snapshot->reconnectAttempts,
);
```
