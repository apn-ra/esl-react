# apntalk/esl-react

ReactPHP-native inbound FreeSWITCH ESL runtime for PHP.

This package turns `apntalk/esl-core` into a usable long-lived async runtime: it manages socket connections, authenticates ESL sessions, dispatches async commands, streams typed events, supervises reconnection, monitors liveness, and exposes operational health — all within the ReactPHP event loop.

Current implementation status:

- Implemented and test-covered: runtime construction, connect/auth lifecycle, inbound frame pump, serial `api()` dispatch, live typed event streaming, raw event-envelope delivery, unknown-event handling, live-session subscription/filter control, reconnect supervision after unexpected disconnect, desired-state restore after re-authentication, tracked `bgapi()`, explicit backpressure rejection, bounded drain shutdown, health snapshots, and deterministic fake-server integration tests.
- Implemented and contract-stabilized: replay-safe runtime hook emission for supported runtime paths.
- Present but still minimal relative to the plan: heartbeat orchestration beyond the current liveness probe and recover-on-silence behavior.
- `connect()` is idempotent while a connection attempt is already in progress and resolves immediately when already authenticated.
- `api()` is rejected before successful authentication.
- The current connect/auth handshake timeout reuses `CommandTimeoutConfig::$apiTimeoutSeconds`.
- `disconnect()` now enters bounded drain mode: new work is rejected immediately, already-accepted work may settle until the configured drain timeout, remaining inflight work is then terminated deterministically, and the runtime closes terminally without reconnecting.

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

- PHP 8.3 or higher
- `react/event-loop` ^1.5
- `react/promise` ^3.2
- `react/socket` ^1.16
- `apntalk/esl-core` ^0.2

---

## Installation

```bash
composer require apntalk/esl-react
```

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

`bgapi` commands return a `BgapiJobHandle` immediately. The handle becomes correlated once FreeSWITCH acknowledges the command with a `Job-UUID`, and the handle's promise resolves only when the matching `BACKGROUND_JOB` completion event arrives.

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

Implemented bgapi contract in the current slice:

- `bgapi()` fails closed before authentication and during reconnect recovery.
- The handle is returned synchronously; `jobUuid()` is empty until the bgapi acceptance reply arrives.
- Ack timeout and completion timeout are distinct: missing ack and missing completion reject the handle through different stages of the same promise lifecycle.
- Late completion after timeout is ignored deterministically.
- Pending bgapi jobs survive unexpected supervised reconnect and can still resolve on a later matching completion.
- Explicit `disconnect()` is terminal for pending bgapi jobs and rejects them instead of waiting for later completion.

### Backpressure and drain

Current backpressure/drain contract:

- Inflight work means accepted `api()` command-bus work plus pending `bgapi()` handles.
- When the configured inflight threshold is reached, new `api()`, `bgapi()`, and live-session subscription/filter mutations are rejected deterministically with `BackpressureException`.
- `disconnect()` enters drain mode and rejects new `api()`, `bgapi()`, and subscription/filter mutations with `DrainException`.
- Accepted inflight work may finish until `BackpressureConfig::$drainTimeoutSeconds` expires.
- When the drain deadline expires, remaining inflight work is rejected deterministically with `DrainException`, then the runtime closes terminally.

### Replay hooks

Replay capture is explicit and observational only.

- Disabled capture has no side effects on runtime behavior.
- Enabled capture emits `ReplayEnvelopeInterface` artifacts to configured `ReplayCaptureSinkInterface` sinks.
- Stable artifact names in the current contract are:
  - `api.dispatch`
  - `api.reply`
  - `bgapi.dispatch`
  - `bgapi.ack`
  - `bgapi.complete`
  - `command.reply`
  - `event.raw`
  - `subscription.mutate`
  - `filter.mutate`
- Deterministic no-op subscription/filter mutations and rejected work emit nothing.
- Sink failures are contained and do not crash the runtime.
- Replay capture is not storage, playback, or process-restart recovery.
- Unexpected reconnect preserves capture for later runtime traffic, but there is no durable persistence across process restart.

