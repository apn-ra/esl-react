# Architecture

## Package layering

```
apntalk/laravel-freeswitch-esl
  Laravel service provider, database-backed PBX registry,
  multi-PBX orchestration policy, application control plane
        |
        v
apntalk/esl-react
  Async runtime: connection lifecycle, command dispatch,
  event streaming, reconnect supervision, health
        |
        v
apntalk/esl-core
  Protocol substrate: parsing, serialization, typed models,
  replay envelope primitives
```

Each layer has a strict upward direction. `esl-react` depends on `esl-core`. `laravel-freeswitch-esl` depends on `esl-react`. Neither lower layer has any knowledge of the layer above it.

---

## esl-react owned responsibilities

These responsibilities belong exclusively to this package:

- Socket connection lifecycle (open, close, error handling)
- Async read and write pumps
- ESL authentication handshake and session lifecycle
- Async `api` command dispatch and reply correlation
- `bgapi` dispatch, job tracking, and completion matching
- Inbound event routing and typed listener delivery
- Reconnect supervision and retry scheduling
- Active subscription and filter management across reconnects
- Heartbeat monitoring and liveness state
- Backpressure control and drain mode
- Runtime health snapshot emission
- Replay-safe runtime hook emission

The following are explicitly NOT owned by this package:

- Laravel framework integration
- Application container wiring
- Database-backed PBX registry
- Cross-node routing or ownership policy
- Durable replay storage or playback
- Business-rule event interpretation

---

## Internal component map

### Connection

`ReactSocketConnector`, `ConnectionFactory`, `AsyncConnection`

Responsible for TCP socket management: opening connections via `react/socket`, tracking raw socket state, and surfacing connection-level errors. `ConnectionState` is the observable state machine associated with the connection layer.

### Session

`SessionAuthenticator`, `SessionLifecycle`, `SessionMetadata`

Responsible for the ESL auth handshake after TCP connection is established. Manages the transition from connected to authenticated. `SessionState` tracks auth progress. `SessionMetadata` captures per-session identifiers.

### Protocol

`FrameReader`, `FrameWriter`, `EnvelopePump`, `InboundMessageRouter`, `OutboundMessageDispatcher`

Bridges the raw socket bytes to and from `esl-core` parsed types. `FrameReader` and `FrameWriter` handle low-level framing. `EnvelopePump` drives the read loop. `InboundMessageRouter` classifies inbound messages as replies, events, or bgapi completions and routes each to the appropriate subsystem. `OutboundMessageDispatcher` serializes and writes commands.

### CommandBus

`AsyncCommandBus`, `PendingCommand`, `CommandTimeoutRegistry`, `CommandCorrelationMap`

Manages the serial `api` command queue. Accepts a command, writes it via the Protocol layer, registers a correlation entry, and resolves the caller's promise when the matching reply arrives. Enforces per-command timeouts via `CommandTimeoutRegistry`.

### Events

`EventStream`, `TypedEventEmitter`, `UnknownEventHandler`, `EventDispatchContext`

Receives classified events from the router. Emits to registered raw envelope listeners first, then routes to typed listeners by event class, then falls through to unknown-event listeners for unrecognized event names. Catches listener exceptions and forwards them to the configured error handler.

### Subscription

`SubscriptionManager`, `ActiveSubscriptionSet`, `FilterManager`, `ResubscriptionPlanner`

Tracks which event names the consumer has subscribed to and which filters are active. On reconnect, `ResubscriptionPlanner` issues the required `event` and `filter` commands to restore the prior subscription state.

### Supervisor

`ConnectionSupervisor`, `ReconnectScheduler`, `CircuitState`, `DisconnectClassifier`

The top-level runtime watchdog. Observes connection state transitions, classifies disconnect reasons, and schedules reconnect attempts according to `RetryPolicy`. Controls the circuit state to prevent uncontrolled reconnect loops.

### Heartbeat

`HeartbeatMonitor`, `IdleTimer`, `LivenessState`

Sends periodic heartbeat commands and tracks acknowledgment timing. Transitions `LivenessState` between live, degraded, and unresponsive based on heartbeat results. Configurable via `HeartbeatConfig`.

### Health

`RuntimeHealthReporter`, `HealthSnapshot`

Collects state from Connection, Session, CommandBus, Bgapi, Subscription, Heartbeat, and Supervisor to build a point-in-time `HealthSnapshot`. The snapshot is the primary operational observability surface exposed to consumers.

### Bgapi

`BgapiDispatcher`, `BgapiJobTracker`, `PendingBgapiJob`, `BgapiCompletionMatcher`

Handles `bgapi` dispatch separately from the serial `api` queue. Issues the command, immediately returns a `BgapiJobHandle` containing the job UUID, and registers the UUID in `BgapiJobTracker`. When a `BACKGROUND_JOB` event arrives, `BgapiCompletionMatcher` resolves the matching handle's promise.

### Backpressure

`BackpressureController`, `InflightCounter`, `BufferPolicy`, `PauseResumeGate`

Tracks inflight command count and enforces configured thresholds. Can pause the read pump or reject new commands when overload thresholds are exceeded. Manages drain mode: stops accepting new work and waits for inflight operations to complete before allowing shutdown.

### Replay

`RuntimeReplayCapture`, `ReplayEnvelopeFactory`, `ReplayDispatchContext`

When replay capture is enabled via `RuntimeConfig::$replayCaptureEnabled`, this component emits `ReplayEnvelope` objects (defined in `esl-core`) on the command reply, event, and bgapi completion paths. Consumers supply a `ReplayCaptureSinkInterface` implementation. This is a hook emission layer only — no storage, no playback.

---

## How components wire together at runtime

At startup, `AsyncEslRuntime::make()` constructs and wires all components:

1. `ConnectionSupervisor` is created and given the `RetryPolicy` and a factory for `AsyncConnection`.
2. `AsyncConnection` holds references to the `Protocol` components, `CommandBus`, `EventStream`, `BgapiDispatcher`, `SubscriptionManager`, and `HeartbeatMonitor`.
3. `InboundMessageRouter` routes each parsed envelope to one of: `CommandBus` (reply), `EventStream` (event), or `BgapiCompletionMatcher` (bgapi completion).
4. `SessionAuthenticator` runs once per connection immediately after TCP establishment.
5. After authentication, `ResubscriptionPlanner` replays any active subscriptions and filters.
6. `HeartbeatMonitor` begins its idle timer.
7. `BackpressureController` wraps the `CommandBus` and can gate command acceptance.
8. `RuntimeHealthReporter` collects state from all components on demand.
9. `RuntimeReplayCapture` (if enabled) taps the reply, event, and bgapi paths.

The `ConnectionSupervisor` restarts the connection path from step 2 on disconnect, without replacing the higher-level components (`EventStream`, `SubscriptionManager`, `BgapiJobTracker`), which survive reconnect.

---

## Facade orientation

Consumers interact with the runtime through four narrow contracts:

- `AsyncEslClientInterface` — command dispatch, connect/disconnect
- `EventStreamInterface` — listener registration
- `SubscriptionManagerInterface` — subscription and filter management
- `HealthReporterInterface` — health snapshot access

These contracts are obtained through `AsyncEslRuntime::make()`. Consumers should not depend on any internal component class directly. All internal types are subject to change until 1.0.
