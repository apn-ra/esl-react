# Public API reference

This document describes the stable public surface of `apntalk/esl-react`. Consumers should import only the types listed here. Everything else is internal and may change without notice until 1.0.

See [docs/stability-policy.md](stability-policy.md) for the full stability policy.

Status note: this pass implements and tests the connect/auth lifecycle, serial `api()` dispatch, tracked `bgapi()` dispatch and completion handling, bounded drain shutdown, live typed event delivery, unknown-event handling, subscription/filter control, reconnect supervision after unexpected disconnect, desired-state restore after re-authentication, explicit overload rejection, health snapshots, runner lifecycle snapshots, and a stable replay-hook artifact contract for the currently supported runtime paths. Broader heartbeat orchestration remains intentionally minimal.
It also adds adapter-friendly runner seams for consuming prepared runtime inputs from higher layers without exposing runtime internals.

---

## Entry point

```php
AsyncEslRuntime::make(
    \Apntalk\EslReact\Config\RuntimeConfig $config,
    ?\React\EventLoop\LoopInterface $loop = null
): \Apntalk\EslReact\Contracts\AsyncEslClientInterface
```

`AsyncEslRuntime` is the only supported way to construct a runtime instance. The returned value is typed as `AsyncEslClientInterface`; do not rely on the concrete class.

If `$loop` is `null`, the global `React\EventLoop\Loop::get()` instance is used. Pass an explicit loop when integrating into an existing application.

### Runner entry point

```php
AsyncEslRuntime::runner(): \Apntalk\EslReact\Contracts\RuntimeRunnerInterface
```

Returns the stable runner contract for the first live prepared-input milestone.
Consumers should type against `RuntimeRunnerInterface`, not against the concrete
runner class.

---

## Stable contracts

### AsyncEslClientInterface

```
Apntalk\EslReact\Contracts\AsyncEslClientInterface
```

The primary consumer-facing interface.

Current contract notes for the implemented slice:

- `connect()` rejects with `AuthenticationException` for auth rejection, `CommandTimeoutException` for handshake timeout, and `ConnectionException` for transport or malformed-handshake failures.
- `api()` is illegal before successful authentication and rejects with `ConnectionException`.
- Inflight `api()` calls reject with `ConnectionLostException` on unexpected transport loss and with `DrainException` if bounded drain terminates them during explicit shutdown.
- `api()` and subscription/filter mutations are rejected while the runtime is recovering after an unexpected disconnect.
- `disconnect()` is terminal for the runtime instance; it does not trigger reconnect.
- `disconnect()` is a bounded drain, not an immediate abort: new work is rejected immediately, accepted inflight work gets a bounded settle window, and remaining work is rejected deterministically at the drain deadline.
- `bgapi()` is illegal before successful authentication and during recovery.
- `bgapi()` returns a handle immediately; `BgapiJobHandle::jobUuid()` becomes non-empty only after FreeSWITCH acknowledges the bgapi command.
- Pending bgapi jobs survive unexpected supervised reconnect but are rejected with `DrainException` on explicit shutdown.
- Overload rejects new `api()`, `bgapi()`, and live-session subscription/filter mutations with `BackpressureException`.

| Method | Return type | Description |
|---|---|---|
| `connect()` | `PromiseInterface<void>` | Establish connection and authenticate; repeated calls share the active connect promise until it settles |
| `disconnect()` | `PromiseInterface<void>` | Enter drain mode, stop accepting new work, wait up to the drain timeout, then close terminally |
| `api(string $command)` | `PromiseInterface<ApiReply>` | Dispatch a serial api command |
| `bgapi(string $command, string $args)` | `BgapiJobHandle` | Dispatch a bgapi command, return a tracked handle immediately |
| `events()` | `EventStreamInterface` | Access the event stream |
| `subscriptions()` | `SubscriptionManagerInterface` | Access subscription management |
| `health()` | `HealthReporterInterface` | Access runtime health |

### EventStreamInterface

```
Apntalk\EslReact\Contracts\EventStreamInterface
```

| Method | Description |
|---|---|
| `onRawEnvelope(callable $listener): void` | Register a raw inbound event-envelope listener |
| `onEvent(string $eventName, callable $listener): void` | Register a typed listener keyed by ESL event name such as `CHANNEL_CREATE` |
| `onAnyEvent(callable $listener): void` | Register a listener for all known typed events |
| `onUnknown(callable $listener): void` | Register a listener for well-formed but unmapped events surfaced as `RawEvent` |

Current event-stream notes:

