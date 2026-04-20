# apntalk/esl-react

ReactPHP-native inbound FreeSWITCH ESL runtime for PHP.

This package turns `apntalk/esl-core` into a usable long-lived async runtime: it manages socket connections, authenticates ESL sessions, dispatches async commands, streams typed events, supervises reconnection, monitors liveness, and exposes operational health — all within the ReactPHP event loop.

Current implementation status:

- Implemented and test-covered: runtime construction, connect/auth lifecycle, inbound frame pump, serial `api()` dispatch, live typed event streaming, raw event-envelope delivery, unknown-event handling, live-session subscription/filter control, reconnect supervision after unexpected disconnect, desired-state restore after re-authentication, tracked `bgapi()`, explicit backpressure rejection, bounded drain shutdown, health snapshots, and deterministic fake-server integration tests.
- Implemented and contract-stabilized: replay-safe runtime hook emission for supported runtime paths.
- Implemented and test-covered in the current runner milestones: a narrow prepared-input runner seam plus a richer prepared-bootstrap input path that can carry prepared ReactPHP transport access, prepared ingress pipeline access, and runtime-local session context.
- Implemented and test-covered for truthful runtime substrate export: runner feedback/status now also surface queue posture, accepted-work identity, recovery generation identity, reconstruction posture, replay continuity posture, recent terminal-publication facts, and recent lifecycle-semantic observations using `apntalk/esl-core` vocabulary.
- Implemented and test-covered for higher-layer observation: runner lifecycle snapshots, exportable runner status snapshots, and push-based lifecycle callbacks that expose startup state, connection/session health, liveness, reconnecting, drain, and failure truth without giving downstream packages runtime ownership. Recent runtime-owned connect/disconnect/failure timestamps and bounded cause summaries live on the status snapshot surface, not on the coarse lifecycle callback.
- The runner observation contract is intentionally split: `lifecycleSnapshot()` / `onLifecycleChange()` stay coarse and stable for lifecycle observation, while `feedbackSnapshot()` and `statusSnapshot()` carry the richer reconnect/backoff timing, terminal-stop, and disconnect/failure-cause detail.
- Present but still minimal relative to the plan: heartbeat orchestration beyond the current liveness probe and recover-on-silence behavior.
- `connect()` is idempotent while a connection attempt is already in progress and resolves immediately when already authenticated.
- `api()` is rejected before successful authentication.
- The current connect/auth handshake timeout reuses `CommandTimeoutConfig::$apiTimeoutSeconds`, fails closed, stops autonomous reconnect for that startup attempt, and surfaces `handshake_timeout` on the runner reconnect/status snapshots.
- `disconnect()` now enters bounded drain mode: new work is rejected immediately, already-accepted work may settle until the configured drain timeout, remaining inflight work is then terminated deterministically, and the runtime closes terminally without reconnecting.

Release note and tag-prep summary for the next patch release:
[docs/release-prep-v0.2.13.md](docs/release-prep-v0.2.13.md)

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
- `apntalk/esl-core` ^0.2.13
- `ext-dom` is required transitively by `apntalk/esl-core` ^0.2.10+

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

## Prepared runner seam

The first runner milestone adds a narrow adapter-friendly seam for higher layers
that already own runtime preparation.

```php
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;

$input = new PreparedRuntimeInput(
    endpoint: 'tcp://127.0.0.1:8021',
    runtimeConfig: $config,
);

$handle = AsyncEslRuntime::runner()->run($input, $loop);

$handle->startupPromise()->then(function () use ($handle) {
    $client = $handle->client();
    echo $handle->state()->value; // running
});
```

Current runner truth:

