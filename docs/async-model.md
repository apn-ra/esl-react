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

---

## Raw envelope stream

Every inbound `EventEnvelope` is delivered to registered raw envelope listeners before typed dispatch occurs.

```php
$client->events()->onRawEnvelope(
    function (\Apntalk\EslCore\Model\EventEnvelope $envelope): void {
        // receives all envelopes: replies, events, bgapi completions
    }
);
```

Raw envelope listeners receive envelopes in socket-received order. This stream is intended for debugging, logging, and replay capture. It is not filtered by event name or type.

---

## Typed event stream

After raw envelope delivery, classified events are dispatched to typed event listeners.

```php
$client->events()->onEvent(
    \Apntalk\EslCore\Model\Event\ChannelAnswerEvent::class,
    function (\Apntalk\EslCore\Model\Event\ChannelAnswerEvent $event): void {
        // ...
    }
);
```

- Listeners are matched by exact class name or by interface/parent class, depending on the emitter implementation.
- Multiple listeners may be registered for the same event type.
- Listeners are called in registration order.

---

## Unknown events

Event names that `esl-core` cannot map to a typed class are delivered as `RawEvent` to unknown-event listeners.

```php
$client->events()->onUnknown(
    function (\Apntalk\EslCore\Model\Event\RawEvent $event): void {
        echo "Unrecognized event: " . $event->getEventName();
    }
);
```

Unknown event listeners follow the same ordering and exception rules as typed listeners.

---

## Listener exception policy

Exceptions thrown inside listener callbacks are caught by the event dispatch machinery. A listener exception does NOT:

- crash the runtime
- abort delivery to subsequent listeners
- prevent the next event from being processed

Caught exceptions are passed to a configurable error handler. The default error handler writes the exception class, message, and stack trace to stderr.

To supply a custom error handler:

```php
$config = new RuntimeConfig(
    // ...
    listenerErrorHandler: function (\Throwable $e, object $event): void {
        // log, metric, or re-throw if desired
    },
);
```

Rethrowing inside the error handler will propagate the exception upward into the ReactPHP event loop, which may crash the process depending on your loop configuration. This is the caller's responsibility.

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
