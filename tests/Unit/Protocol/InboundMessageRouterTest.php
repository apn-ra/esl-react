<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Protocol;

use Apntalk\EslCore\Contracts\ClassifiedMessageInterface;
use Apntalk\EslCore\Contracts\InboundMessageClassifierInterface;
use Apntalk\EslCore\Protocol\Frame;
use Apntalk\EslCore\Protocol\HeaderBag;
use Apntalk\EslCore\Replies\ErrorReply;
use Apntalk\EslReact\Protocol\InboundMessageRouter;
use PHPUnit\Framework\TestCase;

final class InboundMessageRouterTest extends TestCase
{
    public function testRouteAcceptsPublicClassifiedMessageContractForCommandErrors(): void
    {
        $frame = new Frame(
            HeaderBag::fromHeaderBlock("Content-Type: command/reply\nReply-Text: -ERR invalid password"),
            '',
        );

        $classifier = new class ($frame) implements InboundMessageClassifierInterface {
            public function __construct(private readonly Frame $frame) {}

            public function classify(Frame $frame): ClassifiedMessageInterface
            {
                TestCase::assertSame($this->frame, $frame);

                return new class ($this->frame) implements ClassifiedMessageInterface {
                    public function __construct(private readonly Frame $frame) {}

                    public function frame(): Frame
                    {
                        return $this->frame;
                    }

                    public function isAuthRequest(): bool
                    {
                        return false;
                    }

                    public function isAuthAccepted(): bool
                    {
                        return false;
                    }

                    public function isBgapiAccepted(): bool
                    {
                        return false;
                    }

                    public function isCommandAccepted(): bool
                    {
                        return false;
                    }

                    public function isCommandError(): bool
                    {
                        return true;
                    }

                    public function isApiResponse(): bool
                    {
                        return false;
                    }

                    public function isEvent(): bool
                    {
                        return false;
                    }

                    public function isDisconnectNotice(): bool
                    {
                        return false;
                    }

                    public function isUnknown(): bool
                    {
                        return false;
                    }
                };
            }
        };

        $router = new InboundMessageRouter($classifier);
        $capturedReply = null;
        $router->onReply(static function ($reply) use (&$capturedReply): void {
            $capturedReply = $reply;
        });

        $router->route($frame);

        self::assertInstanceOf(ErrorReply::class, $capturedReply);
        self::assertSame('invalid password', $capturedReply->reason());
    }
}