- Raw envelope listeners and typed/unknown listeners can observe the same inbound event frame.
- Raw envelope delivery happens before typed or unknown listener dispatch.
- `onAnyEvent()` currently observes known typed events only; unknown events stay on the explicit unknown path.
- Listener exceptions are contained and currently written to stderr by the internal default handler.

See [docs/async-model.md](async-model.md) for ordering guarantees and listener exception policy.

### SubscriptionManagerInterface

```
Apntalk\EslReact\Contracts\SubscriptionManagerInterface
```

| Method | Description |
|---|---|
| `subscribe(string $eventName): PromiseInterface<void>` | Subscribe to a named event |
| `unsubscribe(string $eventName): PromiseInterface<void>` | Unsubscribe from a named event |
| `subscribeAll(): PromiseInterface<void>` | Subscribe to all events |
| `addFilter(string $header, string $value): PromiseInterface<void>` | Add an inbound filter |
| `removeFilter(string $header, string $value): PromiseInterface<void>` | Remove an inbound filter |
| `activeEventNames(): array<string>` | List currently active event-name subscriptions |

Current subscription/filter notes:

- The baseline is explicit and caller-owned; the runtime does not invent a broad default application subscription policy.
- `RuntimeConfig::$subscriptions` seeds the initial desired event/filter state before the first successful authentication.
- Mutations are only allowed while the runtime is authenticated and not draining.
- Desired active subscriptions and filters are tracked locally in memory.
- Duplicate subscribe/filter-add operations and removal of missing state are idempotent no-ops.
- `subscribeAll()` is supported, but specific unsubscribe from the "all events" state is rejected in the current implementation.
- After a successful reconnect, the runtime restores `subscribeAll()` or the named desired event set first, then restores desired filters.
- When the runtime is overloaded, subscription/filter mutations are rejected with `BackpressureException`.

### HealthReporterInterface

```
Apntalk\EslReact\Contracts\HealthReporterInterface
```

| Method | Return type | Description |
|---|---|---|
| `snapshot()` | `HealthSnapshot` | Return a point-in-time health snapshot |
| `isLive()` | `bool` | Whether the heartbeat monitor considers the connection alive |

Current health notes:

- `snapshot()->connectionState` transitions through `Reconnecting` during unexpected disconnect recovery.
- `snapshot()->reconnectAttempts` reflects retry attempts since the last successful authenticated connection and resets to zero after recovery succeeds.
- `snapshot()->isLive` is driven by the currently implemented minimal heartbeat monitor. A false value may mean either a degraded but still-authenticated connection or a disconnected/recovering runtime.
- `snapshot()->pendingBgapiJobCount` includes jobs that are still pending across an unexpected reconnect.
- `snapshot()->totalInflightCount` is the runtime-wide accepted work count used by overload and drain decisions.
- `snapshot()->isOverloaded` reflects whether new work would currently be rejected for backpressure reasons.

### RuntimeRunnerInterface

```
Apntalk\EslReact\Contracts\RuntimeRunnerInterface
```

| Method | Return type | Description |
|---|---|---|
| `run(RuntimeRunnerInputInterface $input, ?LoopInterface $loop = null)` | `RuntimeRunnerHandle` | Start the live runtime from prepared runtime-owned input and return a startup handle |

Current runner notes:

- `run()` starts the live runtime immediately by delegating to the existing `connect()` path.
- The returned handle exposes `startupPromise()` plus a coarse startup state model.
- The returned handle exposes `lifecycleSnapshot()` for higher layers that need read-only startup + live runtime observation without taking runtime ownership.
- The returned handle exposes `onLifecycleChange()` for push-based lifecycle observation without polling.
- Package-owned live harnesses cover the runner handle startup and explicit drain-to-stop observation path on a real FreeSWITCH target.
- Package-owned live harnesses now cover unexpected transport-loss reconnect and recovery on the runner seam in opt-in lab environments that provide safe disruption and restore commands.
- Package-owned deterministic runner tests cover event subscription plus `bgapi()` completion activity while lifecycle snapshots and pushed markers remain authenticated/live, and an opt-in live harness has validated the same runner-surface truth against real FreeSWITCH event and background-job traffic.
- Package-owned deterministic runner tests cover combined conditions where pending `bgapi()` and desired event subscriptions intersect with degraded liveness or reconnecting runtime states. Live combined-condition fault injection remains intentionally deferred.
- Package-owned deterministic runner tests now cover heartbeat/liveness degradation and recovery on the same snapshot/push observation surface, and an opt-in live harness can validate the same path on a quiet target.
- Package-owned deterministic runner tests also cover the second-miss heartbeat dead/reconnect path, and a separate opt-in live harness can validate that deeper path when the lab can make the target go silent without immediately closing the connection.
- Config-driven `RuntimeRunnerInputInterface` inputs remain supported.
- Richer `PreparedRuntimeBootstrapInputInterface` inputs can provide prepared ReactPHP transport access, a prepared ingress pipeline, and runtime-local session context.
- `PreparedRuntimeDialTargetInputInterface` additively allows richer prepared-bootstrap inputs to override the dial target URI used by the prepared connector for startup and reconnect attempts.
- Ongoing runtime lifecycle after startup remains on the stable client/health surface rather than a second parallel runner control plane.
- Direct polling of `apntalk/esl-core` `TransportInterface` and decoded `InboundPipelineInterface` routing are not part of this runner-input expansion.

