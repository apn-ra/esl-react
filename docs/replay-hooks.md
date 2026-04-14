# Replay hooks

> **Provisional.** This feature is marked `@provisional` and is subject to change before 1.0. The interface described here reflects the current design intent but may be revised.

---

## Purpose

`esl-react` can emit replay-safe capture hooks during a live runtime session. These hooks capture the inbound and outbound messages exchanged with FreeSWITCH, enriched with connection and session metadata, and deliver them to a consumer-supplied sink.

This is a **hook emission layer only**. `esl-react` does not store data, does not serialize to disk, does not manage replay playback, and does not own any persistence mechanism. Storage and playback are the responsibility of the consuming layer (e.g., `laravel-freeswitch-esl`).

---

## Enabling replay capture

Replay capture is disabled by default. Enable it in `RuntimeConfig`:

```php
$config = new \Apntalk\EslReact\Config\RuntimeConfig(
    // ...
    replayCaptureEnabled: true,
    replayCaptureSink: new MyReplayCaptureSinkImplementation(),
);
```

When `replayCaptureEnabled` is `false`, no capture hooks fire and there is no performance overhead.

---

## ReplayEnvelope

Captured artifacts are emitted as `ReplayEnvelope` objects, defined in `apntalk/esl-core`. Each `ReplayEnvelope` contains:

- The raw `EventEnvelope` or serialized command/reply payload
- Connection metadata (host, port, connection ID)
- Session metadata (session ID, auth timestamp)
- A capture timestamp (microseconds)
- A capture path identifier (command reply, event, bgapi completion)

The `ReplayEnvelope` type is owned by `esl-core` and is the canonical capture artifact format. `esl-react` constructs envelopes via `ReplayEnvelopeFactory` and emits them; it does not define the format.

---

## Capture paths

There are three capture paths:

### Command reply path

Fires when an `api` command reply is received and correlated to a pending command. The capture includes the outbound command and the inbound reply.

### Event path

Fires for every inbound event envelope, after routing and before typed dispatch. The capture includes the raw envelope with full headers.

### BGAPI completion path

Fires when a `BACKGROUND_JOB` completion event is matched to a pending bgapi job. The capture includes the completion event envelope and the original job UUID.

---

## ReplayCaptureSinkInterface

Consumers implement this interface to receive capture artifacts:

```php
interface ReplayCaptureSinkInterface
{
    public function capture(\Apntalk\EslCore\Model\Replay\ReplayEnvelope $envelope): void;
}
```

The `capture()` method is called synchronously within the event loop for each artifact. Implementations must not block the loop. Heavy I/O (disk writes, network calls) should be offloaded using `Loop::futureTick()` or a queue.

If `capture()` throws an exception, the exception is caught by the runtime and passed to the `listenerErrorHandler`. It does not crash the runtime or interrupt event delivery.

---

## Behavior under backpressure

When the runtime is under backpressure or in drain mode, replay capture continues to fire for events that are still being processed. Replay capture does not itself apply backpressure, and the sink is responsible for handling high-throughput scenarios. If the sink cannot keep up, it should buffer or drop internally according to its own policy.

---

## What replay hooks are NOT

- Not a durable replay engine: `esl-react` has no storage
- Not a playback mechanism: `esl-react` cannot replay captured artifacts
- Not a recording mode: capture hooks are fire-and-forget from the runtime's perspective
- Not a debugging proxy: `esl-react` does not intercept or modify traffic for capture purposes

If you need durable replay, implement a `ReplayCaptureSinkInterface` that writes to a database or message queue. If you need playback, that belongs in `laravel-freeswitch-esl` or application code, using `FakeEslServer` or a purpose-built replay harness.

---

## Provisional status

The replay hook API is marked `@provisional` because:

- The `ReplayEnvelope` format in `esl-core` may evolve before 1.0.
- The `ReplayCaptureSinkInterface` signature may be adjusted.
- The capture paths may be expanded to include subscription commands and filter commands.

Consumers building production systems on replay hooks should treat this feature as experimental until the 1.0 stability guarantee applies.