- The runner consumes `esl-react` owned prepared input and starts the live runtime immediately via `connect()`.
- The coarse runner startup lifecycle is `starting -> running` or `starting -> failed`.
- The returned handle exposes `lifecycleSnapshot()` as the preferred read-only higher-layer observation seam for startup state, connection/session health, liveness, reconnecting, drain, and failure truth.
- The returned handle exposes `feedbackSnapshot()` as the preferred stable reporting seam for prepared runtime identity plus drain, inflight, subscription, and retry feedback.
- The returned handle exposes `statusSnapshot()` as the preferred exportable live-runtime status seam for downstream readiness/liveness linkage and persisted status feeds.
- The returned handle also exposes `onLifecycleChange()` for push-based lifecycle observation without polling.
- Ongoing runtime lifecycle remains visible through the stable client health model (`ConnectionState`, `SessionState`, `HealthSnapshot`).
- `PreparedRuntimeInput` preserves the config-driven path for simple adapters.
- `PreparedRuntimeBootstrapInput` supports a richer handoff with a prepared ReactPHP `ConnectorInterface`, prepared `InboundPipelineInterface`, and runtime-local `RuntimeSessionContext`.
- `PreparedRuntimeBootstrapInput` can also carry an explicit prepared dial target URI, so higher layers may reuse the prepared connector path for non-default schemes such as `tls://...` without moving runtime ownership out of `esl-react`.
- `PreparedRuntimeBootstrapInput` can also inject replay capture explicitly for the prepared runner handoff, reusing the stable `ReplayCaptureSinkInterface` contract from `apntalk/esl-core`.
- `PreparedRuntimeBootstrapInput` can also carry bounded prepared recovery truth through `PreparedRuntimeRecoveryContext`, allowing higher layers to hand off recovery-generation identity and reconstruction posture without making `esl-react` own storage or replay execution.
- The prepared connector is used for live startup and reconnect attempts. This lets higher layers prepare transport access without making `esl-react` own their control plane.
- The prepared pipeline is accepted, reset at handoff, and then reused as the live inbound decode path for startup and reconnect attempts on that runtime instance.
- Direct polling of `apntalk/esl-core` `TransportInterface` and full replacement of the live ingress router with `InboundPipelineInterface` remain deferred.

Lifecycle observer notes:

- `onLifecycleChange()` invokes listeners immediately with the current `RuntimeLifecycleSnapshot`.
- Later callbacks are emitted when coarse lifecycle truth changes, using the same snapshot shape as `lifecycleSnapshot()`.
- Listener callbacks run synchronously in registration order, and listener exceptions are contained so they do not destabilize the runtime.
- Explicit drain and unexpected transport-loss reconnect remain distinct on this surface: drain emits `Draining -> Closed`, while unexpected loss emits `Reconnecting` before any later recovery or exhaustion and does not emit a misleading shutdown-style drain marker first.

Richer prepared-bootstrap example:

```php
use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Vocabulary\ReconstructionPosture;
use Apntalk\EslCore\Vocabulary\RecoveryGenerationId;
use Apntalk\EslCore\Vocabulary\ReplayContinuity;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeRecoveryContext;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use React\Socket\Connector;

$input = new PreparedRuntimeBootstrapInput(
    endpoint: 'tcp://127.0.0.1:8021',
    runtimeConfig: $config,
    connector: new Connector([], $loop),
    inboundPipeline: new InboundPipeline(),
    sessionContext: new RuntimeSessionContext(
        sessionId: 'runtime-session-123',
        metadata: ['pbx_node' => 'node-a'],
        workerSessionId: 'worker-session-123',
        connectionProfile: 'primary-pbx',
    ),
    recoveryContext: new PreparedRuntimeRecoveryContext(
        generationId: RecoveryGenerationId::fromString('prepared-generation-7'),
        reconstructionPosture: ReconstructionPosture::HookRequired,
        replayContinuity: ReplayContinuity::Reconstructed,
        metadata: ['source' => 'fixture-bootstrap'],
    ),
    dialUri: 'tls://pbx.example.test:7443',
    replayCaptureSinksOverride: [
        new class () implements ReplayCaptureSinkInterface {
            public function capture(\Apntalk\EslCore\Contracts\ReplayEnvelopeInterface $envelope): void
            {
                // lightweight runtime-owned capture only
            }
        },
    ],
);

$handle = AsyncEslRuntime::runner()->run($input, $loop);
$feedback = $handle->feedbackSnapshot();
$recovery = $feedback->recovery;
$operations = $feedback->activeOperations;
$publications = $feedback->recentTerminalPublications;
```