See [docs/replay-hooks.md](docs/replay-hooks.md) for the current replay-hook contract.
See [docs/replay-companion-package.md](docs/replay-companion-package.md) for the recommended future package boundary for durable storage and replay execution.

---

## Event listeners

### Typed event listener

```php
$client->events()->onEvent(
    'CHANNEL_ANSWER',
    function (\Apntalk\EslCore\Events\ChannelLifecycleEvent $event) {
        echo "Channel answered: " . $event->uniqueId();
    }
);
```

### Raw envelope listener

```php
$client->events()->onRawEnvelope(
    function (\Apntalk\EslCore\Correlation\EventEnvelope $envelope) {
        // receives every inbound event envelope before typed/unknown dispatch
    }
);
```

### Unknown event listener

```php
$client->events()->onUnknown(
    function (\Apntalk\EslCore\Events\RawEvent $event) {
        echo "Unknown event type: " . $event->eventName();
    }
);
```

Current event-stream contract:

- Inbound event frames are delivered in socket order.
- Raw envelope listeners run first for each event frame.
- Known event names are surfaced as typed `esl-core` models.
- Unknown but well-formed events are surfaced via `onUnknown()` as `RawEvent`.
- Listener exceptions are contained and do not stop other listeners or crash the runtime.

Listener ordering guarantees and exception policy are documented in [docs/async-model.md](docs/async-model.md).

---

## Subscriptions

```php
$client->subscriptions()->subscribe('CHANNEL_ANSWER');
$client->subscriptions()->subscribe('BACKGROUND_JOB');

// Subscribe to all events
$client->subscriptions()->subscribeAll();
```

Current subscription/filter contract:

- The baseline is explicit and caller-owned. The runtime does not silently subscribe to a broad event set for application code.
- `RuntimeConfig::$subscriptions` seeds the runtime's initial desired event/filter state before the first successful connect/auth cycle.
- Subscription and filter mutations are rejected before successful authentication and after disconnect.
- The runtime tracks desired active subscriptions and filters in memory and restores them after a successful reconnect.
- Duplicate subscribe/filter-add operations are idempotent no-ops.
- Unsubscribing an inactive event name or removing a missing filter is a no-op.
- `subscribeAll()` is supported, but unsubscribing specific names while subscribed to all is rejected in the current implementation because this phase does not model "all except X".
- While the runtime is reconnecting, `api()` and subscription/filter mutations fail closed with `ConnectionException`.

---

## Reconnect model

`esl-react` supervises reconnection automatically. The retry schedule is configured via `RetryPolicy`:

```php
$retry = RetryPolicy::withMaxAttempts(10, 0.5);

// Disable reconnect entirely:
$retry = RetryPolicy::disabled();
```

Implemented reconnect contract in the current slice:

- Unexpected socket close triggers bounded reconnect attempts according to `RetryPolicy`.
- Explicit `disconnect()` does not trigger reconnect.
- Authentication rejection does not trigger reconnect.
- Handshake timeout and malformed handshake traffic remain fail-closed and do not enter retry.
- After successful re-authentication, the runtime restores `subscribeAll()` or the named event set first, then restores filters, then transitions back to `Authenticated`/live.
- Inflight `api()` commands are rejected with `ConnectionLostException` when the connection drops.
- New `api()` calls and subscription/filter mutations are rejected while reconnect is in progress.

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

Current liveness note:

- When heartbeat monitoring is enabled, any inbound frame records activity.
- If the connection goes idle past the configured window, health degrades (`isLive = false`) and the runtime issues a lightweight `api status` probe when safe.
- If the liveness window expires again without recovery, the runtime closes the socket and falls into the normal disconnect/reconnect path.
- This is a minimal heartbeat/liveness integration, not yet a broader orchestration layer.

---

## Stability policy

