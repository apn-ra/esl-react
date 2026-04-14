# Reconnect model

## Overview

Reconnect is supervised by internal runtime components. When a live connection drops unexpectedly, or when a supervised TCP connect attempt fails, the runtime schedules retry attempts according to `RetryPolicy`.

Consumers configure retry behavior through `RetryPolicy` in `RuntimeConfig`. Consumers observe its effects through `ConnectionState`, `HealthSnapshot::$reconnectAttempts`, and the eventual resolution or rejection of the `connect()` promise.

---

## RetryPolicy

```php
$policy = \Apntalk\EslReact\Config\RetryPolicy::withMaxAttempts(10, 0.5);
```

| Property | Type | Description |
|---|---|---|
| `maxAttempts` | `int` | Maximum number of reconnect attempts. `0` means unlimited. |
| `initialDelaySeconds` | `float` | Delay before the first reconnect attempt, in seconds. |
| `backoffMultiplier` | `float` | Multiplier applied to the delay after each failed attempt. |
| `maxDelaySeconds` | `float` | Upper bound on the delay between attempts, in seconds. |

Delay for attempt N is: `min(initialDelaySeconds * (backoffMultiplier ^ (N-1)), maxDelaySeconds)`.

### Named constructors

```php
RetryPolicy::default()   // unlimited attempts, 1.0s initial, 2x multiplier, 60s max
RetryPolicy::disabled()  // No reconnect. Any disconnect is permanent.
```

Use `RetryPolicy::disabled()` in testing environments or in deployments where a supervisor process is responsible for restarting the worker.

---

## Reconnect trigger policy

Before scheduling a reconnect, the runtime classifies the failure:

| Disconnect reason | Classification | Default reconnect behavior |
|---|---|---|
| Clean `disconnect()` call | Intentional shutdown | No reconnect |
| TCP error / connect failure | Network error | Reconnect with backoff |
| Unexpected EOF | Network error | Reconnect with backoff |
| Auth reply `-ERR` | Auth failure | No reconnect |
| Handshake timeout | Handshake failure | No reconnect |
| Malformed or unexpected handshake traffic | Protocol failure | No reconnect |

Intentional shutdowns and non-retryable handshake/auth failures bypass the retry schedule entirely.

---

## Retry sequence

When a network error disconnect is classified:

1. `ConnectionState` transitions to `Reconnecting`.
2. The supervisor increments the attempt counter (`reconnectAttempts` in `HealthSnapshot`).
3. The scheduler computes the delay for this attempt using the backoff formula.
4. After the delay, `ConnectionState` transitions to `Connecting` and a new TCP connection is attempted.
5. If the connection succeeds and auth succeeds, the attempt counter resets to zero.
6. If the connection fails again or the reconnecting socket closes unexpectedly before recovery completes, the cycle repeats from step 2.
7. If `maxAttempts` is reached and the attempt fails, `ConnectionState` transitions to `Disconnected` and no further retries occur.

The `connect()` promise (if the caller is still awaiting it) does not reject during intermediate retries. It rejects only when `maxAttempts` is exhausted or a non-retryable failure occurs.

---

## Auth and handshake failure behavior

The current implementation does not retry:

- auth rejection
- connect/auth handshake timeout
- malformed or unexpected inbound handshake frames

These cases fail closed, transition the runtime to `Disconnected`, and reject the pending `connect()` promise when one exists.

---

## Inflight commands on disconnect

When the connection drops while commands are inflight:

- All pending `api` commands (sent and awaiting reply, or enqueued but not yet sent) are rejected with `ConnectionLostException` immediately.
- Their promises reject synchronously as part of the disconnect handling, before the reconnect cycle begins.
- Callers should handle `ConnectionLostException` and decide whether to reissue the command after reconnect.

There is no automatic retry for inflight commands. The caller is responsible for reissuing commands that should be retried.

---

## Pending bgapi jobs on disconnect and reconnect

`bgapi` job handling remains provisional relative to the implementation plan. The current reconnect pass does not expand its behavior beyond abandoning pending tracked jobs on connection loss.

---

## Subscriptions and filters on reconnect

Active subscriptions and filters are tracked by `SubscriptionManager` in memory. They survive the disconnect and reconnect cycle.

After `ConnectionState` reaches `Authenticated` following a reconnect:

1. The runtime re-authenticates the session.
2. It restores `event plain all` or the named desired event set.
3. It restores the desired filters.
4. Only after restore succeeds does the runtime transition back to `Authenticated` / `Active`.

If recovery is in progress, new `api()` calls and subscription/filter mutations fail closed instead of being queued for later replay.

---

## Supervisor lifecycle

Reconnect supervision starts when `connect()` is called and stops when:

- `disconnect()` is called
- auth rejection occurs
- a handshake timeout or malformed handshake failure occurs
- `maxAttempts` is exhausted

After supervision stops, `ConnectionState` is either `Disconnected` or `Closed`. No further reconnects occur for that runtime instance unless the caller creates or reconnects a new one explicitly.
