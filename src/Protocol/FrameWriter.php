<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Contracts\FrameSerializerInterface;

final class FrameWriter
{
    public function __construct(private readonly FrameSerializerInterface $serializer) {}

    public function serialize(CommandInterface $command): string
    {
        return $this->serializer->serialize($command);
    }
}