### RuntimeLifecycleSnapshot

```
Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot
```

Read-only lifecycle and health observation returned by `RuntimeRunnerHandle::lifecycleSnapshot()`.

It combines:

- runner startup state (`RuntimeRunnerState`)
- endpoint and optional `RuntimeSessionContext`
- current `HealthSnapshot`
- startup error class/message when startup failed

Helper methods expose coarse truth for downstream packages:

| Method | Meaning |
|---|---|
| `isConnected()` | Socket is at or above connected state |
| `isStarting()` | Runner startup promise has not settled yet |
| `isAuthenticated()` | Runtime has authenticated or is draining from an authenticated state |
| `isLive()` | Heartbeat/liveness currently reports live |
| `isReconnecting()` | Runtime health currently reports reconnecting |
| `isDraining()` | Runtime health currently reports drain mode |
| `isStopped()` | Runtime health reports terminal `Closed` connection state |
| `isFailed()` | Runner startup failed or runtime session state is failed |
| `connectionState()` | Current `ConnectionState`, if health is available |
| `sessionState()` | Current `SessionState`, if health is available |
| `reconnectAttempts()` | Current reconnect attempt count |
| `lastHeartbeatAtMicros()` | Last observed heartbeat/activity timestamp |
| `lastRuntimeErrorClass()` / `lastRuntimeErrorMessage()` | Last runtime error recorded by health |

This is an observation surface only. It does not start, stop, reconnect, or supervise the runtime.

`RuntimeRunnerHandle::onLifecycleChange()` reuses this same snapshot type for
push-based lifecycle observation. It does not introduce a second public state
machine or a separate lifecycle event taxonomy.

### RuntimeRunnerInputInterface

```
Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface
```

| Method | Return type | Description |
|---|---|---|
| `endpoint()` | `string` | Higher-layer endpoint identity for the prepared runtime input |
| `runtimeConfig()` | `RuntimeConfig` | Runtime-owned config used for the live `esl-react` start path |

`PreparedRuntimeInput` is the provided immutable implementation for the first pass.

### PreparedRuntimeBootstrapInputInterface

```
Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface
```

Additive richer runner input contract. It extends `RuntimeRunnerInputInterface`
and preserves the config-driven path.

| Method | Return type | Description |
|---|---|---|
| `connector()` | `ConnectorInterface` | Prepared ReactPHP transport connector used for startup and reconnect attempts |
| `inboundPipeline()` | `InboundPipelineInterface` | Prepared `esl-core` ingress pipeline associated with this handoff |
| `sessionContext()` | `RuntimeSessionContext` | Runtime-local session identity and scalar metadata |

Current bootstrap-input notes:

- The prepared connector is consumed by the live runtime core instead of the default `React\Socket\Connector`.
- The prepared pipeline is accepted and reset by the runner handoff lifecycle, but the current live ingress path still routes through the existing frame pump/router.
- The session context is exposed on `RuntimeRunnerHandle` for runtime identity correlation. It must not carry Laravel control-plane ownership or worker assignment policy.
- Without an additive dial-target override, the prepared connector dials `RuntimeConfig::connectionUri()`.

`PreparedRuntimeBootstrapInput` is the provided immutable implementation for this richer path.

### PreparedRuntimeDialTargetInputInterface

```
Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface
```

Additive prepared-bootstrap contract for higher layers that need the prepared
connector to dial a URI other than `RuntimeConfig::connectionUri()`.

| Method | Return type | Description |
|---|---|---|
| `dialUri()` | `string` | Dial target URI used by the prepared connector for startup and reconnect attempts |

