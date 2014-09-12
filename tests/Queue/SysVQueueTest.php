<?php

namespace Phive\Queue\Tests\Queue;

use Phive\Queue\NoItemAvailableException;
use Phive\Queue\QueueException;
use Phive\Queue\SysVQueue;
use Phive\Queue\Tests\Handler\SysVHandler;

/**
 * @requires extension sysvmsg
 */
class SysVQueueTest extends QueueTest
{
    use PerformanceTrait {
        PerformanceTrait::testPushPopPerformance as baseTestPushPopPerformance;
    }
    use ConcurrencyTrait;

    protected function getUnsupportedItemTypes()
    {
        return [Types::TYPE_NULL, Types::TYPE_ARRAY, Types::TYPE_OBJECT];
    }

    /**
     * @dataProvider provideItemsOfUnsupportedTypes
     * @expectedException \Phive\Queue\QueueException
     * @expectedExceptionMessage /^Message parameter must be either a string or a number\./
     */
    public function testUnsupportedItemType($item)
    {
        @$this->queue->push($item);
    }

    /**
     * @dataProvider provideItemsOfVariousTypes
     */
    public function testSupportItemTypeWithSerializerLoose($item)
    {
        $handler = self::getHandler();
        $key = $handler->getOption('key');

        $queue = new SysVQueue($key, true);

        $queue->push($item);
        $this->assertEquals($item, $queue->pop());
    }

    /**
     * @dataProvider provideQueueInterfaceMethods
     */
    public function testThrowExceptionOnMissingResource($method)
    {
        // force a resource creation
        $this->queue->count();

        self::getHandler()->clear();

        try {
            // suppress notices/warnings triggered by msg_* functions
            // to avoid a PHPUnit_Framework_Error_Notice exception be thrown
            @$this->callQueueMethod($this->queue, $method);
        } catch (NoItemAvailableException $e) {
        } catch (QueueException $e) {
            return;
        }

        $this->fail();
    }

    public function testSetPermissions()
    {
        $handler = self::getHandler();
        $key = $handler->getOption('key');

        $queue = new SysVQueue($key, null, 0606);

        // force a resource creation
        $queue->count();

        $meta = $handler->getMeta();

        $this->assertEquals(0606, $meta['msg_perm.mode']);
    }

    public function testSetItemMaxLength()
    {
        $this->queue->push('xx');
        $this->queue->setItemMaxLength(1);

        try {
            $this->queue->pop();
        } catch (\Exception $e) {
            if (7 === $e->getCode() && 'Argument list too long.' === $e->getMessage()) {
                return;
            }
        }

        $this->fail();
    }

    /**
     * @group performance
     * @dataProvider providePerformancePopDelay
     */
    public function testPushPopPerformance($delay)
    {
        exec('sysctl kernel.msgmnb 2> /dev/null', $output);

        if (!$output) {
            $this->markTestSkipped('Unable to determine the maximum size of the System V queue.');
        }

        $maxSizeInBytes = (int) str_replace('kernel.msgmnb = ', '', $output[0]);
        $queueSize = static::getPerformanceQueueSize();
        $itemLength = static::getPerformanceItemLength();

        if ($itemLength * $queueSize > $maxSizeInBytes) {
            $this->markTestSkipped(sprintf(
                'The System V queue size is too small (%d bytes) to run this test. '.
                'Try to decrease the "PHIVE_PERF_QUEUE_SIZE" environment variable to %d.',
                $maxSizeInBytes,
                floor($maxSizeInBytes / $itemLength)
            ));
        }

        self::baseTestPushPopPerformance($delay);
    }

    public static function createHandler(array $config)
    {
        return new SysVHandler([
            'key' => $config['PHIVE_SYSV_KEY'],
        ]);
    }
}
