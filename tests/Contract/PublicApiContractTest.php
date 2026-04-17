<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Contract;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use PHPUnit\Framework\TestCase;

final class PublicApiContractTest extends TestCase
{
    public function testAsyncClientInterfaceExposesConnectLifecycleMethod(): void
    {
        self::assertTrue(method_exists(AsyncEslClientInterface::class, 'connect'));
    }

    public function testAsyncEslRuntimeMakeHasStableReturnType(): void
    {
        $method = new \ReflectionMethod(AsyncEslRuntime::class, 'make');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(AsyncEslClientInterface::class, $returnType->getName());
    }

    public function testRuntimeRunnerContractExposesRunMethod(): void
    {
        self::assertTrue(method_exists(RuntimeRunnerInterface::class, 'run'));
    }

    public function testRuntimeRunnerHandleExposesLifecycleSnapshotMethod(): void
    {
        self::assertTrue(method_exists(RuntimeRunnerHandle::class, 'lifecycleSnapshot'));

        $method = new \ReflectionMethod(RuntimeRunnerHandle::class, 'lifecycleSnapshot');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeLifecycleSnapshot::class, $returnType->getName());
    }

    public function testRuntimeRunnerHandleExposesLifecycleChangeListenerMethod(): void
    {
        self::assertTrue(method_exists(RuntimeRunnerHandle::class, 'onLifecycleChange'));
    }

    public function testAsyncEslRuntimeRunnerHasStableReturnType(): void
    {
        $method = new \ReflectionMethod(AsyncEslRuntime::class, 'runner');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeRunnerInterface::class, $returnType->getName());
    }

    public function testPreparedRuntimeInputImplementsRunnerInputContract(): void
    {
        self::assertTrue(is_a(PreparedRuntimeInput::class, RuntimeRunnerInputInterface::class, true));
    }

    public function testPreparedRuntimeBootstrapInputIsAdditiveRunnerInputContract(): void
    {
        self::assertTrue(is_a(PreparedRuntimeBootstrapInputInterface::class, RuntimeRunnerInputInterface::class, true));
        self::assertTrue(is_a(PreparedRuntimeBootstrapInput::class, PreparedRuntimeBootstrapInputInterface::class, true));
    }

    public function testPreparedRuntimeDialTargetInputIsAdditiveBootstrapContract(): void
    {
        self::assertTrue(is_a(PreparedRuntimeDialTargetInputInterface::class, PreparedRuntimeBootstrapInputInterface::class, true));
        self::assertTrue(is_a(PreparedRuntimeBootstrapInput::class, PreparedRuntimeDialTargetInputInterface::class, true));
    }
}
