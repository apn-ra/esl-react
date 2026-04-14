<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Contract;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
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
}
