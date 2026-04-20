<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use React\Promise\PromiseInterface;

interface AsyncEslClientInterface
{
    /**
     * Establish the TCP connection and complete the ESL auth handshake.
     *
     * Resolves once the runtime reaches the authenticated state.
     * Rejects with ConnectionException for transport failures.
     * Rejects with AuthenticationException when FreeSWITCH rejects auth.
     *
     * @return PromiseInterface<void>
     */
    public function connect(): PromiseInterface;

    /**
     * Dispatch a synchronous API command.
     *
     * Commands are queued serially (ESL protocol constraint: one api at a time).
     * Resolves with ApiReply on success.
     * Rejects with CommandTimeoutException if the timeout expires.
     * Rejects with ConnectionLostException if the connection drops while inflight.
     * Rejects with BackpressureException if the inflight limit is exceeded.
     * Rejects with DrainException if the runtime is draining.
     *
     * Cancellation is NOT supported in v1.x.
     *
     * @return PromiseInterface<\Apntalk\EslCore\Replies\ApiReply>
     */
    public function api(string $command, string $args = ''): PromiseInterface;

    /**
     * Dispatch an asynchronous background API command.
     *
     * Returns a BgapiJobHandle immediately (synchronous).
     * Throws ConnectionException synchronously if the runtime is not currently
     * authenticated or is still recovering after an unexpected disconnect.
     * The handle's jobUuid() is empty until the bgapi acceptance reply arrives.
     * The handle's promise() resolves with BackgroundJobEvent when the matching
     * completion event arrives.
     * The handle's promise rejects on ack timeout, completion timeout, or
     * terminal disconnect.
     * Throws BackpressureException synchronously if the inflight limit is exceeded.
     * Throws DrainException synchronously if the runtime is draining.
     *
     * @throws \Apntalk\EslReact\Exceptions\ConnectionException
     * @throws \Apntalk\EslReact\Exceptions\BackpressureException
     * @throws \Apntalk\EslReact\Exceptions\DrainException
     */
    public function bgapi(string $command, string $args = ''): BgapiJobHandle;

    /**
     * Return the event stream for attaching listeners.
     */
    public function events(): EventStreamInterface;

    /**
     * Return the health reporter.
     */
    public function health(): HealthReporterInterface;

    /**
     * Return the subscription manager.
     */
    public function subscriptions(): SubscriptionManagerInterface;

    /**
     * Gracefully disconnect.
     *
     * Enters drain mode and rejects new work immediately.
     * Already-accepted inflight work is allowed to settle until the configured
     * drain timeout; remaining work is then terminated deterministically before
     * the socket is closed. Explicit disconnect is terminal for this runtime
     * instance and does not trigger reconnect.
     *
     * @return PromiseInterface<void>
     */
    public function disconnect(): PromiseInterface;
}
