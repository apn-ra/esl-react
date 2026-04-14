# Revised Implementation Plan for `apntalk/esl-react`

## 1. Purpose

Build `apntalk/esl-react` as a **ReactPHP-native inbound FreeSWITCH ESL runtime for PHP**.

This package exists to make `apntalk/esl-core` usable in long-lived live runtime scenarios by providing:

- live connection management
- async command dispatch
- async event streaming
- bgapi dispatch and completion tracking
- reconnect supervision
- heartbeat and liveness monitoring
- backpressure and drain behavior
- runtime health snapshots
- replay-safe runtime hook emission

This package is **inbound-client runtime only** for v1.x. It is not responsible for outbound ESL server behavior.

It should **not** include:

- Laravel integration
- database-backed PBX registry
- multi-PBX orchestration policy
- app-specific telephony normalization rules
- durable replay persistence
- cluster leadership or ownership policy

That keeps the product boundary clear:

- `apntalk/esl-core` → protocol substrate
- `apntalk/esl-react` → async runtime
- `apntalk/laravel-freeswitch-esl` → Laravel integration and control plane

---

## 2. Product goals

### Primary goals
- Turn `apntalk/esl-core` into a usable live runtime.
- Support long-lived ESL sessions safely.
- Provide a small stable async API.
- Make reconnect and resubscription first-class.
- Expose operational state for worker-oriented systems.

### Secondary goals
- Make most behavior testable without a live PBX.
- Keep the public API narrow enough to stabilize before 1.0.
- Keep future Laravel integration straightforward.

### Non-goals
- Full durable replay engine
- Telephony business logic
- Campaign routing logic
- Multi-node ownership logic
- Persistent storage
- Outbound ESL server support

---

## 3. Architectural boundaries

### 3.1 Upstream dependency
`apntalk/esl-react` depends on `apntalk/esl-core` for:

- protocol parsing
- serialization
- typed command and reply models
- typed event models
- normalized event models
- correlation and session metadata primitives
- replay envelope primitives

### 3.2 Responsibilities owned by `apntalk/esl-react`
- socket lifecycle
- async read and write pumps
- auth and session lifecycle
- async command dispatch
- bgapi tracking
- event routing and listener delivery
- reconnect logic
- heartbeat and liveness monitoring
- backpressure and drain behavior
- runtime replay hook emission
- runtime health snapshots

### 3.3 Responsibilities explicitly deferred
- Laravel service provider
- application container integration
- secrets from app persistence
- database-backed PBX registry
- cross-node routing policy
- health persistence
- durable replay storage
- business-rule event interpretation

---

## 4. Target package structure

```text
apntalk/esl-react/
  composer.json
  README.md
  CHANGELOG.md
  LICENSE
  docs/
    architecture.md
    public-api.md
    async-model.md
    runtime-lifecycle.md
    reconnect-model.md
    health-model.md
    bgapi-tracking.md
    replay-hooks.md
    stability-policy.md
  examples/
    connect-and-listen.php
    async-api-command.php
    bgapi-job-tracking.php
    supervised-client.php
  src/
    Contracts/
    Config/
    Runtime/
    Connection/
    Session/
    Protocol/
    CommandBus/
    Events/
    Subscription/
    Bgapi/
    Supervisor/
    Heartbeat/
    Health/
    Backpressure/
    Replay/
    Support/
    Exceptions/
  tests/
    Unit/
    Contract/
    Integration/
    FakeServer/
    Fixtures/
```

---

## 5. Public API design

Keep the public API **smaller than the internal architecture**.

### 5.1 Stable public contracts
The stable public surface for pre-1.0 should be limited to:

- `AsyncEslClientInterface`
- `EventStreamInterface`
- `SubscriptionManagerInterface`
- `HealthReporterInterface`

### 5.2 Public entry point
- `AsyncEslRuntime::make(RuntimeConfig $config): AsyncEslClientInterface`

### 5.3 Public config objects
- `RuntimeConfig`
- `RetryPolicy`
- `HeartbeatConfig`
- `BackpressureConfig`
- `SubscriptionConfig`
- `CommandTimeoutConfig`

### 5.4 Public read models / DTOs
- `HealthSnapshot`
- `ConnectionState`
- `SessionState`
- `RuntimeState`
- `BgapiJobHandle` or equivalent tracked-handle DTO

### 5.5 Internal by default
The following should exist as implementation components but **not** be treated as stable public contracts unless a strong need emerges:

- connection supervisor internals
- heartbeat monitor internals
- backpressure controller internals
- correlation registries
- frame readers and writers
- reconnect scheduler internals
- router internals
- replay capture internals

