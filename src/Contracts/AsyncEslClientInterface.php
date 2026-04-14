<?php declare(strict_types=1);
namespace Apntalk\EslReact\Contracts;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use React\Promise\PromiseInterface;

interface AsyncEslClientInterface {
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
     * The handle's promise() resolves with BackgroundJobEvent when the job completes.
     * Throws BackpressureException synchronously if the inflight limit is exceeded.
     * Throws DrainException synchronously if the runtime is draining.
     *
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
     * Enters drain mode: no new commands accepted.
     * Waits for inflight commands to complete or timeout.
     * Then sends EXIT and closes the socket.
     *
     * @return PromiseInterface<void>
     */
    public function disconnect(): PromiseInterface;
}
