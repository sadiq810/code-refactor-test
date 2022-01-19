<?php

namespace Tests\Unit;

use DTApi\Helpers\TeHelper;
use PHPUnit\Framework\TestCase;

class UrlHelperTest extends TestCase
{
    /**
     * Test the willExpireAt function.
     *
     * @return void
     */
    public function testTheWillExpireAt()
    {
        $teHelper = new TeHelper();

        $result = $teHelper->willExpireAt("2022-01-19 13:50:55", "2021-11-19 13:50:55");
        $this->assertEquals("2022-01-17 13:50:55", $result);

        $result = $teHelper->willExpireAt("2022-01-19 13:56:01", "2022-01-17 13:56:01");
        $this->assertEquals("2022-01-19 13:56:01", $result);

        $result = $teHelper->willExpireAt("2022-01-19 13:59:48", "2022-01-20 13:59:48");
        $this->assertEquals("2022-01-19 13:59:48", $result);

        $result = $teHelper->willExpireAt("2022-01-19 14:00:57", "2022-01-19 13:39:57");
        $this->assertEquals("2022-01-19 14:00:57", $result);

        $result = $teHelper->willExpireAt("", "");
        $this->assertNotNull($result);
    }
}