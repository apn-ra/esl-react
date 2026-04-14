# Async model

## Async result contract

All async operations return `React\Promise\PromiseInterface` from `react/promise` ^3.x.

Callers attach fulfillment and rejection handlers using `->then()`. Promises are resolved on the ReactPHP event loop. Consuming code must not block the loop inside a `->then()` callback.

```php
// Fulfillment handler receives the typed result.
// Rejection handler receives a \Throwable.
$client->api('status')->then(
    fn($reply) => ...,
    fn($e) => ...,
);
```

---

## Command dispatch model

### api commands

`api` commands are subject to the ESL protocol constraint: only one `api` command may be inflight on a connection at a time. The `AsyncCommandBus` enforces a serial queue.

- Commands are accepted and enqueued immediately regardless of whether a prior command is outstanding.
- The bus writes each command to the socket only after the previous reply has been received.
- The caller's promise resolves in the order commands were accepted.
- Callers do not need to serialize their own calls; the bus handles it.

If a command cannot be accepted (e.g., backpressure limit reached, runtime is draining), the promise is rejected with the relevant exception before the command enters the queue.

### bgapi commands

`bgapi` commands use a separate dispatch path and do not share the serial api queue.

- The runtime issues the `bgapi` command to FreeSWITCH immediately and receives a short acknowledgment reply.
- A `BgapiJobHandle` is returned to the caller synchronously (before the promise resolves).
- The handle's promise resolves when a `BACKGROUND_JOB` event carrying the matching Job-UUID arrives.
- Multiple `bgapi` commands may be outstanding simultaneously.

See [bgapi-tracking.md](bgapi-tracking.md) for full bgapi behavior.

## Subscription and filter mutation model

Subscription and filter mutations use the same authenticated live-session command path as other runtime commands, but they remain a separate control surface from `api()`.

- The runtime starts with an explicit caller-owned baseline: no broad application event subscription is invented automatically.
- `subscribe()` sends the full desired named-event set for the live session.
- `unsubscribe()` sends either the reduced named-event set or `noevents` when the desired set becomes empty.
- `subscribeAll()` switches the desired state to all events.
- `addFilter()` and `removeFilter()` mutate the live session and the in-memory desired filter set together.
- Duplicate subscribe/filter-add operations and removal of missing state are treated as deterministic no-ops.
- Mutations before successful authentication, while draining, or after disconnect are rejected with `ConnectionException`.

The desired subscription/filter state is intentionally kept in memory so the runtime can restore it after a successful reconnect. Broader replay semantics are still not implemented in this phase. No mutation queue exists during recovery: subscription/filter mutations attempted while reconnect is in progress reject with `ConnectionException`.

## Reconnect and recovery command behavior

- Unexpected socket close triggers reconnect attempts according to `RetryPolicy`.
- Explicit `disconnect()` does not trigger reconnect.
- Authentication rejection, malformed handshake traffic, and handshake timeout remain fail-closed and do not enter retry.
- After successful re-authentication, the runtime restores the desired event subscription baseline first (`subscribeAll()` or the named event set), then restores desired filters, then marks the runtime live again.
- `api()` during recovery rejects with `ConnectionException`. Commands are not queued for post-reconnect replay in this phase.

---

## Timeout behavior

Command timeouts are configured via `CommandTimeoutConfig`.

```php
$config = new \Apntalk\EslReact\Config\CommandTimeoutConfig(
    apiTimeoutMs: 5_000,
    bgapiAckTimeoutMs: 2_000,
    bgapiCompletionTimeoutMs: 60_000,
);
```

- If an `api` command does not receive a reply within `apiTimeoutMs`, its promise is rejected with `CommandTimeoutException`.
- If a `bgapi` acknowledgment is not received within `bgapiAckTimeoutMs`, the handle's promise is rejected with `CommandTimeoutException`.
- If a `bgapi` completion event is not received within `bgapiCompletionTimeoutMs`, the handle's promise is rejected with `CommandTimeoutException`.
- After timeout, any late reply or completion that arrives for the timed-out operation is silently discarded.

---

## Cancellation policy

**Cancellation is NOT supported in v1.x.**

Promises returned by `api()` and `bgapi()` cannot be cancelled. Once dispatched, a command runs to completion, timeout, or connection loss. There is no mechanism to withdraw an inflight command from the queue or cancel a pending bgapi job.

Callers that need to abandon a result may choose to ignore the resolved value, but the command will still be sent to FreeSWITCH and a reply will still be awaited internally.

