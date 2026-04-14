<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Subscription;

use Apntalk\EslReact\Subscription\ActiveSubscriptionSet;
use Apntalk\EslReact\Subscription\FilterManager;
use PHPUnit\Framework\TestCase;

final class DesiredStateTrackingTest extends TestCase
{
    public function testActiveSubscriptionSetDeduplicatesAndCanReplaceState(): void
    {
        $set = new ActiveSubscriptionSet();
        $set->subscribe('CHANNEL_CREATE', 'CHANNEL_CREATE', 'CHANNEL_ANSWER');

        self::assertSame(['CHANNEL_CREATE', 'CHANNEL_ANSWER'], $set->eventNames());
        self::assertTrue($set->hasEventName('CHANNEL_CREATE'));

        $set->replace(['BACKGROUND_JOB']);

        self::assertSame(['BACKGROUND_JOB'], $set->eventNames());
        self::assertFalse($set->hasEventName('CHANNEL_CREATE'));
    }

    public function testFilterManagerDeduplicatesAndTracksMembership(): void
    {
        $filters = new FilterManager();
        $filters->addFilter('Event-Name', 'CHANNEL_CREATE');
        $filters->addFilter('Event-Name', 'CHANNEL_CREATE');

        self::assertTrue($filters->hasFilter('Event-Name', 'CHANNEL_CREATE'));
        self::assertCount(1, $filters->all());

        $filters->removeFilter('Event-Name', 'missing');
        self::assertCount(1, $filters->all());

        $filters->removeFilter('Event-Name', 'CHANNEL_CREATE');
        self::assertFalse($filters->hasFilters());
    }
}
