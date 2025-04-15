<?php

declare(strict_types=1);

namespace wapmorgan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use wapmorgan\Mp3Info\Mp3Info;

final class Mp3InfoTest extends TestCase
{
    public function testIsValidAudio(): void
    {
        $file =  __DIR__ . '/Samples/kky3OREM.mp3';
        
        $this->assertSame(true, Mp3Info::isValidAudio($file));
    }
}