This constraint exists because the ESL protocol does not support request cancellation, and implementing client-side cancellation safely (without desynchronizing the serial queue) requires explicit protocol coordination that is deferred to a future version.

The same applies during reconnect recovery: commands or session mutations attempted while the runtime is not authenticated are rejected rather than queued for later replay.

---

## Raw envelope stream

Every inbound event frame that is successfully parsed and classified is delivered to registered raw `EventEnvelope` listeners before typed or unknown dispatch occurs.

```php
$client->events()->onRawEnvelope(
    function (\Apntalk\EslCore\Correlation\EventEnvelope $envelope): void {
        // receives inbound event envelopes only
    }
);
```

Raw envelope listeners receive event envelopes in socket-received order. This stream is intended for debugging, logging, and replay-adjacent observation. It is not filtered by event name or type.

Reply traffic does not enter this event-envelope path. Command replies remain on the command bus / reply-routing path.

---

## Typed event stream

After raw envelope delivery, classified events are dispatched to typed event listeners.

```php
$client->events()->onEvent(
    'CHANNEL_ANSWER',
    function (\Apntalk\EslCore\Events\ChannelLifecycleEvent $event): void {
        // ...
    }
);
```

- Listeners are matched by exact ESL event name, such as `CHANNEL_CREATE` or `CHANNEL_ANSWER`.
- The listener receives the corresponding typed `esl-core` model for known event families.
- Multiple listeners may be registered for the same event name.
- Listeners are called in registration order.

`onAnyEvent()` receives every known typed event after any raw envelope listeners have run.

---

## Unknown events

Event names that `esl-core` cannot map to a typed class are delivered as `RawEvent` to unknown-event listeners.

```php
$client->events()->onUnknown(
    function (\Apntalk\EslCore\Events\RawEvent $event): void {
        echo "Unrecognized event: " . $event->eventName();
    }
);
```

Unknown event listeners follow the same ordering and exception rules as typed listeners.
Unknown events do not currently flow through `onAnyEvent()`; they stay on the explicit unknown-event path.

Well-formed but unmapped events are different from malformed event payloads:

- well-formed unmapped events become `RawEvent` and remain observable
- malformed event payloads are dropped from the event surface and currently do not enter the unknown-event path

---

## Listener exception policy

Exceptions thrown inside listener callbacks are caught by the event dispatch machinery. A listener exception does NOT:

- crash the runtime
- abort delivery to subsequent listeners
- prevent the next event from being processed

Caught exceptions are currently contained within the event dispatcher. The default internal handler writes a short message to stderr.

Listener exceptions are not currently surfaced through a stable public callback or health-specific metric surface in this phase. This is intentional: containment is implemented now, richer surfacing is deferred until the package can define that contract explicitly.

---

## Heartbeat and liveness

The current heartbeat implementation is intentionally minimal:

- Every successfully parsed inbound frame records activity.
- When the runtime stays idle beyond `HeartbeatConfig::$timeoutSeconds`, `isLive` becomes false.
- If the runtime is authenticated, not draining, and has no command already inflight, the monitor issues a lightweight `api status` probe.
- If liveness degrades again without recovery, the runtime closes the socket and falls into the normal disconnect/reconnect path.

This is enough to expose deterministic liveness transitions in health snapshots and to trigger recovery when a connection goes silent. It is not yet a broader heartbeat orchestration layer.

---

## Event ordering guarantee

Events are delivered in **socket-received order** at the router boundary. The router processes one envelope at a time.

Within a single event, listener dispatch is **synchronous and sequential**:

1. Raw envelope listeners are called in registration order.
2. Typed event listeners (or unknown listeners) are called in registration order.
3. Dispatch completes before the router processes the next envelope.

**Consequence: slow listeners block delivery of subsequent events.**

If a listener performs a long-running synchronous operation, no other events will be dispatched until that listener returns. This applies to all listener types (raw, typed, unknown).

Callers with slow listeners should schedule deferred work using `Loop::futureTick()` or a similar mechanism, returning from the listener quickly.

### Listener isolation

**Async listener execution is NOT supported in v1.x.**

There is no mechanism to run listeners in parallel or on isolated fibers. All listeners execute synchronously within the event loop tick that delivers their event. Strict global delivery order is maintained at the cost of head-of-line blocking when listeners are slow.

Per-listener async isolation is a candidate for a future version if the ordering trade-offs prove acceptable.
