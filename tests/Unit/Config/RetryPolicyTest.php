<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Config;

use Apntalk\EslReact\Config\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultPolicyUsesUnlimitedExponentialBackoffWithMaximumDelay(): void
    {
        $policy = RetryPolicy::default();

        self::assertTrue($policy->enabled);
        self::assertSame(0, $policy->maxAttempts);
        self::assertFalse($policy->hasExhausted(0));
        self::assertFalse($policy->hasExhausted(100));
        self::assertSame(0.0, $policy->delayForAttempt(0));
        self::assertSame(1.0, $policy->delayForAttempt(1));
        self::assertSame(2.0, $policy->delayForAttempt(2));
        self::assertSame(4.0, $policy->delayForAttempt(3));
        self::assertSame(60.0, $policy->delayForAttempt(10));
    }

    public function testBoundedPolicyExhaustsAtConfiguredAttemptCount(): void
    {
        $policy = RetryPolicy::withMaxAttempts(3, 0.25);

        self::assertTrue($policy->enabled);
        self::assertSame(3, $policy->maxAttempts);
        self::assertFalse($policy->hasExhausted(0));
        self::assertFalse($policy->hasExhausted(2));
        self::assertTrue($policy->hasExhausted(3));
        self::assertSame(0.25, $policy->delayForAttempt(1));
        self::assertSame(0.5, $policy->delayForAttempt(2));
        self::assertSame(1.0, $policy->delayForAttempt(3));
    }

    public function testZeroMaxAttemptsMeansUnlimitedWhenPolicyIsEnabled(): void
    {
        $policy = RetryPolicy::withMaxAttempts(0, 0.1);

        self::assertTrue($policy->enabled);
        self::assertFalse($policy->hasExhausted(0));
        self::assertFalse($policy->hasExhausted(1_000));
        self::assertSame(0.1, $policy->delayForAttempt(1));
    }

    public function testDisabledPolicyIsImmediatelyExhaustedAndHasNoDelay(): void
    {
        $policy = RetryPolicy::disabled();

        self::assertFalse($policy->enabled);
        self::assertTrue($policy->hasExhausted(0));
        self::assertTrue($policy->hasExhausted(10));
        self::assertSame(0.0, $policy->delayForAttempt(1));
    }
}
