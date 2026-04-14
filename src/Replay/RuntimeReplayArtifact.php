<?php declare(strict_types=1);

namespace Apntalk\EslReact\Replay;

final class RuntimeReplayArtifact
{
    public const VERSION = '1';

    public const META_VERSION = 'replay-artifact-version';
    public const META_NAME = 'replay-artifact-name';
    public const META_PATH = 'runtime-capture-path';

    public const API_DISPATCH = 'api.dispatch';
    public const API_REPLY = 'api.reply';
    public const BGAPI_DISPATCH = 'bgapi.dispatch';
    public const BGAPI_ACK = 'bgapi.ack';
    public const BGAPI_COMPLETE = 'bgapi.complete';
    public const COMMAND_REPLY = 'command.reply';
    public const EVENT_RAW = 'event.raw';
    public const SUBSCRIPTION_MUTATE = 'subscription.mutate';
    public const FILTER_MUTATE = 'filter.mutate';

    /**
     * @param array<string, string> $metadata
     * @return array<string, string>
     */
    public static function withIdentity(string $artifactName, array $metadata = []): array
    {
        $metadata[self::META_VERSION] = self::VERSION;
        $metadata[self::META_NAME] = $artifactName;
        $metadata[self::META_PATH] = $artifactName;

        return $metadata;
    }
}