### Runner feedback quick reference

`RuntimeRunnerHandle::feedbackSnapshot()` is the stable release-facing read
model for downstream health/reporting adapters that need more than the raw
`HealthSnapshot`.

```php
$feedback = $handle->feedbackSnapshot();

$desired = $feedback->subscriptionState();
$observed = $feedback->observedSubscriptionState();
$reconnect = $feedback->reconnectState();

if ($reconnect->isTerminallyStopped) {
    // conservative runtime-known terminal truth
    $reason = $reconnect->terminalStopReason?->value;
}
```

Safe consumption rules:

- treat `subscriptionState()` as exact in-memory desired subscription/filter intent
- treat `observedSubscriptionState()` as conservative current-session applied truth after successful local command replies, not as a deeper server receipt ledger
- treat `reconnectState()->nextRetryDueAtMicros` and `remainingDelaySeconds` as local scheduler packaging that may drift slightly with event-loop latency
- treat `reconnectState()->terminalStoppedDurationSeconds` as derived local elapsed time, not a persisted transition timestamp
- treat `reconnectState()->terminalStopReason` as a bounded runtime-known or policy-derived category, not a general transport diagnostics framework
- when `subscribeAll` is active, prefer `subscriptionState()->subscribeAll` or `observedSubscriptionState()->subscribeAll` over `activeSubscriptions()`, because the event-name list intentionally stays empty in that mode
- treat `recovery->generationId`, `reconstructionPosture`, and `replayContinuity` as bounded runtime-owned truth only; they do not claim durable process-restart recovery unless an upper layer supplied explicit prepared context
- treat `activeOperations` as exact accepted-work identity for the current runtime instance only
- treat `recentTerminalPublications` and `recentLifecycleSemantics` as bounded recent history for downstream export or persistence, not as a durable replay corpus

### Runner status quick reference

`RuntimeRunnerHandle::statusSnapshot()` is the stable release-facing status
read model for downstream packages that need a truthful exported runtime status
feed without reconstructing reconnect/session truth themselves.

```php
$status = $handle->statusSnapshot();

if ($status->isRecoveryInProgress) {
    // reconnect/backoff or session-restore is underway
}

$export = $status->toArray();
```

Safe consumption rules:

- treat `phase` as a coarse packaged lifecycle phase, not as a second control-plane state machine
- treat `isRuntimeActive` as truth about this runtime instance still being active, not as proof that the outer ReactPHP process/event loop is alive
- treat `lastSuccessfulConnectAtMicros` and `lastDisconnectAtMicros` as exact runtime-recorded local timestamps
- treat `lastDisconnectReasonClass` and `lastDisconnectReasonMessage` as optional bounded local observation; clean closes may leave them `null`
- treat `lastFailure*` fields as the most recent runtime-recorded failure summary only; they are not a durable incident log
- use `toArray()` / `jsonSerialize()` only for observational export or persistence owned by downstream packages, not as a cross-process supervision guarantee

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

Implemented `api()` timeout posture in the current slice:

- if the reply does not arrive before `apiTimeoutSeconds`, the promise rejects with `CommandTimeoutException`
- that timeout is treated as a fail-closed reply-correlation failure for the current connection
- the runtime closes the compromised connection instead of continuing normal `api()` flow on the same reply slot
- any late reply that arrives on that compromised session is ignored
- later command work resumes only after the runtime has re-established a clean connection boundary

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
- Subscription and filter mutations reject their returned promises before successful authentication, during reconnect recovery, during drain, and after disconnect.
- Normal runtime gating failures on these promise-returning methods do not leak synchronous throws in normal use; promise consumers can rely on `then(null, $onError)`.
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