### 5.6 Stability rule
Only contracts, config objects, entry points, and documented DTOs should be imported by consumers. Everything else is internal and may change until 1.0.

---

## 6. Async model

This must be explicit early.

### 6.1 Command model
- `api` and `bgapi` dispatch are asynchronous.
- Command operations resolve through a documented ReactPHP-compatible async contract.
- Timeout behavior is part of the public contract.
- Cancellation behavior must be explicitly documented as supported or unsupported.

### 6.2 Event model
The runtime must expose:

- raw envelope stream
- typed event stream
- unknown-event path

### 6.3 Listener isolation
Listener failures must not crash the runtime. The package must define:

- whether listener exceptions are swallowed, surfaced, or both
- whether listener execution is ordered
- whether slow listeners can backpressure the stream
- whether per-listener isolation can relax strict global delivery timing

### 6.4 Ordering guarantee
Document the event ordering guarantee clearly:

- inbound socket order at router boundary
- dispatch behavior to listeners
- any loss of strict ordering caused by listener isolation

---

## 7. Phase-by-phase implementation

## Phase 1 — Repository foundation

### Objective
Create the package repo and baseline tooling.

### Deliverables
- `composer.json`
- PSR-4 autoloading
- PHPUnit setup
- PHPStan setup
- code style config
- CI workflow
- base README
- changelog
- docs skeleton
- stability-policy doc shell

### Acceptance criteria
- package installs cleanly
- CI runs
- static analysis runs
- empty test suite passes

---

## Phase 2 — Runtime contracts and config objects

### Objective
Define stable contracts and config objects before runtime behavior.

### Deliverables
Contracts for:
- async client
- event stream
- subscription manager
- health reporter

Typed config objects for:
- runtime
- retry
- heartbeat
- subscriptions
- backpressure
- command timeout

### Required design decisions in this phase
- async result contract
- timeout model
- cancellation policy
- event listener policy
- ordering guarantee
- public versus internal type boundary

### Acceptance criteria
- all public contracts compile
- config objects are immutable
- guards and validation are test-covered
- async-model doc is drafted, even if still marked provisional

---

## Phase 3 — Fake server foundation

### Objective
Create a minimal deterministic fake ESL server early.

### Components
- `FakeEslServer`
- `FakeConnectionScenario`
- scripted auth flow
- scripted reply flow
- scripted event injection

### Why this phase is early
The fake server is required to shape connection lifecycle, reply correlation, malformed-input handling, and reconnect behavior before those behaviors harden into unstable internals.

### Acceptance criteria
- fake server can simulate connect/auth success
- fake server can simulate auth failure
- fake server can send scripted replies
- fake server can emit scripted events
- CI can run core lifecycle tests without a live PBX

---

## Phase 4 — Connection and session lifecycle

### Objective
Implement socket connection and authentication lifecycle.

### Components
- `ReactSocketConnector`
- `ConnectionFactory`
- `AsyncConnection`
- `ConnectionState`
- `SessionAuthenticator`
- `SessionLifecycle`
- `SessionState`
- `SessionMetadata`

### Features
- connect
- authenticate
- disconnect
- state transitions
- connection/session metadata

### Acceptance criteria
- successful socket connection
- successful auth handshake
- clean disconnect path
- invalid auth fails predictably
- session state transitions are test-covered

---

## Phase 5 — Protocol runtime loop

### Objective
Build the live read/write pipeline around `apntalk/esl-core`.

### Components
- `FrameReader`
- `FrameWriter`
- `EnvelopePump`
- `InboundMessageRouter`
- `OutboundMessageDispatcher`

### Features
- read raw frames from socket
- write commands to socket
- classify inbound messages
- route replies vs events vs bgapi completions
- protect against malformed inbound data

### Acceptance criteria
- inbound frames parsed correctly
- outbound frames written correctly
- router classifies messages correctly
- malformed frames do not crash the runtime
- fake-server scenarios cover malformed and partial input cases

---

## Phase 6 — Async command bus

### Objective
Support async `api` dispatch and correlated reply handling.

### Components
- `AsyncCommandBus`
- `PendingCommand`
- `CommandTimeoutRegistry`
- `CommandCorrelationMap`

### Features
- async `api`
- timeout handling
- multiple inflight commands
- reply correlation
- predictable failure behavior

### Acceptance criteria
- multiple commands can be inflight safely
- correct reply matches correct request
- timed-out commands resolve as failures
- cancellation behavior is implemented or explicitly unsupported and documented

---

## Phase 7 — Typed event stream

