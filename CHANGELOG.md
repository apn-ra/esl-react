# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Executable runtime entry point via `AsyncEslRuntime::make()`
- Concrete connect/auth lifecycle implementation with ReactPHP socket wiring
- Deterministic fake ESL server for connect/auth and basic `api()` integration coverage
- Initial PHPUnit contract, unit, and integration suites
- Live typed event-stream coverage for known events, raw event envelopes, unknown events, and listener-failure containment
- Live-session subscription and filter control with deterministic fake-server coverage

### Changed
- `AsyncEslClientInterface` now explicitly declares `connect()`, matching the documented public contract
- Local workspace dependency handling now keeps the publishable requirement at `apntalk/esl-core ^0.2` while using a path-repository version override for sibling-repo installs
- `connect()`/`api()`/`disconnect()` failure semantics are now hardened and covered for timeout, malformed-handshake, invalid-state, and inflight-disconnect paths
- Event-stream docs now distinguish real typed-event behavior from later subscription/reconnect work
- Subscription/filter mutations now use an explicit caller-owned baseline, idempotent desired-state tracking, and authenticated-only live-session gating

### Documentation
- README, public API reference, and runtime lifecycle docs now distinguish implemented lifecycle guarantees from later planned runtime behavior
- Public contracts: AsyncEslClientInterface, EventStreamInterface, SubscriptionManagerInterface, HealthReporterInterface
- Config objects: RuntimeConfig, RetryPolicy, HeartbeatConfig, BackpressureConfig, SubscriptionConfig, CommandTimeoutConfig
- Read models: HealthSnapshot, ConnectionState, SessionState, RuntimeState, BgapiJobHandle
- FakeEslServer test infrastructure for deterministic CI without a live PBX
- docs/async-model.md: explicit async contract, timeout, cancellation, ordering, listener policy
- docs/runtime-lifecycle.md: connection/session state machine
- docs/stability-policy.md: public API stability boundaries