An additional opt-in live runner liveness harness is available for validating
the public runner observation seam during heartbeat degradation:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_LIVENESS_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerLivenessCompatibilityTest.php
```

It uses short heartbeat settings against a relatively quiet live target and
asserts `live -> degraded/not-live -> live` on the public runner lifecycle
surface without reporting false reconnect or drain. This remains an opt-in lab
validation path because the exact degradation window depends on the target's
ambient inbound traffic.

For labs that can safely make the ESL session go silent without immediately
tearing down the TCP path, an additional opt-in live heartbeat dead/reconnect
harness is available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_HEARTBEAT_RECONNECT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_HEARTBEAT_DEADPATH_DISRUPT_COMMAND='./scripts/pause-esl-target.sh' \
ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RESTORE_COMMAND='./scripts/unpause-esl-target.sh' \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerHeartbeatReconnectCompatibilityTest.php
```

This harness asserts the deeper bounded heartbeat path on the public runner
surface: `Authenticated/live -> Authenticated/not-live -> Reconnecting/not-live
-> Authenticated/live`, while also verifying that heartbeat failure is not
misreported as drain. It remains opt-in because the target must support a safe
pause/resume-style disruption that leaves the connection silent long enough for
the second miss to occur.

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

An additional opt-in live runner lifecycle harness is available for the public runner and observation surfaces consumed by higher layers:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerLifecycleCompatibilityTest.php
```

It verifies the config-driven runner seam, immediate lifecycle observation
registration, authenticated live startup, explicit drain-to-closed shutdown on
a real FreeSWITCH target, and the additive runner feedback/status recovery
surfaces that are truthful on that path: generation identity, idle retry
posture, drain posture/outcome, and the absence of spurious lifecycle-semantic
or terminal-publication history when no runtime-owned work has occurred.

For labs that can safely automate transport disruption and restoration, an additional opt-in live runner reconnect harness is available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_RECONNECT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_RECONNECT_DISRUPT_COMMAND='./scripts/disrupt-esl-path.sh' \
ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND='./scripts/restore-esl-path.sh' \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerReconnectCompatibilityTest.php
```

This harness runs the public runner seam, subscribes through the public API,
executes the configured disrupt/restore commands, and asserts that snapshot
plus push-based lifecycle observation surfaces report unexpected transport loss
as reconnecting rather than draining before later recovery to authenticated/live
truth. It also validates additive runner recovery truth on that live path:
retry posture progression, generation rollover, gap-detected replay continuity,
and bounded reconnect outcome metadata. This reconnect path has been exercised
successfully against a real FreeSWITCH target in an opt-in lab environment. It
remains intentionally opt-in and requires target-specific commands that are
safe, bounded, and idempotent in your lab environment.

An additional opt-in live runner bgapi/event harness is available when a safe
event source and one low-risk background job command are available:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_NAME=HEARTBEAT \
ESL_REACT_LIVE_RUNNER_BGAPI_COMMAND=msleep \
ESL_REACT_LIVE_RUNNER_BGAPI_ARGS=1000 \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerBgapiEventCompatibilityTest.php
```

This harness starts through the public runner seam, subscribes to the configured
event and `BACKGROUND_JOB`, observes one live event through the public raw
event stream, runs one safe `bgapi()` command, waits for its real ack and
completion, and asserts that snapshot plus push-based lifecycle observation
remain `Authenticated`/`Active`/live without false reconnect, drain, closed, or
failed markers during the activity. It also validates additive accepted-work
tracking and terminal-publication export for a real bgapi operation: one active
operation while the job is pending, then a bounded recent terminal publication
after completion. It has been exercised successfully against a real FreeSWITCH
target in an opt-in lab environment. The default command for this harness is a
short `msleep` window rather than `status`, because the accepted-work proof
needs a real pending interval that can be observed before completion.

An opt-in live runner reconnect + bgapi/event harness is available for labs
that can safely automate transport disruption/restoration while also expecting
a safe live event source and one low-risk background job command:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_RECONNECT_DISRUPT_COMMAND='./scripts/disrupt-esl-path.sh' \
ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND='./scripts/restore-esl-path.sh' \
ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_NAME=HEARTBEAT \
ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_COMMAND=status \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerReconnectBgapiEventCompatibilityTest.php
```