### Objective
Expose runtime events using typed models from `apntalk/esl-core`.

### Components
- `EventStream`
- `TypedEventEmitter`
- `UnknownEventHandler`
- `EventDispatchContext`

### Features
- raw envelope listener
- typed event listener
- unknown event listener
- safe dispatch to multiple listeners

### Acceptance criteria
- typed events are emitted correctly
- raw envelope stream remains available
- unknown events are surfaced without crashing the runtime
- listener exception policy is documented and tested
- ordering guarantee is documented and tested

---

## Phase 8 — Subscription and filter management

### Objective
Make subscriptions reconnect-safe and explicit.

### Components
- `SubscriptionManager`
- `ActiveSubscriptionSet`
- `FilterManager`
- `ResubscriptionPlanner`

### Features
- subscribe
- unsubscribe
- filter add/remove
- restore active subscriptions after reconnect

### Acceptance criteria
- active subscription state is preserved
- reconnect restores subscriptions and filters
- duplicate subscription operations are safe
- default subscription baseline is documented

---

## Phase 9 — Reconnect supervision and runtime health

### Objective
Make the runtime durable and observable for long-lived sessions.

### Components
- `ConnectionSupervisor`
- `ReconnectScheduler`
- `CircuitState`
- `DisconnectClassifier`
- `HeartbeatMonitor`
- `IdleTimer`
- `LivenessState`
- `RuntimeHealthReporter`
- `HealthSnapshot`

### Features
- reconnect with retry/backoff
- disconnect classification
- heartbeat/liveness checks
- degraded/recovering/live states
- runtime health snapshots
- supervisor start/stop lifecycle

### Acceptance criteria
- reconnect works after disconnect
- heartbeat failure transitions state correctly
- resubscribe occurs after reconnect
- repeated failures do not spin uncontrollably
- health snapshot is available during normal and degraded states

---

## Phase 10 — BGAPI support

### Objective
Implement async `bgapi` dispatch and completion tracking.

### Components
- `BgapiDispatcher`
- `BgapiJobTracker`
- `PendingBgapiJob`
- `BgapiCompletionMatcher`

### Features
- dispatch `bgapi`
- return tracked job handles
- correlate completion events
- handle late completion
- define orphan and timeout strategy
- define reconnect behavior for pending jobs

### Acceptance criteria
- bgapi returns a tracked handle
- completion matches the correct job
- missing completion is handled cleanly
- reconnect behavior for pending jobs is documented and tested

---

## Phase 11 — Backpressure and drain control

### Objective
Protect the runtime under load and allow graceful shutdown.

### Components
- `BackpressureController`
- `InflightCounter`
- `BufferPolicy`
- `PauseResumeGate`

### Features
- inflight counting
- overload thresholds
- pause/resume behavior
- drain mode
- graceful shutdown

### Required policy decisions
- what gets throttled
- what gets rejected
- what gets buffered
- whether any events may be dropped
- how replay capture behaves under pressure

### Acceptance criteria
- inflight state is observable
- thresholds are enforced
- drain mode stops accepting new work and exits cleanly
- overload policy is documented and tested

---

## Phase 12 — Replay-safe runtime hooks

### Objective
Emit replay-safe runtime artifacts without becoming a replay engine.

### Components
- `RuntimeReplayCapture`
- `ReplayEnvelopeFactory`
- `ReplayDispatchContext`

### Features
- capture replay envelopes during live runtime
- include connection/session metadata
- emit raw and typed capture hooks
- enable/disable replay capture

### Acceptance criteria
- replay hooks fire on command, reply, and event paths
- capture payloads contain required correlation/runtime metadata
- capture can be disabled without side effects

---

## Phase 13 — Hardening expansion

### Objective
Expand fake-server and integration coverage around failure-heavy scenarios.

### Expanded scenarios
- delayed replies
- event bursts
- bgapi completion and orphan cases
- disconnect during inflight command
- malformed frames
- reconnect and resubscribe
- heartbeat timeout
- drain while inflight
- backpressure escalation
- duplicate or late completion paths

### Acceptance criteria
- fake server covers major lifecycle paths
- reconnect and correlation regressions are protected
- most CI coverage does not require a live PBX

---

## 8. Milestone release plan

## Milestone 0.1.0
Foundation and connection lifecycle

Includes:
- repo foundation
- contracts and config
- fake server foundation
- connect/auth/disconnect

## Milestone 0.2.0
Protocol loop and async command bus

Includes:
- protocol runtime loop
- async `api`
- reply correlation
- timeout handling

## Milestone 0.3.0
Typed event stream

