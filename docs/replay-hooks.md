# Replay hooks

Replay hooks are implemented and test-covered for the currently supported runtime paths.

The artifact contract in this document is the supported replay-hook contract for `apntalk/esl-react` within the current package line. Future additions should be additive rather than renaming the artifact vocabulary below.

## Purpose

`apntalk/esl-react` can emit replay-safe runtime artifacts during live operation.

This is an **observational hook layer only**:

- no durable storage
- no replay execution
- no process-restart recovery
- no command queueing
- no control-plane ownership over runtime behavior

The runtime continues to own socket lifecycle, command flow, reconnect, and drain semantics. Replay capture only observes those paths.

## Enabling capture

Replay capture is disabled by default.

Enable it through `RuntimeConfig` with one or more `ReplayCaptureSinkInterface` sinks:

```php
use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslReact\Config\RuntimeConfig;

$config = RuntimeConfig::create(
    host: '127.0.0.1',
    port: 8021,
    password: 'ClueCon',
    replayCaptureEnabled: true,
    replayCaptureSinks: [
        new class () implements ReplayCaptureSinkInterface {
            public function capture(\Apntalk\EslCore\Contracts\ReplayEnvelopeInterface $envelope): void
            {
                // lightweight sink logic only
            }
        },
    ],
);
```

Current configuration rules:

- `replayCaptureEnabled: false` means no hooks fire, even if sinks are configured.
- `replayCaptureEnabled: true` requires at least one sink.
- Enabling capture does not imply persistence, buffering, or deferred replay behavior.

## Artifact shape

Artifacts are emitted as `ReplayEnvelopeInterface` values from `apntalk/esl-core`.

For reply and event traffic, `esl-react` uses the existing `ReplayEnvelopeFactory` substrate and adds runtime metadata needed to identify the capture point.

For dispatch-intent artifacts, `esl-react` emits replay envelopes directly using the same stable envelope shape because there is no protocol reply/event object yet at that point.

### Required stable fields

Every emitted artifact contains:

- the standard `ReplayEnvelopeInterface` fields from `apntalk/esl-core`
- `replay-artifact-version`
- `replay-artifact-name`
- `runtime-capture-path`
- runtime connection/session/liveness metadata
- runtime connection generation
- runtime reconnect attempt count
- runtime draining/overloaded flags

Current version:

- `replay-artifact-version = 1`

### Optional fields

Depending on the capture point, artifacts may also contain:

- command name / command args
- mutation kind
- desired-state before / after snapshots
- filter header name / header value
- `Job-UUID`
- protocol sequence
- event-specific protocol facts preserved by `esl-core`

## Implemented capture points

The current runtime emits capture artifacts for:

1. `api()` dispatch intent after the runtime accepts the call: `api.dispatch`
2. `bgapi()` dispatch intent after the runtime accepts the call: `bgapi.dispatch`
3. `api()` reply receipt: `api.reply`
4. other command replies received on the live runtime path: `command.reply`
5. inbound event receipt on the live event path: `event.raw`
6. bgapi ack after the `Job-UUID` is assigned: `bgapi.ack`
7. bgapi completion after a matching `BACKGROUND_JOB`: `bgapi.complete`
8. accepted subscription mutation commands: `subscription.mutate`
9. accepted filter mutation commands: `filter.mutate`

Important distinctions:

- A `BACKGROUND_JOB` event can produce two artifacts:
  - the generic inbound event artifact (`replay-artifact-name = event.raw`)
  - the matched bgapi completion artifact (`replay-artifact-name = bgapi.complete`)
- A bgapi ack can also produce two artifacts:
  - the generic command reply artifact (`replay-artifact-name = command.reply`)
  - the bgapi-ack artifact (`replay-artifact-name = bgapi.ack`)
- Rejected operations do not emit dispatch artifacts. If overload, drain, recovery-state gating, or unauthenticated-state gating rejects a call, no replay capture is produced for that rejected attempt.
- Deterministic no-op subscription/filter mutations also emit nothing, because no live-session mutation command is sent.

For compatibility, `runtime-capture-path` currently matches `replay-artifact-name`.

## Metadata currently included

Each artifact includes as much metadata as is truly available at the capture point.

Currently emitted metadata includes:

- capture timestamp and capture sequence from the replay envelope
- runtime session ID
- `runtime-capture-path`
- `replay-artifact-version`
- `replay-artifact-name`
- runtime connection state
- runtime session state
- runtime liveness state
- runtime reconnect-attempt count
- runtime connection generation within the live runtime instance
- runtime draining / overloaded flags
- command type / command name / command args when captured on a dispatch or bgapi-specific path
- `Job-UUID` when available
- protocol facts already preserved by `esl-core` for replies and events

The exact metadata set depends on the capture point. For example:

- dispatch intent has command metadata but no protocol sequence
- generic reply capture has reply protocol facts
- inbound event capture has event name and protocol sequence
- bgapi completion includes both event facts and the matched job UUID
- subscription/filter mutation artifacts include a JSON payload describing the accepted intent and desired-state transition

## Failure containment

Replay sinks run synchronously on the event loop.

Current policy:

- sink failures are caught and contained inside replay capture
- the live runtime continues processing replies, events, reconnect, and drain transitions
- one failing sink does not stop later sinks from receiving the same artifact
- the current implementation writes a short stderr message for sink exceptions

No stable public replay-error callback is exposed in this phase.

## Interaction with reconnect, overload, and drain

Current tested behavior:

- replay capture continues to observe later runtime traffic after an unexpected supervised reconnect
- reconnect does not reconstruct traffic that was already lost before capture
- overload and drain rejections still fail closed; replay hooks do not bypass those gates
- accepted inflight work can still produce reply/event/completion capture during drain until it settles or is terminated
- explicit drain shutdown remains terminal for the runtime instance and does not reconnect

## What replay hooks are not

- not a persistence adapter
- not a replay engine
- not a playback API
- not a job/workflow orchestrator
- not a replacement for the runtime’s health or reconnect policy

If you need durable recording or replay execution, implement that in an upper-layer package using the emitted envelopes.
