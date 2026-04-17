<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Contract;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeReplayCaptureInputInterface;
use Apntalk\EslReact\Contracts\RuntimeFeedbackProviderInterface;
use Apntalk\EslReact\Contracts\RuntimeStatusProviderInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeFeedbackSnapshot;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeObservedSubscriptionStateSnapshot;
use Apntalk\EslReact\Runner\RuntimeReconnectPhase;
use Apntalk\EslReact\Runner\RuntimeReconnectStateSnapshot;
use Apntalk\EslReact\Runner\RuntimeReconnectStopReason;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use Apntalk\EslReact\Runner\RuntimeSubscriptionStateSnapshot;
use Apntalk\EslReact\Runner\RuntimeStatusPhase;
use Apntalk\EslReact\Runner\RuntimeStatusSnapshot;
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

    public function testRuntimeRunnerHandleExposesFeedbackSnapshotMethod(): void
    {
        self::assertTrue(method_exists(RuntimeRunnerHandle::class, 'feedbackSnapshot'));
        self::assertTrue(is_a(RuntimeRunnerHandle::class, RuntimeFeedbackProviderInterface::class, true));

        $method = new \ReflectionMethod(RuntimeRunnerHandle::class, 'feedbackSnapshot');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeFeedbackSnapshot::class, $returnType->getName());
    }

    public function testRuntimeFeedbackSnapshotExposesDesiredSubscriptionStateMethod(): void
    {
        self::assertTrue(method_exists(RuntimeFeedbackSnapshot::class, 'subscriptionState'));

        $method = new \ReflectionMethod(RuntimeFeedbackSnapshot::class, 'subscriptionState');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeSubscriptionStateSnapshot::class, $returnType->getName());
    }

    public function testRuntimeFeedbackSnapshotExposesObservedSubscriptionStateMethod(): void
    {
        self::assertTrue(method_exists(RuntimeFeedbackSnapshot::class, 'observedSubscriptionState'));

        $method = new \ReflectionMethod(RuntimeFeedbackSnapshot::class, 'observedSubscriptionState');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeObservedSubscriptionStateSnapshot::class, $returnType->getName());
    }

    public function testRuntimeFeedbackSnapshotExposesReconnectStateMethod(): void
    {
        self::assertTrue(method_exists(RuntimeFeedbackSnapshot::class, 'reconnectState'));

        $method = new \ReflectionMethod(RuntimeFeedbackSnapshot::class, 'reconnectState');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeReconnectStateSnapshot::class, $returnType->getName());
    }

    public function testRuntimeReconnectStopReasonIsStableEnumSurface(): void
    {
        self::assertTrue(enum_exists(RuntimeReconnectStopReason::class));
    }

    public function testRuntimeReconnectPhaseIsStableEnumSurface(): void
    {
        self::assertTrue(enum_exists(RuntimeReconnectPhase::class));
    }

    public function testRuntimeRunnerHandleExposesStatusSnapshotMethod(): void
    {
        self::assertTrue(method_exists(RuntimeRunnerHandle::class, 'statusSnapshot'));
        self::assertTrue(is_a(RuntimeRunnerHandle::class, RuntimeStatusProviderInterface::class, true));

        $method = new \ReflectionMethod(RuntimeRunnerHandle::class, 'statusSnapshot');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(RuntimeStatusSnapshot::class, $returnType->getName());
    }

    public function testRuntimeStatusPhaseIsStableEnumSurface(): void
    {
        self::assertTrue(enum_exists(RuntimeStatusPhase::class));
    }

    public function testRuntimeStatusSnapshotExposesStableExportHelpers(): void
    {
        self::assertTrue(method_exists(RuntimeStatusSnapshot::class, 'toArray'));
        self::assertTrue(is_a(RuntimeStatusSnapshot::class, \JsonSerializable::class, true));
    }

    public function testRunnerFeedbackReadModelsRemainStablePublicSurface(): void
    {
        self::assertTrue(class_exists(RuntimeSubscriptionStateSnapshot::class));
        self::assertTrue(class_exists(RuntimeObservedSubscriptionStateSnapshot::class));
        self::assertTrue(class_exists(RuntimeReconnectStateSnapshot::class));
        self::assertTrue(class_exists(RuntimeStatusSnapshot::class));
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

    public function testPreparedRuntimeReplayCaptureInputIsAdditiveBootstrapContract(): void
    {
        self::assertTrue(is_a(PreparedRuntimeReplayCaptureInputInterface::class, PreparedRuntimeBootstrapInputInterface::class, true));
        self::assertTrue(is_a(PreparedRuntimeBootstrapInput::class, PreparedRuntimeReplayCaptureInputInterface::class, true));
    }
}