Includes:
- typed event emission
- raw envelope stream
- unknown event handling
- listener policy documentation

## Milestone 0.4.0
Subscriptions and reconnect-safe restore

Includes:
- subscriptions
- filters
- resubscription planning
- default subscription model

## Milestone 0.5.0
Reconnect, heartbeat, and health

Includes:
- supervisor
- reconnect logic
- heartbeat monitoring
- health snapshots
- degraded/recovering/live states

## Milestone 0.6.0
BGAPI tracking

Includes:
- bgapi dispatch
- tracked job handles
- completion matching
- reconnect behavior for pending jobs

## Milestone 0.7.0
Backpressure and drain

Includes:
- inflight counting
- overload policy
- drain mode
- graceful shutdown

## Milestone 0.8.0
Replay-safe runtime hooks

Includes:
- replay envelope hooks
- runtime replay metadata
- capture enable/disable

## Milestone 0.9.0
Hardening

Includes:
- expanded fake-server scenarios
- reconnect regression coverage
- malformed input resilience
- edge-case bgapi completion coverage

## Milestone 1.0.0
Stable async runtime

Criteria:
- public API frozen
- reconnect behavior stable
- event dispatch guarantees documented and reliable
- bgapi behavior stable
- health model stable
- replay hook API stable enough for consumers
- fake-server coverage strong enough for regression protection

---

## 9. Testing plan

## Unit tests
Cover:
- config objects
- retry policy
- timeout registry
- correlation map
- heartbeat logic
- backpressure logic
- health snapshot building
- replay capture toggles

## Contract tests
Cover:
- behavior promised by public interfaces
- entrypoint construction
- runtime state transitions
- async command guarantees
- event ordering and listener policy
- subscription restore behavior

## Integration tests
Cover:
- connect/auth
- `api`
- event flow
- `bgapi`
- reconnect/resubscribe
- health transitions

## Fake-server tests
Cover:
- delayed replies
- malformed frames
- event bursts
- disconnects
- reconnects
- heartbeat timeout
- drain mode
- backpressure escalation
- late bgapi completion

---

## 10. Documentation plan

Create these docs:

- `architecture.md`
- `public-api.md`
- `async-model.md`
- `runtime-lifecycle.md`
- `reconnect-model.md`
- `health-model.md`
- `bgapi-tracking.md`
- `replay-hooks.md`
- `stability-policy.md`

README should include:
- package purpose
- relationship to `apntalk/esl-core`
- explicit inbound-only scope
- what the package does not do
- install
- quick start
- async command example
- event listener example
- reconnect model
- health model
- stability policy

---

## 11. Risks and controls

## Risk 1 — Public API becomes too wide
If too many subsystem interfaces are made public, the package will be difficult to stabilize.

### Control
Keep the stable public surface limited to façade contracts, config objects, and documented DTOs.

## Risk 2 — Async semantics are implicit
If timeout, cancellation, ordering, and listener isolation are not decided early, the implementation will harden around accidental behavior.

### Control
Define the async model in Phase 2 and test it as contract behavior.

## Risk 3 — Reconnect logic becomes unstable
Reconnect and resubscription are where these runtimes usually fail.

### Control
Introduce the fake server early and isolate supervisor logic from command and event internals.

## Risk 4 — Package grows into framework code
If Laravel concerns or registry logic leak in, boundaries become messy.

### Control
Reject framework-specific features in this repo.

## Risk 5 — Replay scope expands too far
Replay can become a product of its own.

### Control
Limit this repo to replay-safe hook emission, not durable replay execution.

## Risk 6 — Backpressure policy becomes undefined
Under load, hidden buffering or undefined loss behavior will create operational surprises.

### Control
Treat overload policy as a documented product behavior, not an implementation detail.

---

## 12. Success criteria

The package is successful when it can:

- connect and authenticate reliably
- send async commands and correlate replies correctly
- dispatch typed events from live runtime traffic
- restore subscriptions predictably after reconnect
- expose health and liveness state clearly
- track bgapi jobs reliably
- enter drain mode and stop safely
- apply backpressure according to documented policy
- emit replay-safe runtime hooks
- pass regression tests through a fake ESL server harness

---

## 13. Strong implementation recommendation

Build in this order:

1. contracts and config
2. minimal fake server
3. connect/auth lifecycle
4. protocol runtime loop
5. async command bus
6. typed event stream
7. subscriptions
8. reconnect + heartbeat + health
9. bgapi tracking
10. backpressure and drain
11. replay hooks
12. hardening expansion

That order gives you a usable runtime early, while shaping the difficult behaviors with a deterministic harness before they become expensive to change.