Current dial-target notes:

- This is intended for richer ReactPHP connector handoff, including non-default dial schemes such as `tls://...`.
- The dial target is transport/bootstrap input only. It does not replace the higher-layer `endpoint()` identity surface.
- Direct polling handoff of `apntalk/esl-core` `TransportInterface` remains deferred.

---

## Config objects

All config objects are immutable value objects. Setters do not exist; pass values through the constructor or named arguments.

### RuntimeConfig

```
Apntalk\EslReact\Config\RuntimeConfig
```

| Property | Type | Description |
|---|---|---|
| `host` | `string` | FreeSWITCH host |
| `port` | `int` | ESL port (default 8021) |
| `password` | `string` | ESL password |
| `retryPolicy` | `RetryPolicy` | Reconnect retry policy |
| `heartbeat` | `HeartbeatConfig` | Heartbeat monitoring config |
| `backpressure` | `BackpressureConfig` | Backpressure thresholds |
| `subscriptions` | `SubscriptionConfig` | Initial desired subscription/filter intent to seed on first connect |
| `commandTimeout` | `CommandTimeoutConfig` | Command timeout config |
| `replayCaptureEnabled` | `bool` | Enable replay hook emission (default false) |
| `replayCaptureSinks` | `list<ReplayCaptureSinkInterface>` | Sinks that receive replay envelopes when capture is enabled |

### RetryPolicy

```
Apntalk\EslReact\Config\RetryPolicy
```

| Property | Type | Description |
|---|---|---|
| `enabled` | `bool` | Whether reconnect attempts are enabled |
| `maxAttempts` | `int` | Maximum reconnect attempts (0 = unlimited) |
| `initialDelaySeconds` | `float` | Initial delay before first reconnect attempt |
| `backoffMultiplier` | `float` | Delay multiplier per attempt |
| `maxDelaySeconds` | `float` | Upper bound on delay between attempts |

| Static constructor | Description |
|---|---|
| `RetryPolicy::default()` | Current defaults: reconnect enabled, unlimited attempts, 1s initial, 2x multiplier, 60s max |
| `RetryPolicy::disabled()` | No reconnect |

### HeartbeatConfig

```
Apntalk\EslReact\Config\HeartbeatConfig
```

| Property | Type | Description |
|---|---|---|
| `intervalSeconds` | `float` | How often heartbeat checks run when enabled |
| `timeoutSeconds` | `float` | Idle threshold before liveness degrades |
| `enabled` | `bool` | Whether heartbeat monitoring is active |

### BackpressureConfig

```
Apntalk\EslReact\Config\BackpressureConfig
```

| Property | Type | Description |
|---|---|---|
| `maxInflightCommands` | `int` | Current runtime-wide accepted-work threshold for overload rejection |
| `rejectOnOverload` | `bool` | Whether overload rejects new work instead of buffering indefinitely |
| `drainTimeoutSeconds` | `float` | Maximum time accepted inflight work may continue after drain begins |

### SubscriptionConfig

```
Apntalk\EslReact\Config\SubscriptionConfig
```

| Property | Type | Description |
|---|---|---|
| `initialEventNames` | `array<string>` | Event names that seed the runtime's initial desired subscription set |
| `subscribeAll` | `bool` | Whether the runtime should seed its initial desired state as `event plain all` |
| `initialFilters` | `array<array{headerName: string, headerValue: string}>` | Header filters that seed the runtime's initial desired filter set |

### CommandTimeoutConfig

```
Apntalk\EslReact\Config\CommandTimeoutConfig
```

| Property | Type | Description |
|---|---|---|
| `apiTimeoutSeconds` | `float` | Timeout for `api()` replies and the current connect/auth handshake budget |
| `bgapiAckTimeoutSeconds` | `float` | Timeout for bgapi acknowledgment |
| `subscriptionTimeoutSeconds` | `float` | Timeout for subscription command acknowledgments |
| `bgapiOrphanTimeoutSeconds` | `float` | Timeout for unmatched bgapi completion tracking |

---

## Read models and DTOs

### HealthSnapshot

```
Apntalk\EslReact\Health\HealthSnapshot
```

See [docs/health-model.md](health-model.md) for field descriptions.

### ConnectionState

```
Apntalk\EslReact\Connection\ConnectionState
```

Backed enum. Values: `Disconnected`, `Connecting`, `Connected`, `Authenticating`, `Authenticated`, `Reconnecting`, `Draining`, `Closed`.

See [docs/runtime-lifecycle.md](runtime-lifecycle.md) for transition rules.

