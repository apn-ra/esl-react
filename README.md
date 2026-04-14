# apntalk/esl-react

ReactPHP-native inbound FreeSWITCH ESL runtime for PHP.

This package turns `apntalk/esl-core` into a usable long-lived async runtime: it manages socket connections, authenticates ESL sessions, dispatches async commands, streams typed events, supervises reconnection, monitors liveness, and exposes operational health — all within the ReactPHP event loop.

Current implementation status:

- Implemented and test-covered in this pass: runtime construction, connect/auth lifecycle, inbound frame pump, serial `api()` dispatch, baseline event stream wiring, health snapshots, deterministic fake-server integration tests.
- Present but still provisional relative to the plan: `bgapi()` completion flow, subscription restoration, reconnect supervision, heartbeat-driven liveness, explicit backpressure policy hardening, replay hooks.
- `connect()` is idempotent while a connection attempt is already in progress and resolves immediately when already authenticated.
- `api()` is rejected before successful authentication.
- The current connect/auth handshake timeout reuses `CommandTimeoutConfig::$apiTimeoutSeconds`.
- `disconnect()` currently closes the active socket and resolves when close is observed; full drain orchestration is still planned work.

---

## Package relationships

| Package | Role |
|---|---|
| `apntalk/esl-core` | Protocol substrate: parsing, serialization, typed command/reply/event models, replay envelope primitives |
| `apntalk/esl-react` | Async runtime: connection lifecycle, command dispatch, event streaming, reconnect supervision, health |
| `apntalk/laravel-freeswitch-esl` | Laravel integration: service provider, container wiring, database-backed PBX registry, control plane |

`esl-react` depends on `esl-core` and is a required dependency of `laravel-freeswitch-esl`. It has no knowledge of Laravel or any application framework.

---

## Scope — v1.x

This package implements an **inbound ESL client runtime only**.

It connects to a running FreeSWITCH instance using the inbound Event Socket Library connection model, where the PHP process initiates the TCP connection to FreeSWITCH.

### What this package does NOT include

- Laravel service provider or framework integration
- Database-backed PBX connection registry
- Multi-PBX orchestration policy or connection routing
- Application-specific telephony normalization rules
- Durable replay persistence or replay playback engine
- Cluster leadership or cross-node ownership policy
- Outbound ESL server support (FreeSWITCH initiates the connection)

These concerns belong to `apntalk/laravel-freeswitch-esl` or application code.

---

## Requirements

- PHP 8.1 or higher
- `react/event-loop` ^1.5
- `react/promise` ^3.2
- `react/socket` ^1.16
- `apntalk/esl-core` ^0.2

---

## Installation

```bash
composer require apntalk/esl-react
```

Repository-local development note:

- The publishable dependency intent remains `apntalk/esl-core ^0.2`.
- For sibling-repo workspace installs, this repository uses a Composer `path` repository with a local version override for `../esl-core` so development stays practical without publishing a misleading `dev-main as ...` dependency requirement.

---

## Quick start

```php
<?php

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\RetryPolicy;

$config = RuntimeConfig::create(
    host: '127.0.0.1',
    port: 8021,
    password: 'ClueCon',
    retryPolicy: RetryPolicy::default(),
);

$client = AsyncEslRuntime::make($config);

$client->connect()->then(function () use ($client) {
    echo "Connected and authenticated.\n";
});

\React\EventLoop\Loop::run();
```

Pass an explicit loop if you are integrating into an existing application:

```php
$loop = \React\EventLoop\Loop::get();
$client = AsyncEslRuntime::make($config, $loop);
```

---

## Async command dispatch

### api command

`api` commands are dispatched serially. The returned promise resolves with the reply when FreeSWITCH responds.

If `api()` is called before successful `connect()`, it rejects with `ConnectionException`.

```php
$client->api('status')->then(
    function (\Apntalk\EslCore\Replies\ApiReply $reply) {
        echo $reply->body();
    },
    function (\Throwable $e) {
        echo "Command failed: " . $e->getMessage();
    }
);
```

### bgapi command

`bgapi` commands return a `BgapiJobHandle` immediately. The job's promise resolves when the `BACKGROUND_JOB` completion event arrives.

