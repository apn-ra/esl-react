# Reconnect model

## Overview

Reconnect is supervised by `ConnectionSupervisor`. When a connection is lost, the supervisor classifies the reason, determines whether a retry is appropriate, and schedules retry attempts according to `RetryPolicy`.

The supervisor is an internal component. Consumers configure its behavior through `RetryPolicy` in `RuntimeConfig`. Consumers observe its effects through `ConnectionState`, `HealthSnapshot::$reconnectAttempts`, and the eventual resolution or rejection of the `connect()` promise.

---

## RetryPolicy

```php
$policy = new \Apntalk\EslReact\Config\RetryPolicy(
    maxAttempts: 10,
    initialDelayMs: 500,
    backoffMultiplier: 2.0,
    maxDelayMs: 30_000,
);
```

| Property | Type | Description |
|---|---|---|
| `maxAttempts` | `int` | Maximum number of reconnect attempts. `0` means unlimited. |
| `initialDelayMs` | `int` | Delay before the first reconnect attempt, in milliseconds. |
| `backoffMultiplier` | `float` | Multiplier applied to the delay after each failed attempt. |
| `maxDelayMs` | `int` | Upper bound on the delay between attempts, in milliseconds. |

Delay for attempt N is: `min(initialDelayMs * (backoffMultiplier ^ (N-1)), maxDelayMs)`.

### Named constructors

```php
RetryPolicy::default()   // 10 attempts, 500ms initial, 2x multiplier, 30s max
RetryPolicy::disabled()  // No reconnect. Any disconnect is permanent.
```

Use `RetryPolicy::disabled()` in testing environments or in deployments where a supervisor process is responsible for restarting the worker.

---

## Disconnect classification

Before scheduling a reconnect, `DisconnectClassifier` examines the reason for the disconnect:

| Disconnect reason | Classification | Default reconnect behavior |
|---|---|---|
| `exit` command reply received | Expected disconnect | No reconnect |
| Clean `disconnect()` or `drain()` call | Intentional shutdown | No reconnect |
| TCP error (ECONNREFUSED, ECONNRESET, timeout) | Network error | Reconnect with backoff |
| Unexpected EOF | Network error | Reconnect with backoff |
| FreeSWITCH process restart detected | Network error | Reconnect with backoff |
| Auth reply `-ERR` | Auth failure | Configurable (default: no reconnect) |

Expected and intentional disconnects bypass the retry schedule entirely. The `ConnectionState` transitions to `Disconnected` (not `Reconnecting`) and no retry timer is set.

---

## Retry sequence

When a network error disconnect is classified:

1. `ConnectionState` transitions to `Reconnecting`.
2. The supervisor increments the attempt counter (`reconnectAttempts` in `HealthSnapshot`).
3. The scheduler computes the delay for this attempt using the backoff formula.
4. After the delay, `ConnectionState` transitions to `Connecting` and a new TCP connection is attempted.
5. If the connection succeeds and auth succeeds, the attempt counter resets to zero.
6. If the connection or auth fails again, the cycle repeats from step 2.
7. If `maxAttempts` is reached and the attempt fails, `ConnectionState` transitions to `Disconnected` and no further retries occur.

The `connect()` promise (if the caller is still awaiting it) does not reject during intermediate retries. It rejects only when `maxAttempts` is exhausted or a non-retryable failure occurs.

---

## Auth failure behavior

Auth failures can be caused by a wrong password, a FreeSWITCH configuration change, or a race condition during startup.

By default (`retryOnAuthFailure: false`):

- The supervisor does not retry.
- `ConnectionState` transitions to `Disconnected`.
- The `connect()` promise rejects with `AuthenticationException`.

When `retryOnAuthFailure: true`:

- The supervisor treats auth failure like a network error and schedules a retry.
- This is appropriate when credentials may become valid after a brief delay (e.g., startup sequencing).
- Note that if the password is simply wrong, unlimited retries will loop indefinitely unless `maxAttempts` is set.

---

## Inflight commands on disconnect

When the connection drops while commands are inflight:

- All pending `api` commands (sent and awaiting reply, or enqueued but not yet sent) are rejected with `ConnectionLostException` immediately.
- Their promises reject synchronously as part of the disconnect handling, before the reconnect cycle begins.
- Callers should handle `ConnectionLostException` and decide whether to reissue the command after reconnect.

There is no automatic retry for inflight commands. The caller is responsible for reissuing commands that should be retried.

---

## Pending bgapi jobs on disconnect and reconnect

`bgapi` job promises behave differently from `api` on disconnect:

- Pending bgapi jobs that have received their FreeSWITCH acknowledgment are NOT rejected on disconnect.
- Their promises remain open across the reconnect cycle.
- When FreeSWITCH reconnects and resumes processing, it may emit a `BACKGROUND_JOB` completion event with the original Job-UUID.
- `BgapiCompletionMatcher` matches that event to the still-open handle and resolves its promise.

This behavior is possible because FreeSWITCH assigns Job-UUIDs before processing begins, and may complete jobs even if the ESL connection drops and is reestablished.

If the completion event never arrives (job was lost on the FreeSWITCH side), the bgapi job will eventually time out and its promise will reject with `CommandTimeoutException`. The timeout is configured via `CommandTimeoutConfig::$bgapiCompletionTimeoutMs`.

---

## Subscriptions and filters on reconnect

Active subscriptions and filters are tracked by `SubscriptionManager` in memory. They survive the disconnect and reconnect cycle.

After `ConnectionState` reaches `Authenticated` following a reconnect:

1. `ResubscriptionPlanner` reads the current subscription set.
2. It issues the required `event` commands to restore named subscriptions.
3. It issues any required `filter` commands to restore active filters.
4. Event delivery to listeners resumes only after resubscription is confirmed.

This prevents a window after reconnect where event types are not yet subscribed.

If a subscription command fails during resubscription, the error is surfaced via the listener error handler but the runtime remains running. The failed subscription may be missing until the next reconnect or until the consumer manually calls `subscribe()` again.

---

## Supervisor lifecycle

The `ConnectionSupervisor` starts when `connect()` is called and stops when:

- `disconnect()` is called
- `drain()` is called and the drain completes
- `maxAttempts` is exhausted

After the supervisor stops, `ConnectionState` is either `Disconnected` or `Closed`. No further reconnects will occur without creating a new runtime instance.