### SessionState

```
Apntalk\EslReact\Session\SessionState
```

Backed enum. Values: `NotStarted`, `Authenticating`, `Active`, `Disconnected`, `Failed`.

### BgapiJobHandle

```
Apntalk\EslReact\Bgapi\BgapiJobHandle
```

| Method | Return type | Description |
|---|---|---|
| `jobUuid()` | `string` | The FreeSWITCH Job-UUID after ack; empty string before acceptance is observed |
| `eslCommand()` | `string` | The bgapi command verb |
| `eslArgs()` | `string` | The bgapi command arguments |

### RuntimeRunnerHandle

```
Apntalk\EslReact\Runner\RuntimeRunnerHandle
```

| Method | Return type | Description |
|---|---|---|
| `endpoint()` | `string` | Prepared endpoint identity passed to the runner |
| `client()` | `AsyncEslClientInterface` | Stable async runtime facade |
| `startupPromise()` | `PromiseInterface<void>` | Promise for the initial live runtime startup |
| `state()` | `RuntimeRunnerState` | Coarse startup lifecycle state |
| `startupError()` | `?\Throwable` | Startup failure if the initial connect/auth path failed |
| `sessionContext()` | `?RuntimeSessionContext` | Runtime-local session context when startup used a prepared-bootstrap input |
| `lifecycleSnapshot()` | `RuntimeLifecycleSnapshot` | Read-only packaged runner + health lifecycle snapshot |
| `onLifecycleChange(callable $listener)` | `void` | Register a synchronous lifecycle listener receiving `RuntimeLifecycleSnapshot` values |

`onLifecycleChange()` notes:

- The listener is invoked immediately with the current `RuntimeLifecycleSnapshot`.
- Later callbacks are emitted when coarse lifecycle truth changes on the runtime or runner startup state.
- Listener callbacks run synchronously in registration order.
- Listener exceptions are contained and written to stderr; they do not crash the runtime or block later listeners.

### RuntimeRunnerState

```
Apntalk\EslReact\Runner\RuntimeRunnerState
```

Backed enum. Values: `Starting`, `Running`, `Failed`.

### PreparedRuntimeBootstrapInput

```
Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput
```

Immutable implementation of `PreparedRuntimeBootstrapInputInterface`.
It also implements `PreparedRuntimeDialTargetInputInterface`; `dialUri()`
defaults to `RuntimeConfig::connectionUri()` when no explicit override is
provided.

### RuntimeSessionContext

```
Apntalk\EslReact\Runner\RuntimeSessionContext
```

Small runtime-local identity DTO for runner handoff correlation. It carries a
non-empty `sessionId` plus scalar-or-null metadata. It is not a control-plane,
assignment, or operator-surface model.
| `dispatchedAtMicros()` | `float` | Local dispatch timestamp |
| `promise()` | `PromiseInterface<BackgroundJobEvent>` | Resolves on matching completion; rejects on ack timeout, orphan timeout, or terminal shutdown |

---

## Exceptions

All exceptions extend `EslRuntimeException`.

| Class | Thrown when |
|---|---|
| `EslRuntimeException` | Base class for all runtime exceptions |
| `ConnectionException` | TCP connection could not be established |
| `AuthenticationException` | ESL authentication was rejected |
| `CommandTimeoutException` | A command or bgapi job exceeded its configured timeout |
| `BackpressureException` | Command rejected because inflight limit was reached |
| `ConnectionLostException` | Connection dropped while a command was inflight |
| `DrainException` | Command rejected because the runtime is draining |

All exception classes are under `Apntalk\EslReact\Exceptions\`.

---

## Internal types (not stable)

The following components exist as implementation details and are not part of the stable public surface. Do not import or depend on them. They may change at any time until 1.0.

- `RuntimeClient` and its reconnect/drain lifecycle internals
- `HeartbeatMonitor`, `IdleTimer`, and related liveness internals
- `AsyncCommandBus` and `PendingCommand`
- `FrameReader`, `FrameWriter`, `EnvelopePump`
- `InboundMessageRouter`, `OutboundMessageDispatcher`
- `ActiveSubscriptionSet`, `FilterManager`, and subscription-state internals
- `RuntimeReplayCapture` and replay-shaping internals
- `BgapiJobTracker`, `PendingBgapiJob`, and bgapi dispatcher internals
- `ReconnectScheduler`, `CircuitState`
- `TypedEventEmitter`, `UnknownEventHandler`, `EventDispatchContext`
