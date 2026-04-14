# BGAPI tracking

## Overview

`bgapi` is the FreeSWITCH command mode for long-running operations. Unlike `api`, which blocks the ESL connection until a reply arrives, `bgapi` is accepted quickly and later produces a `BACKGROUND_JOB` event when the work completes. FreeSWITCH assigns the Job-UUID in the acceptance reply.

`esl-react` implements a tracked dispatch model that bridges the synchronous handle return with an async promise that resolves on completion.

---

## Dispatch

```php
$handle = $client->bgapi('originate', 'sofia/internal/1000 &echo');
```

`bgapi()` returns a `BgapiJobHandle` **immediately**. At that moment the handle is only a local tracking object. The FreeSWITCH-assigned Job-UUID is not known until the command/reply ack arrives.

The handle is valid from the moment `bgapi()` returns. Its promise will be resolved or rejected asynchronously.

`bgapi()` does NOT return a `PromiseInterface` directly. It returns a `BgapiJobHandle`. The handle's promise is accessed via `$handle->promise()`.

---

## BgapiJobHandle

```php
$handle->jobUuid();    // string — the Job-UUID FreeSWITCH will use
$handle->promise();    // PromiseInterface<BackgroundJobEvent>
```

Implemented truth for this slice:

- `jobUuid()` returns an empty string until the ack arrives.
- Once the ack arrives, `jobUuid()` returns the FreeSWITCH-assigned Job-UUID for the rest of the handle lifecycle.

The promise resolves with a `BackgroundJobEvent` when the corresponding completion event arrives. The `BackgroundJobEvent` carries the job output in its body.

---

## Completion matching

When a `BACKGROUND_JOB` event arrives from FreeSWITCH:

1. The runtime event path parses it as a typed `BackgroundJobEvent`.
2. `BgapiJobTracker` reads the `Job-UUID` from the event.
3. It looks up the matching pending job by UUID.
4. If found, that job's promise is resolved and the job is removed from the tracker.
5. If no matching job is found, the completion event is ignored for bgapi tracking.

Matching is strict by `Job-UUID`. A completion can only resolve the job that owns that UUID.

---

## Timeout behavior

Bgapi completions are subject to two separate timeouts, configured via `CommandTimeoutConfig`:

### bgapiAckTimeoutSeconds

The time allowed for FreeSWITCH to acknowledge the `bgapi` command with its initial reply (the short reply that confirms the command was received and provides the Job-UUID). If this reply is not received within `bgapiAckTimeoutSeconds`, the handle's promise is rejected with `CommandTimeoutException`.

This is distinct from the job completion. An ack timeout means FreeSWITCH never accepted the command.

### bgapiOrphanTimeoutSeconds

The time allowed for the `BACKGROUND_JOB` completion event to arrive after the ack. If no completion arrives within this window, the handle's promise is rejected with `CommandTimeoutException`.

This timeout begins when the ack is received, not when `bgapi()` is called.

### Late completions

If a `BACKGROUND_JOB` event arrives for a job whose promise has already been rejected due to timeout, it is **silently discarded**. The job UUID is no longer in `BgapiJobTracker`, so no lookup matches and no action is taken.

---

## Orphaned jobs

An orphaned job is one where the completion event never arrives (FreeSWITCH lost the job internally, the originate failed without emitting `BACKGROUND_JOB`, or FreeSWITCH was restarted mid-execution).

Orphaned jobs are cleaned up by the completion timeout. After `bgapiOrphanTimeoutSeconds` elapses, the promise rejects with `CommandTimeoutException` and the job is removed from the tracker.

Set `bgapiOrphanTimeoutSeconds` to a value longer than the longest expected job duration. For originate commands, this may be 60+ seconds. For shorter commands, shorter values are appropriate.

---

## Reconnect behavior

Accepted `bgapi` jobs survive unexpected supervised reconnect. When the connection drops unexpectedly:

- Pending bgapi jobs are NOT rejected (unlike inflight `api` commands).
- Their promises remain open.
- `BgapiJobTracker` retains all pending job UUIDs.

If FreeSWITCH was processing the job before the disconnect, it may still emit the `BACKGROUND_JOB` completion event after the ESL connection is reestablished. When that event arrives on the new connection, `BgapiCompletionMatcher` matches it by UUID and resolves the handle's promise normally.

If FreeSWITCH also lost the job during the restart, no completion will ever arrive, and the job will time out via `bgapiOrphanTimeoutSeconds`.

Reconnect itself does NOT resolve bgapi handles. Only a valid later completion can resolve them.

Explicit `disconnect()` is different:

- The runtime treats it as terminal shutdown.
- Pending bgapi jobs are rejected with `ConnectionLostException`.
- No later completion is applied to those terminalized handles.

---

## Pending bgapi count

`HealthSnapshot::$pendingBgapiJobCount` reflects the current number of tracked bgapi jobs. Under normal operation this should be low and bounded. A growing count indicates either very long-running jobs, a high dispatch rate, or orphaned jobs accumulating before timeout.

The current runtime does not add a separate bgapi-specific backpressure threshold beyond the existing command-path limits.

---

## Usage example

```php
$handle = $client->bgapi('originate', '{origination_uuid=my-call-uuid}sofia/internal/1000 &echo');

if ($handle->jobUuid() === '') {
    echo "Waiting for bgapi acceptance...\n";
}

$handle->promise()->then(
    function (\Apntalk\EslCore\Events\BackgroundJobEvent $event) use ($handle) {
        echo "Job UUID: " . $handle->jobUuid() . "\n";
        echo "Job completed: " . $event->result() . "\n";
    },
    function (\Throwable $e) {
        if ($e instanceof \Apntalk\EslReact\Exceptions\CommandTimeoutException) {
            echo "Job timed out.\n";
        } elseif ($e instanceof \Apntalk\EslReact\Exceptions\ConnectionLostException) {
            echo "Runtime shut down before job completion.\n";
        }
    }
);
```
