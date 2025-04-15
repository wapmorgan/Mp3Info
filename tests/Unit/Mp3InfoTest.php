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

    public function testIsValid(): void
    {
        $fp = fopen(__DIR__ . '/Samples/kky3OREM.mp3', 'r');
        
        $isDone = false;
        $data = '';
        while(! $isDone) {
            $isDone = true;
            $chunk = fread($fp, 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            } else {
                $data .= $chunk;
                $isDone = false;
            }
        }
        fclose($fp);

        $this->assertSame(true, Mp3Info::isValid($data));
    }
}