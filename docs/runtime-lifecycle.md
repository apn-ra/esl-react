# Runtime lifecycle

Status note: the connect/auth state transitions, unexpected-disconnect reconnect flow, desired subscription/filter restoration, bounded drain completion, and observational replay-hook emission documented here are implemented and test-covered. Heartbeat remains intentionally minimal, but its degrade/probe/dead behavior is explicit and regression-tested.

Hardening note for the implemented slice:

- If `disconnect()` is called while `connect()` is still pending, the pending connect promise is rejected and the runtime moves to `Closed`/`Disconnected`.
- If the connect/auth handshake does not complete before the current handshake timeout budget, `connect()` rejects with `CommandTimeoutException`, the runtime returns to `Disconnected`, and `SessionState` becomes `Failed`.
- Unexpected or malformed inbound frames during the handshake fail closed and reject `connect()`.
- After authentication succeeds, inbound event frames are delivered immediately on the live socket.
- After an unexpected disconnect and successful re-authentication, the runtime restores the in-memory desired subscription baseline first and then restores filters before transitioning back to `Authenticated`/`Active`.
- Replay capture, when enabled, survives an unexpected reconnect for later runtime traffic because it stays attached to the surviving runtime instance. It does not persist lost traffic or provide process-restart recovery.

## State machines

The runtime maintains two parallel state machines: `ConnectionState` and `SessionState`. They are related but distinct. A connection can be `Authenticated` (ConnectionState) while the session is `Active` (SessionState) — both must be healthy for the runtime to be fully operational.

The prepared-input runner seam exposes a coarse `RuntimeRunnerState` model.
It is intentionally smaller than the ongoing runtime lifecycle model and exists
to describe the initial runner startup path for both config-driven inputs and
richer prepared-bootstrap inputs:

```
Starting
  -> Running              on successful initial connect/auth
  -> Failed               on initial connect/auth failure
```

`RuntimeRunnerHandle::lifecycleSnapshot()` is the preferred higher-layer
observation seam. It combines `RuntimeRunnerState`, optional
`RuntimeSessionContext`, startup failure detail, and the current `HealthSnapshot`
in one read-only value object. After `RuntimeRunnerState` reaches `Running`,
ongoing lifecycle truth still comes from `ConnectionState`, `SessionState`, and
`HealthSnapshot`; the lifecycle snapshot only packages that truth for consumers.
This avoids creating a second competing control-plane model in `esl-react`.

`RuntimeRunnerHandle::onLifecycleChange()` is the push-based companion to
`lifecycleSnapshot()`. It emits the same `RuntimeLifecycleSnapshot` shape:

- once immediately when a listener registers
- again when coarse lifecycle truth changes

This remains observational only. It does not add control hooks or define a
second public state machine.

The automated live harness currently validates this observation surface through
startup, authenticated live operation, explicit `disconnect()` drain entry, and
the final `Closed` state. Unexpected transport-loss reconnect recovery is also
validated by an opt-in automated lab harness when the environment provides safe
disruption and restore commands, alongside the existing manual live harnesses
for staging paths where operator-driven disruption is still preferred.
Heartbeat/liveness degradation is regression-tested deterministically on the
runner seam and can also be exercised by an opt-in live harness on relatively
quiet targets, where the expected path is `Authenticated/live` ->
`Authenticated/not-live` -> `Authenticated/live` before any transport-loss
reconnect becomes necessary.
The deeper second-miss path is also regression-tested deterministically on the
runner seam. When a lab target can be made silent without immediately closing
the connection, an opt-in live harness can validate the expected sequence
`Authenticated/live` -> `Authenticated/not-live` ->
`Reconnecting/not-live` -> `Authenticated/live`.

For richer prepared-bootstrap inputs, the prepared connector participates in
the normal `ConnectionState` transitions because it supplies the live connection
for startup and reconnect attempts. The prepared ingress pipeline is reset as
part of runner handoff consumption, but decoded-pipeline routing is not yet the
live ingress path.
When the richer input also implements the additive prepared dial-target
contract, that same explicit dial URI is reused for both startup and reconnect
attempts instead of forcing `RuntimeConfig::connectionUri()`.

Observation note for shutdown and reconnect:

- Explicit `disconnect()` emits `Draining` before the terminal `Closed` state.
- Unexpected transport loss emits `Reconnecting` when supervision is active and
  only later returns to `Authenticated` on recovery or to `Disconnected` if
  retries are exhausted.
- Heartbeat failure after the second consecutive missed liveness check also
  enters the reconnect/disconnect path rather than the explicit drain path.
- These remain distinct on both snapshot reads and pushed lifecycle callbacks.

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
| `Draining` | The runtime has been asked to shut down. New work is rejected immediately while accepted inflight work gets a bounded settle window. |
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
  -> Closed                on inflight work settling or being terminated at the drain deadline

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
| Drain settle or deadline | `Draining` → `Closed` | `Active` → `Disconnected` |

---

## What happens on unexpected disconnect

In the currently implemented slice, when the transport drops unexpectedly (socket close, network error, unexpected EOF):

1. `ConnectionState` transitions to `Reconnecting` (if retry is configured) or `Disconnected`.
2. `SessionState` transitions to `Disconnected`.
3. All pending `api` commands that have been sent but have not received a reply are rejected with `ConnectionLostException`.
4. Commands that are enqueued but not yet sent are also rejected with `ConnectionLostException`.
5. Pending `bgapi` jobs that have already been accepted remain tracked across unexpected supervised reconnect. They are rejected only on explicit shutdown, drain deadline expiry, or when their orphan timeout expires.
6. Desired subscriptions and filters remain tracked in memory and are restored after re-authentication in this order: event baseline first, then filters.

## What happens on heartbeat degradation

In the current bounded heartbeat model:

1. Inbound activity keeps the runtime live.
2. After one consecutive missed liveness check, `isLive` becomes `false` while `ConnectionState` may still remain `Authenticated`.
3. At that first missed check, the runtime may issue one lightweight `api status` probe if it is authenticated, not draining, and has no command already inflight.
4. If activity resumes before the next missed check, the runtime returns to `Live`.
5. If a second consecutive missed liveness check occurs without recovery, the runtime closes the socket and enters the normal disconnect/reconnect path.

Drain remains terminal and does not use heartbeat recovery.

## What happens on drain

When `disconnect()` is called on a live runtime:

1. `ConnectionState` transitions to `Draining`.
2. New `api()`, `bgapi()`, and subscription/filter mutations reject immediately.
3. Already-accepted inflight `api()` and `bgapi()` work may continue until `BackpressureConfig::$drainTimeoutSeconds`.
4. If inflight work settles before the deadline, the runtime sends `exit` and closes terminally.
5. If inflight work remains at the deadline, the runtime rejects that remaining work with `DrainException`, then closes terminally.

Explicit drain is different from unexpected transport loss:

- new work rejects immediately with `DrainException`
- remaining inflight `api()` work is allowed to settle until the deadline, then rejects with `DrainException` if still unresolved
- remaining pending `bgapi()` work is allowed to settle until the deadline, then rejects with `DrainException` if still unresolved

Drain is an explicit shutdown path and does not trigger reconnect.

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