This harness starts through the public runner seam, subscribes to the configured
event plus `BACKGROUND_JOB`, observes a pre-fault live event, executes the
configured disrupt/restore commands, asserts reconnect/no-drain lifecycle truth
and desired subscription restoration, then observes a post-reconnect live event
and completes one safe `bgapi()` job. It is intentionally opt-in because the
disrupt/restore commands are target-specific lab controls.

Combined-condition runner coverage remains broader deterministically: the
fake-server suite covers pending `bgapi()` plus desired event subscriptions
through unexpected reconnect, and pending `bgapi()` while heartbeat liveness
degrades and recovers. Those tests assert no false drain, fail-closed new work
while reconnecting, restored event flow after reconnect, and matching snapshot
plus pushed lifecycle truth.

An additional opt-in live runner pending-`bgapi()` reconnect harness is
available for labs that can keep one safe background job genuinely pending while
the ESL listener reconnects:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RECONNECT_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_COMMAND=msleep \
ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_ARGS=15000 \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerPendingBgapiReconnectCompatibilityTest.php
```

By default this harness force-closes the runner transport locally without
calling the public `disconnect()` path, so the live FreeSWITCH target keeps
processing the bgapi job while the runtime observes an unexpected reconnect.
That makes it possible to prove a real pending `bgapi()` handle crossing the
reconnect boundary: pending before fault, reconnecting/not-live without false
drain, pending still tracked after recovery, and the original handle resolving
later on `BACKGROUND_JOB` completion.

This remains intentionally opt-in and lab-scoped. If your environment cannot
use the default controlled transport-close fault, the harness also accepts a
custom external disrupt command or a separate ESL-control fault command such as
`reload mod_event_socket`. The still-deferred gap after this milestone is any
broader external live fault injection beyond this one pending-job reconnect
path.

An additional opt-in live runner lifecycle-semantic harness is available for
labs that can safely generate one supported channel lifecycle event:

```bash
ESL_REACT_LIVE_TEST=1 \
ESL_REACT_LIVE_RUNNER_SEMANTIC_TEST=1 \
ESL_REACT_LIVE_HOST=127.0.0.1 \
ESL_REACT_LIVE_PORT=8021 \
ESL_REACT_LIVE_PASSWORD=ClueCon \
ESL_REACT_LIVE_RUNNER_SEMANTIC_EVENT_NAME=CHANNEL_HANGUP_COMPLETE \
vendor/bin/phpunit --no-coverage tests/Integration/LiveRuntimeRunnerLifecycleSemanticCompatibilityTest.php
```

This harness is intentionally narrower than the generic event harness. It only
accepts semantic transitions the runtime can truthfully infer today:
`CHANNEL_BRIDGE`, `CHANNEL_TRANSFER`, `CHANNEL_HOLD`, `CHANNEL_UNHOLD`,
`CHANNEL_RESUME`, `CHANNEL_HANGUP_COMPLETE`, or `CHANNEL_DESTROY`. When the lab
can safely emit one of those events, the harness validates the exported recent
lifecycle-semantic history and, for terminal events, the corresponding bounded
recent terminal-publication history. It does not attempt to manufacture channel
activity or claim broader semantic certainty when the environment cannot safely
produce one of those events.

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
