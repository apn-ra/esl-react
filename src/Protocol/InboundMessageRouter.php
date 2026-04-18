<?php declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Contracts\InboundMessageClassifierInterface;
use Apntalk\EslCore\Contracts\ReplyInterface;
use Apntalk\EslCore\Protocol\Frame;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslCore\Replies\AuthAcceptedReply;
use Apntalk\EslCore\Replies\BgapiAcceptedReply;
use Apntalk\EslCore\Replies\CommandReply;
use Apntalk\EslCore\Replies\ErrorReply;
use Apntalk\EslCore\Replies\ReplyFactory;
use Apntalk\EslCore\Replies\UnknownReply;

final class InboundMessageRouter
{
    /** @var callable(Frame): void|null */
    private $onAuthRequestHandler = null;
    /** @var callable(ReplyInterface): void|null */
    private $onReplyHandler = null;
    /** @var callable(Frame): void|null */
    private $onEventHandler = null;
    /** @var callable(Frame): void|null */
    private $onDisconnectHandler = null;
    /** @var callable(Frame, \Throwable|null): void|null */
    private $onUnroutableHandler = null;

    private readonly ReplyFactory $replyFactory;

    public function __construct(
        private readonly InboundMessageClassifierInterface $classifier,
    ) {
        $this->replyFactory = new ReplyFactory();
    }

    public function onAuthRequest(callable $handler): void
    {
        $this->onAuthRequestHandler = $handler;
    }

    public function onReply(callable $handler): void
    {
        $this->onReplyHandler = $handler;
    }

    public function onEvent(callable $handler): void
    {
        $this->onEventHandler = $handler;
    }

    public function onDisconnectNotice(callable $handler): void
    {
        $this->onDisconnectHandler = $handler;
    }

    public function onUnroutable(callable $handler): void
    {
        $this->onUnroutableHandler = $handler;
    }

    /**
     * Classify a frame and dispatch it to the appropriate handler.
     * This method MUST NOT throw — malformed/unknown frames call onUnroutable.
     */
    public function route(Frame $frame): void
    {
        try {
            $classified = $this->classifier->classify($frame);

            if ($classified->isAuthRequest()) {
                $this->dispatch($this->onAuthRequestHandler, $frame);
                return;
            }

            if ($classified->isEvent()) {
                $this->dispatch($this->onEventHandler, $frame);
                return;
            }

            if ($classified->isDisconnectNotice()) {
                $this->dispatch($this->onDisconnectHandler, $frame);
                return;
            }

            // Reply path: AuthAccepted, ApiResponse, BgapiAccepted, CommandAccepted, CommandError.
            // `esl-core` v0.2.13 no longer exposes a distinct public auth-rejected
            // classification; auth `-ERR` stays on the command-error reply path.
            if (
                $classified->isAuthAccepted() ||
                $classified->isApiResponse() ||
                $classified->isBgapiAccepted() ||
                $classified->isCommandAccepted() ||
                $classified->isCommandError()
            ) {
                $reply = $this->replyFactory->fromClassification($classified);
                $this->dispatch($this->onReplyHandler, $reply);
                return;
            }

            // Unknown / unroutable frame
            $this->dispatch($this->onUnroutableHandler, $frame, null);
        } catch (\Throwable $e) {
            $this->dispatch($this->onUnroutableHandler, $frame, $e);
        }
    }

    private function dispatch(?callable $handler, mixed ...$args): void
    {
        if ($handler === null) {
            return;
        }
        try {
            $handler(...$args);
        } catch (\Throwable) {}
    }
}