```php
$handle = $client->bgapi('originate', 'sofia/internal/1000 &echo');

$handle->promise()->then(
    function (\Apntalk\EslCore\Events\BackgroundJobEvent $event) {
        echo "Job complete: " . $event->result();
    },
    function (\Throwable $e) {
        echo "Job failed: " . $e->getMessage();
    }
);
```

Timeout behavior and reconnect behavior for pending bgapi jobs are documented in [docs/bgapi-tracking.md](docs/bgapi-tracking.md).

`bgapi()` remains provisional in this repository pass and is not yet covered by the fake-server integration suite.

---

## Event listeners

### Typed event listener

```php
$client->events()->onEvent(
    \Apntalk\EslCore\Model\Event\ChannelAnswerEvent::class,
    function (\Apntalk\EslCore\Model\Event\ChannelAnswerEvent $event) {
        echo "Channel answered: " . $event->getUniqueId();
    }
);
```

### Raw envelope listener

```php
$client->events()->onRawEnvelope(
    function (\Apntalk\EslCore\Model\EventEnvelope $envelope) {
        // receives every inbound envelope before typed dispatch
    }
);
```

### Unknown event listener

```php
$client->events()->onUnknown(
    function (\Apntalk\EslCore\Model\Event\RawEvent $event) {
        echo "Unknown event type: " . $event->getEventName();
    }
);
```

Listener ordering guarantees and exception policy are documented in [docs/async-model.md](docs/async-model.md).

---

## Subscriptions

```php
$client->subscriptions()->subscribe('CHANNEL_ANSWER');
$client->subscriptions()->subscribe('BACKGROUND_JOB');

// Subscribe to all events
$client->subscriptions()->subscribeAll();
```

Active subscriptions are restored automatically after reconnect. See [docs/reconnect-model.md](docs/reconnect-model.md).

---

## Reconnect model

`esl-react` supervises reconnection automatically. The retry schedule is configured via `RetryPolicy`:

```php
$retry = new \Apntalk\EslReact\Config\RetryPolicy(
    maxAttempts: 10,
    initialDelayMs: 500,
    backoffMultiplier: 2.0,
    maxDelayMs: 30_000,
);

// Disable reconnect entirely:
$retry = RetryPolicy::disabled();
```

On disconnect, inflight `api` commands are rejected with `ConnectionLostException`. Pending `bgapi` jobs survive reconnect and are matched when their completion events arrive. Subscriptions and filters are restored by the `ResubscriptionPlanner`.

Full behavior is documented in [docs/reconnect-model.md](docs/reconnect-model.md).

---

## Health model

```php
$snapshot = $client->health()->snapshot();

echo $snapshot->connectionState->value;       // e.g. "Authenticated"
echo $snapshot->isLive ? 'live' : 'degraded';
echo $snapshot->inflightCommandCount;
echo $snapshot->pendingBgapiJobCount;
echo $snapshot->reconnectAttempts;
```

Health fields and their meaning are documented in [docs/health-model.md](docs/health-model.md).

---

## Stability policy

This package is pre-1.0. The public API is not yet frozen.

**Stable public surface (will not break within a minor version):**

- `AsyncEslClientInterface`, `EventStreamInterface`, `SubscriptionManagerInterface`, `HealthReporterInterface`
- Config objects: `RuntimeConfig`, `RetryPolicy`, `HeartbeatConfig`, `BackpressureConfig`, `SubscriptionConfig`, `CommandTimeoutConfig`
- Read models and DTOs: `HealthSnapshot`, `ConnectionState`, `SessionState`, `RuntimeState`, `BgapiJobHandle`
- Entry point: `AsyncEslRuntime::make()`
- Documented exceptions

**Internal (subject to change without notice):** everything else — supervisor internals, heartbeat internals, frame readers/writers, router internals, replay capture internals, correlation registries.

Consumers should import only stable public types. See [docs/stability-policy.md](docs/stability-policy.md) for the full policy.

---

## Documentation

- [Architecture](docs/architecture.md)
- [Public API reference](docs/public-api.md)
- [Async model](docs/async-model.md)
- [Runtime lifecycle](docs/runtime-lifecycle.md)
- [Reconnect model](docs/reconnect-model.md)
- [Health model](docs/health-model.md)
- [BGAPI tracking](docs/bgapi-tracking.md)
- [Replay hooks](docs/replay-hooks.md)
- [Stability policy](docs/stability-policy.md)

---

## License

MIT
