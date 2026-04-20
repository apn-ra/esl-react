<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Support;

use Apntalk\EslReact\Tests\Support\LiveHarnessEnvLoader;
use PHPUnit\Framework\TestCase;

final class LiveHarnessEnvLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEnv('ESL_REACT_LIVE_HOST');
        $this->clearEnv('ESL_REACT_LIVE_PASSWORD');
        $this->clearEnv('ESL_REACT_LIVE_TEST');
        $this->clearEnv('NOT_ESL_REACT_LIVE_VALUE');
    }

    protected function tearDown(): void
    {
        $this->clearEnv('ESL_REACT_LIVE_HOST');
        $this->clearEnv('ESL_REACT_LIVE_PASSWORD');
        $this->clearEnv('ESL_REACT_LIVE_TEST');
        $this->clearEnv('NOT_ESL_REACT_LIVE_VALUE');

        parent::tearDown();
    }

    public function testLoadsOnlyLiveHarnessVariablesFromLocalEnvFiles(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/.env.live.local', implode("\n", [
            'ESL_REACT_LIVE_HOST=127.0.0.1',
            'ESL_REACT_LIVE_PASSWORD="quoted secret"',
            'NOT_ESL_REACT_LIVE_VALUE=ignored',
        ]));

        LiveHarnessEnvLoader::load($root);

        self::assertSame('127.0.0.1', getenv('ESL_REACT_LIVE_HOST'));
        self::assertSame('quoted secret', getenv('ESL_REACT_LIVE_PASSWORD'));
        self::assertFalse(getenv('NOT_ESL_REACT_LIVE_VALUE'));
    }

    public function testExistingProcessEnvironmentTakesPrecedenceOverLocalFiles(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/.env.live.local', 'ESL_REACT_LIVE_HOST=file-value');
        putenv('ESL_REACT_LIVE_HOST=process-value');
        $_ENV['ESL_REACT_LIVE_HOST'] = 'process-value';
        $_SERVER['ESL_REACT_LIVE_HOST'] = 'process-value';

        LiveHarnessEnvLoader::load($root);

        self::assertSame('process-value', getenv('ESL_REACT_LIVE_HOST'));
    }

    public function testLiveLocalOverridesTestingLocalWhenProcessEnvIsAbsent(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/.env.testing.local', 'ESL_REACT_LIVE_TEST=0');
        file_put_contents($root . '/.env.live.local', 'ESL_REACT_LIVE_TEST=1');

        LiveHarnessEnvLoader::load($root);

        self::assertSame('1', getenv('ESL_REACT_LIVE_TEST'));
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/esl-react-env-loader-' . bin2hex(random_bytes(8));
        mkdir($root);

        return $root;
    }

    private function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
