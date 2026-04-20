# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

- No unreleased changes documented yet.

## [0.2.11] - 2026-04-20

Release prep note for this patch:
[docs/release-prep-v0.2.11.md](docs/release-prep-v0.2.11.md)

### Added
- Additive prepared-bootstrap replay-capture input contract so higher layers can inject runtime replay sinks on the runner seam without ad hoc `RuntimeConfig` wiring
- Stable runner feedback snapshot packaging existing health truth with prepared runtime identity for downstream health/reporting integrations
- Stable runner status snapshot packaging runtime-owned lifecycle/reconnect truth plus recent connect/disconnect/failure observations for downstream status export
- Clearer bounded runtime/session identity propagation via named `RuntimeSessionContext` fields plus generic scalar metadata reused by runner feedback and prepared-bootstrap replay metadata

### Changed
- Runtime dependency now targets `apntalk/esl-core ^0.2.13`; the compatibility pass keeps `esl-react` on its existing ReactPHP runtime seams while aligning the inbound reply router with the `v0.2.13` public classified-message contract and revalidating replay-envelope compatibility
- `api()` timeout handling is now fail-closed: once a synchronous api reply times out, the runtime treats reply correlation on that connection as ambiguous, rejects new api work on the compromised session, ignores late replies, and closes the connection to avoid late-reply cross-wiring into later commands
- Subscription and filter mutation methods now reject their returned promises consistently for live-session gating failures and the current `subscribeAll()`-specific unsubscribe limitation, so promise-chain consumers do not have to defend against synchronous throws on those paths
- Runtime feedback semantics are now more explicit: runner feedback distinguishes exact desired subscription/filter state, exact reconnect retry scheduling truth, and exact command-bus active vs queued API counts without changing existing runtime behavior
- Runner status semantics are now explicit: the new export seam distinguishes exact runtime-recorded connect/disconnect/failure timestamps from optional bounded disconnect-cause detail, and does not over-claim process-level loop liveness or cross-process supervision
- Runtime feedback now also distinguishes exact desired subscription/filter state from conservative locally observed-applied state for the current authenticated session, with explicit invalidation on reconnect and rebuild after restore completes
- Runtime feedback now also exposes additive reconnect/backoff detail, including exact reconnect phase and attempt/delay truth plus conservative local next-due and remaining-delay timing estimates when a retry timer is pending
- Runtime feedback now also exposes additive terminal reconnect-stop truth, including exact terminal-stop/exhaustion booleans plus conservative runtime-known stop categories such as explicit shutdown, retry exhaustion/disabled retry, auth rejection, and handshake failure
- Runtime feedback now also retains additive terminal reconnect timing context, including exact terminal-stop and retry-attempt timestamps plus last retained scheduler timing, while keeping elapsed-stop duration explicitly derived from local wall-clock packaging
- Release-facing docs and public-contract coverage now package the accumulated runner feedback and status surfaces more explicitly, including desired vs observed subscription wording, exact vs approximate vs derived reconnect timing semantics, handshake-timeout plus handshake-protocol-failure terminal stop truth, preserved bounded disconnect-cause detail, and the intentional coarse lifecycle-callback vs richer feedback/status contract split
- Deterministic hardening coverage now proves late orphaned `bgapi()` completion remains a no-op after timeout instead of reviving settled state

## [0.2.7] - 2026-04-17

### Added
- Opt-in live runner pending-`bgapi()` reconnect harness for proving that one genuinely pending bgapi job can stay tracked across a reconnect boundary and still resolve on the original handle after recovery
- Live validation coverage for pending-job truth before disruption, reconnect/no-drain lifecycle observation during the fault, pending job survival across recovery, and post-reconnect `BACKGROUND_JOB` completion on the public runner seam

### Changed
- Release-facing docs now distinguish the validated live pending-`bgapi()` reconnect path from the still-deferred broader external fault-injection space
- The default live pending-`bgapi()` harness now uses a controlled unexpected transport close on the runner connection, while keeping optional external fault hooks available for lab-specific experiments

## [0.2.6] - 2026-04-17

### Added
- Opt-in live runner reconnect + bgapi/event combined-condition harness for labs that can safely automate transport disruption/restoration while observing live event delivery and one safe `bgapi()` job on the same public runner handle
- Live validation coverage for pre-fault event delivery, reconnect/no-drain lifecycle truth, desired subscription restoration, post-reconnect event delivery, post-reconnect `bgapi()` ack/completion, and snapshot/push lifecycle consistency across the same runner seam

