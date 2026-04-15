# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Release-oriented smoke test covering the documented happy path: public runtime construction, startup subscription/filter seeding, connect/auth, one successful `api()` call, one delivered event, and clean shutdown
- Bounded chaos integration coverage for controlled runtime reactions to unexpected disconnect, post-success malformed traffic, and replay-sink failure during reconnect-era traffic
- Phased hardening coverage for protocol edges, reconnect lifecycle stress, burst/backpressure correctness, and short soak-style runtime confidence
- Opt-in direct live FreeSWITCH compatibility harness for package-owned connect/auth, `api()`, and clean-shutdown verification
- Opt-in direct live FreeSWITCH event receipt harness for package-owned subscription and public event-stream verification
- Opt-in direct live FreeSWITCH `bgapi()` happy-path harness for package-owned ack/completion verification
- Opt-in staging/lab manual live reconnect recovery harness for package-owned disconnect observation, reconnect recovery, desired-state restore, and post-reconnect event proof
- Opt-in staging/lab manual live reconnect + post-reconnect `api()` harness for package-owned active command-path recovery proof after manual disruption
- Test-bootstrap-only `.env.live.local` / `.env.testing.local` loading for local opt-in live harness variables without overriding shell-provided env
- Executable runtime entry point via `AsyncEslRuntime::make()`
- Concrete connect/auth lifecycle implementation with ReactPHP socket wiring
- Deterministic fake ESL server for connect/auth and basic `api()` integration coverage
- Initial PHPUnit contract, unit, and integration suites
- Live typed event-stream coverage for known events, raw event envelopes, unknown events, and listener-failure containment
- Live-session subscription and filter control with deterministic fake-server coverage
- Reconnect supervision for unexpected disconnects and transient connect failures
- Desired subscription/filter restoration after successful reconnect re-authentication
- Minimal heartbeat/liveness wiring into health snapshots and disconnect recovery
- Fake-server reconnect/heartbeat integration coverage for restore order, bounded retry, and health transitions
- Deterministic bgapi integration coverage for ack, completion correlation, orphan timeout, and reconnect-era pending-job behavior
- Deterministic backpressure/drain integration coverage for overload rejection, bounded drain shutdown, and health-state exposure
- Deterministic replay-hook integration coverage for command dispatch/reply, bgapi ack/completion, inbound events, reconnect-era capture continuity, disabled capture, and sink-failure containment
- Deterministic replay regression coverage for subscription/filter mutation capture, mutation rejection paths, malformed inbound traffic, heartbeat-driven reconnect, and drain-era pending bgapi behavior
- Companion design note for a future replay package that would own durable storage, restart recovery, and replay execution
- Contract-level coverage for startup subscription seeding, bounded drain semantics, bgapi handle ack behavior, and stable replay artifact identity fields
- Focused integration coverage for configured startup subscription/filter seeding, reconnect-era restore from seeded desired state, event bursts, partial-frame event parsing, and replay-sink failure across reconnect continuity

### Changed
- Package metadata and CI now align with the actual supported PHP floor (`^8.3`) inherited from the current `apntalk/esl-core` line
- README install/requirements notes now describe the public package truth only and no longer imply unpublished local path-repository behavior
- `AsyncEslClientInterface` now explicitly declares `connect()`, matching the documented public contract
- Local workspace dependency handling now keeps the publishable requirement at `apntalk/esl-core ^0.2` while using a path-repository version override for sibling-repo installs
- `connect()`/`api()`/`disconnect()` failure semantics are now hardened and covered for timeout, malformed-handshake, invalid-state, and inflight-disconnect paths
- Event-stream docs now distinguish real typed-event behavior from later subscription/reconnect work
- Subscription/filter mutations now use an explicit caller-owned baseline, idempotent desired-state tracking, and authenticated-only live-session gating
- Unexpected disconnect now transitions through reconnect/recovery states, while explicit `disconnect()` remains terminal for the runtime instance
- Recovery now re-authenticates first, restores the desired event baseline, restores filters, and only then returns to the live state
- `api()` and subscription/filter mutations now fail closed while reconnect recovery is in progress
- `bgapi()` now returns a real tracked handle whose Job-UUID appears after ack, resolves only on matching `BACKGROUND_JOB`, survives unexpected supervised reconnect, and rejects on explicit shutdown or orphan timeout
- Overload is now an explicit runtime-wide accepted-work threshold covering `api()`, `bgapi()`, and live-session subscription/filter mutations
- `disconnect()` now enters bounded drain mode, rejects new work immediately, allows accepted inflight work to settle up to `drainTimeoutSeconds`, then terminates remaining work deterministically before closing terminally
- Health snapshots now expose total inflight work and overload state in addition to drain state
- Replay capture is now a real observational runtime slice: capture is explicitly configurable, emits replay envelopes for command/event/bgapi paths, survives supervised reconnect for later traffic, and contains sink failures without crashing the live runtime
- Replay capture now has a stable artifact vocabulary and versioned metadata contract for the currently supported runtime-owned paths, including accepted subscription/filter mutations
- Heartbeat/liveness now explicitly uses a bounded two-step model: first miss degrades and may issue one safe probe, second consecutive miss forces a recoverable close
- `RuntimeConfig::$subscriptions` is now applied as real startup desired-state seeding instead of being an inert public config surface
- Public docs now de-scope `RuntimeState` from the stable consumer surface and describe the actual `RuntimeClient`-centered implementation instead of plan-era internal component names

### Documentation
- README, public API reference, and runtime lifecycle docs now distinguish implemented lifecycle guarantees from later planned runtime behavior
- Public contracts: AsyncEslClientInterface, EventStreamInterface, SubscriptionManagerInterface, HealthReporterInterface
- Config objects: RuntimeConfig, RetryPolicy, HeartbeatConfig, BackpressureConfig, SubscriptionConfig, CommandTimeoutConfig
- Read models: HealthSnapshot, ConnectionState, SessionState, BgapiJobHandle
- FakeEslServer test infrastructure for deterministic CI without a live PBX
- docs/async-model.md: explicit async contract, timeout, cancellation, ordering, listener policy
- docs/runtime-lifecycle.md: connection/session state machine
- docs/stability-policy.md: public API stability boundaries
