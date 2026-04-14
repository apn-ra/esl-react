# Runtime lifecycle

Status note: the connect/auth state transitions, unexpected-disconnect reconnect flow, and desired subscription/filter restoration documented here are implemented in this pass. Full drain completion, replay hooks, and broader heartbeat orchestration are still planned.

Hardening note for the implemented slice:

- If `disconnect()` is called while `connect()` is still pending, the pending connect promise is rejected and the runtime moves to `Closed`/`Disconnected`.
- If the connect/auth handshake does not complete before the current handshake timeout budget, `connect()` rejects with `CommandTimeoutException`, the runtime returns to `Disconnected`, and `SessionState` becomes `Failed`.
- Unexpected or malformed inbound frames during the handshake fail closed and reject `connect()`.
- After authentication succeeds, inbound event frames are delivered immediately on the live socket.
- After an unexpected disconnect and successful re-authentication, the runtime restores the in-memory desired subscription baseline first and then restores filters before transitioning back to `Authenticated`/`Active`.

## State machines

The runtime maintains two parallel state machines: `ConnectionState` and `SessionState`. They are related but distinct. A connection can be `Authenticated` (ConnectionState) while the session is `Active` (SessionState) — both must be healthy for the runtime to be fully operational.

---

## ConnectionState

`ConnectionState` is a backed enum with the following values:

| State | Meaning |
|---|---|
| `Disconnected` | No active connection. Initial state before `connect()` is called, and the state entered after reconnect attempts are exhausted or a non-retryable failure occurs. |
| `Connecting` | TCP connection is being established. |
| `Connected` | TCP connection is established; authentication has not yet started or completed. |
| `Authenticating` | The ESL auth challenge has been received and credentials are being sent. |
| `Authenticated` | Authentication succeeded. The runtime is ready to send commands and receive events. |
| `Reconnecting` | The connection was lost and the supervisor is executing a retry attempt. |
| `Draining` | The runtime has been asked to shut down. New commands are rejected. The runtime is waiting for inflight operations to complete. |
| `Closed` | The runtime has been explicitly shut down. No further reconnects will occur for this runtime instance. |

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
  -> Disconnected          on auth reply -ERR (no retry for auth failure)
  -> Disconnected          on handshake timeout or malformed/unexpected inbound handshake frame

Authenticated
  -> Reconnecting          on socket error or unexpected disconnect
  -> Draining              on disconnect() called

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
| Auth reply -ERR | `Authenticating` → `Disconnected` | `Authenticating` → `Failed` |
| Socket error or close while Authenticated | `Authenticated` → `Reconnecting` | `Active` → `Disconnected` |
| Retry attempt fired | `Reconnecting` → `Connecting` | — |
| Max retries exhausted | `Reconnecting` → `Disconnected` | — |
| `disconnect()` called | `Authenticated` → `Draining` | — |
| Drain complete | `Draining` → `Closed` | `Active` → `Disconnected` |

---

## What happens on disconnect

In the currently implemented slice, when a connection drops (socket close, network error, unexpected EOF, or user-triggered close with inflight work):

1. `ConnectionState` transitions to `Reconnecting` (if retry is configured) or `Disconnected`.
2. `SessionState` transitions to `Disconnected`.
3. All pending `api` commands that have been sent but have not received a reply are rejected with `ConnectionLostException`.
4. Commands that are enqueued but not yet sent are also rejected with `ConnectionLostException`.
5. Pending `bgapi` jobs that have already been accepted remain tracked across unexpected supervised reconnect. They are rejected only on explicit shutdown or when their orphan timeout expires.
6. Desired subscriptions and filters remain tracked in memory and are restored after re-authentication in this order: event baseline first, then filters.

---

## What triggers reconnect

Reconnect is triggered by any transition to `Reconnecting`. This can be caused by:

- Socket error (network drop, TCP reset, FreeSWITCH restart)
- Unexpected EOF on the socket while in `Authenticated` state
- TCP connect failure during an already-supervised connect/reconnect cycle

Reconnect is NOT triggered by:

- Calling `disconnect()` (clean disconnect, transitions to `Disconnected` then `Closed`)
- Auth failure
- Handshake timeout
- Malformed or unexpected inbound handshake traffic

---

## Auth failure behavior

If FreeSWITCH returns `-ERR invalid` on the auth challenge:

1. `SessionState` transitions to `Failed`.
2. `ConnectionState` transitions to `Disconnected`.
3. No further reconnect occurs. The `connect()` promise rejects with `AuthenticationException`.

The current implementation does not retry auth rejection, because a bad password is not a transient transport failure.

---

## Resubscription after reconnect

After `ConnectionState` reaches `Authenticated` following a reconnect:

1. The runtime re-authenticates the ESL session.
2. It restores `event plain all` or the named desired subscription set.
3. It restores desired filters.
4. Only after restore completes does the runtime transition back to `Authenticated` / `Active`.

No command or mutation queue is applied during recovery. `api()` and subscription/filter mutations fail closed until the runtime becomes authenticated again.
