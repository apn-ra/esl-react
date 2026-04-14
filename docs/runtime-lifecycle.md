# Runtime lifecycle

Status note: the connect/auth state transitions documented here are implemented in this pass. Reconnecting and full drain completion are still planned and are not yet exercised by the test suite.

Hardening note for the implemented slice:

- If `disconnect()` is called while `connect()` is still pending, the pending connect promise is rejected and the runtime moves to `Closed`/`Disconnected`.
- If the connect/auth handshake does not complete before the current handshake timeout budget, `connect()` rejects with `CommandTimeoutException`, the runtime returns to `Disconnected`, and `SessionState` becomes `Failed`.
- Unexpected or malformed inbound frames during the handshake fail closed and reject `connect()`.

## State machines

The runtime maintains two parallel state machines: `ConnectionState` and `SessionState`. They are related but distinct. A connection can be `Authenticated` (ConnectionState) while the session is `Active` (SessionState) — both must be healthy for the runtime to be fully operational.

---

## ConnectionState

`ConnectionState` is a backed enum with the following values:

| State | Meaning |
|---|---|
| `Disconnected` | No active connection. Initial state before `connect()` is called, and the state entered after a clean disconnect or after all reconnect attempts are exhausted. |
| `Connecting` | TCP connection is being established. |
| `Connected` | TCP connection is established; authentication has not yet started or completed. |
| `Authenticating` | The ESL auth challenge has been received and credentials are being sent. |
| `Authenticated` | Authentication succeeded. The runtime is ready to send commands and receive events. |
| `Reconnecting` | The connection was lost and the supervisor is executing a retry attempt. |
| `Draining` | The runtime has been asked to shut down. New commands are rejected. The runtime is waiting for inflight operations to complete. |
| `Closed` | The runtime has been permanently stopped. No further reconnects will occur. |

### ConnectionState transitions

```
Disconnected
  -> Connecting            on connect() called or reconnect attempt started

Connecting
  -> Connected             on TCP connection established
  -> Disconnected          on TCP connection failure (no retry configured)
  -> Reconnecting          on TCP connection failure (retry configured)

Connected
  -> Authenticating        on auth-request message received from FreeSWITCH

Authenticating
  -> Authenticated         on auth reply +OK
  -> Disconnected          on auth reply -ERR (no retry or retry disabled for auth failure)
  -> Disconnected          on handshake timeout or malformed/unexpected inbound handshake frame
  -> Reconnecting          on auth reply -ERR (retry enabled for auth failure)

Authenticated
  -> Reconnecting          on socket error or unexpected disconnect
  -> Draining              on drain() called

Reconnecting
  -> Connecting            on next retry attempt scheduled
  -> Disconnected          on maxAttempts exhausted

Draining
  -> Closed                on all inflight commands resolved and disconnect complete

Closed
  (terminal — no transitions out)
```

---

## SessionState

`SessionState` tracks the ESL session within an established connection.

| State | Meaning |
|---|---|
| `NotStarted` | No session has been started on this connection. |
| `Authenticating` | Waiting for the auth reply from FreeSWITCH. |
| `Active` | Authentication succeeded and the session is operational. |
| `Disconnected` | The connection was dropped; the session ended normally. |
| `Failed` | The session ended due to an auth failure or protocol error. |

### SessionState transitions

```
NotStarted
  -> Authenticating        on auth challenge received

Authenticating
  -> Active                on auth reply +OK
  -> Failed                on auth reply -ERR

Active
  -> Disconnected          on socket close (expected or network-level)
  -> Failed                on unexpected protocol error

Disconnected / Failed
  (per-session terminal states — a new session starts on reconnect)
```

Each reconnect cycle creates a new session. The `SessionState` of the prior session is not carried forward. `ConnectionState` transitions to `Authenticating` and a new `SessionState` begins at `NotStarted` → `Authenticating`.

---

## What triggers each transition

| Trigger | ConnectionState effect | SessionState effect |
|---|---|---|
| `connect()` called | `Disconnected` → `Connecting` | — |
| TCP established | `Connecting` → `Connected` | — |
| Auth-request received | `Connected` → `Authenticating` | `NotStarted` → `Authenticating` |
| Auth reply +OK | `Authenticating` → `Authenticated` | `Authenticating` → `Active` |
| Auth reply -ERR | `Authenticating` → `Reconnecting` or `Disconnected` | `Authenticating` → `Failed` |
| Socket error or close while Authenticated | `Authenticated` → `Reconnecting` | `Active` → `Disconnected` |
| Retry attempt fired | `Reconnecting` → `Connecting` | — |
| Max retries exhausted | `Reconnecting` → `Disconnected` | — |
| `drain()` called | `Authenticated` → `Draining` | — |
| Drain complete | `Draining` → `Closed` | `Active` → `Disconnected` |

---

## What happens on disconnect

In the currently implemented slice, when a connection drops (socket close, network error, unexpected EOF, or user-triggered close with inflight work):

1. `ConnectionState` transitions to `Reconnecting` (if retry is configured) or `Disconnected`.
2. `SessionState` transitions to `Disconnected`.
3. All pending `api` commands that have been sent but have not received a reply are rejected with `ConnectionLostException`.
4. Commands that are enqueued but not yet sent are also rejected with `ConnectionLostException`.
5. Pending `bgapi` jobs that have received their acknowledgment but not their `BACKGROUND_JOB` completion event are **not** rejected. They remain tracked and their promises will resolve if the completion event arrives after reconnect. See [bgapi-tracking.md](bgapi-tracking.md) for configurable timeout behavior.
6. Active subscriptions and filters are preserved in `SubscriptionManager` memory so they can be replayed on reconnect.

---

## What triggers reconnect

Reconnect is triggered by any transition to `Reconnecting`. This can be caused by:

- Socket error (network drop, TCP reset, FreeSWITCH restart)
- Unexpected EOF on the socket while in `Authenticated` state
- Auth failure (if `retryPolicy.retryOnAuthFailure` is enabled)

Reconnect is NOT triggered by:

- Calling `disconnect()` (clean disconnect, transitions to `Disconnected` then `Closed`)
- Calling `drain()` (draining shutdown, transitions to `Draining` then `Closed`)
- Receiving an `exit` reply to an explicit `exit` api command (classified as expected disconnect)

---

## Auth failure behavior

If FreeSWITCH returns `-ERR invalid` on the auth challenge:

1. `SessionState` transitions to `Failed`.
2. `ConnectionSupervisor` checks whether `RetryPolicy::retryOnAuthFailure` is enabled.
3. If enabled: `ConnectionState` transitions to `Reconnecting` and the retry schedule resumes.
4. If disabled (default): `ConnectionState` transitions to `Disconnected`. No further reconnect occurs. The `connect()` promise rejects with `AuthenticationException`.

The default behavior is to NOT retry on auth failure, because a bad password will fail every attempt and retrying wastes resources. Enable `retryOnAuthFailure` only in deployments where credentials may be temporarily unavailable during startup.

---

## Resubscription after reconnect

After `ConnectionState` reaches `Authenticated` following a reconnect:

1. `ResubscriptionPlanner` reads the active subscription set from `SubscriptionManager`.
2. It issues the appropriate `event` commands to restore named subscriptions or `event all` if `subscribeAll` was active.
3. It issues `filter` commands to restore any active filters.
4. Only after resubscription is complete does the runtime begin delivering events to listeners.

This ensures that consumers do not miss event types after reconnect due to a gap in subscriptions.