This package is pre-1.0. The public API is not yet frozen.

**Stable public surface (will not break within a minor version):**

- `AsyncEslClientInterface`, `EventStreamInterface`, `SubscriptionManagerInterface`, `HealthReporterInterface`
- Config objects: `RuntimeConfig`, `RetryPolicy`, `HeartbeatConfig`, `BackpressureConfig`, `SubscriptionConfig`, `CommandTimeoutConfig`
- Read models and DTOs: `HealthSnapshot`, `ConnectionState`, `SessionState`, `BgapiJobHandle`
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

## Live compatibility harness

The default test suite uses a deterministic fake ESL server and does not require a live PBX.

For opt-in package-owned realism checks, `tests/Integration/LiveRuntimeCompatibilityTest.php` can connect `apntalk/esl-react` itself to a real FreeSWITCH inbound ESL target:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeCompatibilityTest.php
```

The live harness verifies direct connect/auth, one read-only `api()` command (`status` by default), and clean shutdown. It is skipped unless explicitly enabled and is not a replacement for the deterministic fake-server suite.

For local development, you may place these variables in an untracked `.env.live.local` or `.env.testing.local` file. PHPUnit loads only `ESL_REACT_LIVE_*` keys from those files via `tests/bootstrap.php`; already-exported shell variables take precedence. Use `.env.live.example` as a placeholder template and keep real credentials local.

An additional opt-in live event receipt harness is available when a safe event source is expected:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_EVENT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_EVENT_NAME=HEARTBEAT \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeEventCompatibilityTest.php
```

The event harness subscribes through the public subscription API and observes the event through the public raw event stream. It defaults to observing a natural `HEARTBEAT` event and may wait up to `ESL_REACT_LIVE_EVENT_TIMEOUT` seconds (`25` by default). If your environment requires a harmless trigger command, set `ESL_REACT_LIVE_EVENT_TRIGGER_API` explicitly.

An additional opt-in live `bgapi()` harness is available for one safe happy-path background job:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_BGAPI_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_BGAPI_COMMAND=status \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeBgapiCompatibilityTest.php
```

It verifies direct connect/auth, real `bgapi()` ack/job UUID acquisition, real completion, and clean shutdown. It is intentionally minimal and should use only low-risk commands.

For staging/lab-only manual reconnect recovery proof, an additional opt-in harness is available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_MANUAL_RECONNECT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_EVENT_NAME=HEARTBEAT \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeManualReconnectRecoveryTest.php
```

This harness is intentionally manual. It connects, subscribes through the public API, prints operator instructions, waits for a real external connectivity disruption, then observes reconnect, desired-state recovery, one post-reconnect event, and clean shutdown. It does not automate network changes and should only be used in a staging/lab environment where manual disruption is approved.

For staging/lab-only manual reconnect proof that also verifies the active command path after recovery, an additional opt-in harness is available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_MANUAL_RECONNECT_API_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_EVENT_NAME=HEARTBEAT \
ESL_REACT_LIVE_POST_RECONNECT_API_COMMAND=status \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeManualReconnectApiRecoveryTest.php
```

This harness follows the same manual disruption flow, then waits for a post-reconnect event and issues one safe read-only `api()` command (`status` by default) after recovery. It proves that the active command path is alive again without automating any network change.

For staging/lab-only manual reconnect proof that also verifies the async job path after recovery, an additional opt-in harness is available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_MANUAL_RECONNECT_BGAPI_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_EVENT_NAME=HEARTBEAT \
ESL_REACT_LIVE_POST_RECONNECT_BGAPI_COMMAND=status \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeManualReconnectBgapiRecoveryTest.php
```

This harness follows the same manual disruption flow, restores both the normal event subscription and `BACKGROUND_JOB`, then issues one low-risk `bgapi()` command (`status` by default) after reconnect. It verifies real ack/Job-UUID acquisition, real `BACKGROUND_JOB` completion, and clean shutdown without automating any network change.

---

## License

MIT
