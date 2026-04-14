# Public API reference

This document describes the stable public surface of `apntalk/esl-react`. Consumers should import only the types listed here. Everything else is internal and may change without notice until 1.0.

See [docs/stability-policy.md](stability-policy.md) for the full stability policy.

Status note: this pass implements and tests the connect/auth lifecycle, serial `api()` dispatch, close-based `disconnect()`, event stream attachment, and health snapshots. Reconnect supervision, heartbeat-driven liveness orchestration, replay hooks, and subscription restoration remain planned work and should be treated as provisional.

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
- Inflight `api()` calls reject with `ConnectionLostException` if the socket closes before their reply arrives.

| Method | Return type | Description |
|---|---|---|
| `connect()` | `PromiseInterface<void>` | Establish connection and authenticate; repeated calls share the active connect promise until it settles |
| `disconnect()` | `PromiseInterface<void>` | Close the active socket and resolve when close is observed; full drain semantics remain provisional |
| `api(string $command)` | `PromiseInterface<ApiReply>` | Dispatch a serial api command |
| `bgapi(string $command, string $args)` | `BgapiJobHandle` | Dispatch a bgapi command, returns handle immediately |
| `events()` | `EventStreamInterface` | Access the event stream |
| `subscriptions()` | `SubscriptionManagerInterface` | Access subscription management |
| `health()` | `HealthReporterInterface` | Access runtime health |

### EventStreamInterface

```
Apntalk\EslReact\Contracts\EventStreamInterface
```

| Method | Description |
|---|---|
| `onRawEnvelope(callable $listener): void` | Register a raw envelope listener |
| `onEvent(string $eventClass, callable $listener): void` | Register a typed event listener |
| `onUnknown(callable $listener): void` | Register a listener for unrecognized event types |

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

Subscriptions and filters are tracked locally. Automatic restoration after reconnect is planned, not implemented in this pass.

### HealthReporterInterface

```
Apntalk\EslReact\Contracts\HealthReporterInterface
```

| Method | Return type | Description |
|---|---|---|
| `snapshot()` | `HealthSnapshot` | Return a point-in-time health snapshot |
| `isLive()` | `bool` | Whether the heartbeat monitor considers the connection alive |

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
| `subscriptions` | `SubscriptionConfig` | Default subscriptions |
| `commandTimeout` | `CommandTimeoutConfig` | Command timeout config |
| `replayCaptureEnabled` | `bool` | Enable replay hook emission (default false) |

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
| `maxInflightCommands` | `int` | Maximum concurrent api commands before rejecting new ones |
| `rejectOnOverload` | `bool` | Whether overload currently rejects instead of buffering indefinitely |

### SubscriptionConfig

```
Apntalk\EslReact\Config\SubscriptionConfig
```

| Property | Type | Description |
|---|---|---|
| `initialEventNames` | `array<string>` | Event names configured for initial subscription intent |
| `subscribeAll` | `bool` | Whether to issue `event all` on connect |
| `initialFilters` | `array<array{headerName: string, headerValue: string}>` | Header filters configured for initial intent |

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

### RuntimeState

```
Apntalk\EslReact\Runtime\RuntimeState
```

Composite value object summarizing the current connection and session states alongside liveness. Available via `HealthSnapshot::$runtimeState`.

### BgapiJobHandle

```
Apntalk\EslReact\Bgapi\BgapiJobHandle
```

| Method | Return type | Description |
|---|---|---|
| `jobUuid()` | `string` | The Job-UUID assigned by FreeSWITCH |
| `promise()` | `PromiseInterface<BackgroundJobEvent>` | Resolves on completion |

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

- `ConnectionSupervisor` and its scheduler
- `HeartbeatMonitor` and related liveness internals
- `BackpressureController`, `InflightCounter`, `PauseResumeGate`
- `CommandCorrelationMap`, `PendingCommand`, `CommandTimeoutRegistry`
- `FrameReader`, `FrameWriter`, `EnvelopePump`
- `InboundMessageRouter`, `OutboundMessageDispatcher`
- `ResubscriptionPlanner`, `ActiveSubscriptionSet`
- `RuntimeReplayCapture`, `ReplayEnvelopeFactory`
- `BgapiCompletionMatcher`, `BgapiJobTracker`, `PendingBgapiJob`
- `DisconnectClassifier`, `CircuitState`
- `TypedEventEmitter`, `EventDispatchContext`