### Changed
- Release-facing docs now distinguish deterministic pending-`bgapi()` combined-condition proof from the narrower live reconnect + bgapi/event combined-condition harness
- Public API, lifecycle, and stability docs now explicitly defer live pending-`bgapi()` in-flight reconnect fault injection unless a lab provides a safe long-running background job command

## [0.2.5] - 2026-04-17

### Added
- Opt-in live runner bgapi/event harness for validating public runner lifecycle truth while receiving one real FreeSWITCH event and completing one safe `bgapi()` job
- Deterministic runner-surface coverage for pending `bgapi()` plus desired event subscriptions across unexpected reconnect, including fail-closed new work while reconnecting, restored event flow, and post-reconnect `BACKGROUND_JOB` completion
- Deterministic runner-surface coverage for pending `bgapi()` while heartbeat liveness degrades and recovers without false reconnect or drain observation

### Changed
- Public runner docs now distinguish healthy live bgapi/event validation from deterministic combined-condition validation, and explicitly defer live combined-condition fault injection
- Runtime lifecycle docs now clarify that pending bgapi/event combined conditions keep degraded-not-live and reconnect states distinct from explicit drain

## [0.2.4] - 2026-04-17

### Added
- Additive prepared dial-target runner input contract so richer prepared-bootstrap handoff can direct the prepared connector to non-default URIs, including TLS-style dial targets, for both startup and reconnect attempts without requiring direct `apntalk/esl-core` transport handoff
- `RuntimeRunnerHandle::onLifecycleChange()` as a stable push-based companion to `lifecycleSnapshot()` for higher-layer lifecycle observation without polling
- Opt-in live FreeSWITCH runner lifecycle harness for public runner startup, authenticated/live observation, explicit drain, and terminal closed-state verification
- Opt-in automated live runner reconnect harness for public runner reconnect observation and recovery verification when the lab environment provides safe disrupt/restore commands
- Deterministic runner-surface coverage for heartbeat first-miss liveness degradation and probe-driven recovery without false reconnect or drain observation
- Opt-in live runner liveness harness for validating `Authenticated/live -> Authenticated/not-live -> Authenticated/live` observation on quiet targets
- Deterministic runner-surface coverage for the second-miss heartbeat dead/reconnect path without false drain observation
- Opt-in live runner heartbeat dead/reconnect harness for validating `Authenticated/live -> Authenticated/not-live -> Reconnecting/not-live -> Authenticated/live` against lab targets that can be safely paused and resumed

### Changed
- Public runner docs now describe the live-verified milestone truthfully: runner startup/drain/closed behavior and automated reconnect recovery on the public runner seam have both been exercised against a real FreeSWITCH target in an opt-in lab environment
- Runtime lifecycle docs now distinguish first-miss liveness degradation from the deeper second-miss heartbeat dead/reconnect path on both snapshot reads and push-based lifecycle callbacks

## [0.2.0] - 2026-04-17

### Added
- Runner lifecycle snapshots via `RuntimeRunnerHandle::lifecycleSnapshot()`, giving higher-layer packages a read-only observation surface for startup state, connection/session health, liveness, reconnecting, drain, and failure truth without taking runtime ownership
- Release-oriented smoke test covering the documented happy path: public runtime construction, startup subscription/filter seeding, connect/auth, one successful `api()` call, one delivered event, and clean shutdown
- Bounded chaos integration coverage for controlled runtime reactions to unexpected disconnect, post-success malformed traffic, and replay-sink failure during reconnect-era traffic
- Phased hardening coverage for protocol edges, reconnect lifecycle stress, burst/backpressure correctness, and short soak-style runtime confidence
- Opt-in direct live FreeSWITCH compatibility harness for package-owned connect/auth, `api()`, and clean-shutdown verification
- Opt-in direct live FreeSWITCH event receipt harness for package-owned subscription and public event-stream verification
- Opt-in direct live FreeSWITCH `bgapi()` happy-path harness for package-owned ack/completion verification
- Opt-in staging/lab manual live reconnect recovery harness for package-owned disconnect observation, reconnect recovery, desired-state restore, and post-reconnect event proof
- Opt-in staging/lab manual live reconnect + post-reconnect `api()` harness for package-owned active command-path recovery proof after manual disruption
- Opt-in staging/lab manual live reconnect + post-reconnect `bgapi()` harness for package-owned async job-path recovery proof after manual disruption
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
