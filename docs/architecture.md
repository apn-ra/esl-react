# Architecture

## Package layering

```text
apntalk/laravel-freeswitch-esl
  Laravel integration, control plane, persistence-backed PBX selection
        |
        v
apntalk/esl-react
  Async inbound ESL runtime: connection lifecycle, command dispatch,
  event streaming, reconnect, liveness, health, replay hooks
        |
        v
apntalk/esl-core
  Protocol substrate: parsing, serialization, typed models,
  correlation metadata, replay envelope primitives
```

`esl-react` depends on `esl-core`. It remains framework-agnostic and runtime-focused.

---

## What this package owns

- TCP socket lifecycle for an inbound ESL client connection
- ESL auth handshake and session lifecycle
- Live protocol read/write loop
- Serial `api()` command dispatch and reply handling
- `bgapi()` dispatch, ack tracking, completion correlation, and orphan cleanup
- Raw and typed event streaming
- Desired subscription/filter state and reconnect restore
- Reconnect supervision with bounded retry/backoff
- Minimal bounded heartbeat/liveness tracking
- Runtime health snapshots
- Backpressure rejection and bounded drain shutdown
- Observational replay-safe runtime hook emission

## What this package does not own

- Laravel or service-container integration
- Persistent storage or registry concerns
- Multi-PBX orchestration or control-plane ownership
- Durable replay storage or replay execution
- Outbound ESL server behavior
- Business-specific telephony normalization

---

## Actual implementation composition

The implementation is centered around one internal coordinator plus a small set of focused helpers. The plan-era class names should be read as responsibilities, not as a promise that every responsibility exists as its own class.

### Runtime coordinator

`RuntimeClient`

Coordinates connection establishment, auth, reconnect, bounded drain shutdown, work acceptance/rejection, desired-state restore, and liveness transitions. This is the internal implementation behind `AsyncEslClientInterface`.

### Protocol path

`FrameReader`, `FrameWriter`, `EnvelopePump`, `InboundMessageRouter`, `OutboundMessageDispatcher`

Reads raw socket bytes into `esl-core` frames, writes outbound commands, and classifies inbound traffic into replies, events, disconnect notices, or unroutable input.

### Command path

`AsyncCommandBus`, `PendingCommand`

Implements the serial `api()` queue required by ESL. Handles FIFO dispatch, reply correlation, timeout rejection, connection-loss rejection, and drain-time termination for accepted command work.

### Event path

`EventStream`, `TypedEventEmitter`, `UnknownEventHandler`, `EventDispatchContext`

Delivers raw event envelopes first, then typed events or the explicit unknown-event path. Listener failures are contained and do not crash the runtime.

### Subscription/filter state

`SubscriptionManager`, `ActiveSubscriptionSet`, `FilterManager`

Keeps desired event/filter state in memory. `RuntimeConfig::$subscriptions` seeds that desired state before the first successful authentication. On reconnect, the same desired state is replayed in deterministic order: event baseline first, then filters.

### Reconnect and liveness

`ReconnectScheduler`, `CircuitState`, `HeartbeatMonitor`, `IdleTimer`, `LivenessState`

Reconnect uses `RetryPolicy` for bounded retry/backoff. Liveness is intentionally minimal: inbound activity keeps the runtime live, one missed liveness check degrades state and may issue one safe probe, and a second consecutive miss closes the socket and falls into the normal disconnect/reconnect path.

### Health and observability

`RuntimeHealthReporter`, `HealthSnapshot`

Builds point-in-time health snapshots exposing connection/session state, liveness, reconnect attempts, active subscriptions, accepted-work counts, overload state, drain state, and the last recorded error.

### Bgapi tracking

`BgapiDispatcher`, `BgapiJobTracker`, `PendingBgapiJob`

Returns a tracked handle immediately, assigns `Job-UUID` on ack, correlates `BACKGROUND_JOB` completion by UUID, and applies orphan timeout or explicit terminal shutdown semantics when completion never arrives.

### Replay hooks

`RuntimeReplayCapture`

Observes accepted dispatch, replies, inbound events, and bgapi lifecycle points, then emits replay-safe envelopes to configured sinks. This is a hook emission layer only; it is not storage, replay execution, or a control plane.

---

## Runtime wiring

`AsyncEslRuntime::make()` assembles the runtime like this:

1. Build correlation and replay capture infrastructure.
2. Build protocol, command, bgapi, heartbeat, health, and reconnect helpers.
3. Seed desired subscription/filter state from `RuntimeConfig::$subscriptions`.
4. Construct `RuntimeClient` with the long-lived helpers above.
5. On successful authentication, restore desired session state, mark the runtime live, and start heartbeat monitoring.
6. On unexpected disconnect, keep long-lived helpers such as event listeners, desired-state tracking, bgapi tracking, and replay capture alive while the socket is re-established.
7. On explicit `disconnect()`, enter bounded drain and close terminally without reconnecting.

---

## Public orientation

Consumers should code against the narrow public surface only:

- `AsyncEslClientInterface`
- `EventStreamInterface`
- `SubscriptionManagerInterface`
- `HealthReporterInterface`
- `AsyncEslRuntime::make()`

Everything else is internal, even if it happens to live under `src/`.
