<?php

namespace wapmorgan\Mp3Info;

class Mp3FileRemote
{
    public string $fileName;
    public int $blockSize;

    protected $buffer;
    protected int $filePos;
    protected $fileSize;

    /**
     * Creates a new remote file object.
     *
     * @param string $fileName  URL to open
     * @param int    $blockSize Size of the blocks to query from the server (default: 4096)
     */
    public function __construct(string $fileName, int $blockSize = 4096)
    {
        $this->fileName = $fileName;
        $this->blockSize = $blockSize;
        $this->buffer = [];
        $this->filePos = 0;
        $this->fileSize = $this->_readFileSize();
    }

    /**
     * Returns the file size
     *
     * @return int File size
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Makes a HEAD request to get the file size
     *
     * @return int Content-Length header
     */
    private function _readFileSize(): int
    {
        // make HTTP HEAD request to get Content-Length
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
            ],
        ]);
        $result = get_headers($this->fileName, true, $context);
        return $result['Content-Length'];
    }

    /**
     * Returns the given amount of Bytes from the current file position.
     *
     * @param int $numBytes Bytes to read
     *
     * @return string Read Bytes
     */
    public function getBytes(int $numBytes): string
    {
        $blockId = intdiv($this->filePos, $this->blockSize);
        $blockPos = $this->filePos % $this->blockSize;
        
        $output = [];
        
        do {
            $this->downloadBlock($blockId);   // make sure we have this block
            if ($blockPos + $numBytes >= $this->blockSize) {
                // length of request is more than this block has, truncate to block len
                $subLen = $this->blockSize - $blockPos;
            } else {
                // requested length fits inside this block
                $subLen = $numBytes;
            }
            // $subLen = ($blockPos + $numBytes >= $this->blockSize) ? ($this->blockSize - $blockPos) : $numBytes;
            $output[] = substr($this->buffer[$blockId], $blockPos, $subLen);
            $this->filePos += $subLen;
            $numBytes -= $subLen;
            // advance to next block
            $blockPos = 0;
            $blockId++;
        } while ($numBytes > 0);
        
        return implode('', $output);
    }

    /**
     * Returns the current file position
     *
     * @return int File position
     */
    public function getFilePos(): int
    {
        return $this->filePos;
    }

    /**
     * Sets the file pointer to the given position.
     *
     * @param int $posBytes Position to jump to
     *
     * @return bool TRUE if successful
     */
    public function seekTo(int $posBytes): bool
    {
        if ($posBytes < 0 || $posBytes > $this->fileSize) {
            return false;
        }
        $this->filePos = $posBytes;
        return true;
    }

    /**
     * Advances the file pointer the given amount.
     *
     * @param int $posBytes Bytes to advance
     *
     * @return bool TRUE if successful
     */
    public function seekForward(int $posBytes): bool
    {
        $newPos = $this->filePos + $posBytes;
        return $this->seekTo($newPos);
    }

    /**
     * Downloads the given block if needed
     *
     * @param int $blockNo Block to download
     *
     * @return bool TRUE if successful
     */
    protected function downloadBlock(int $blockNo): bool
    {
        if (array_key_exists($blockNo, $this->buffer)) {
            // already downloaded
            return true;
        }
        $bytesFrom = $blockNo * $this->blockSize;
        $bytesTo   = $bytesFrom + $this->blockSize - 1;
        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Range
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Range: bytes=' . $bytesFrom . '-' . $bytesTo,
                ],
            ],
        ]);
        $filePtr = fopen($this->fileName, 'rb', false, $context);
        $this->buffer[$blockNo] = fread($filePtr, $this->blockSize);
        $status = stream_get_meta_data($filePtr);
        $httpStatus = explode(' ', $status['wrapper_data'][0])[1];
        if ($httpStatus != '206') {
            if ($httpStatus != '200') {
                echo 'Download error!' . PHP_EOL;
                var_dump($status);
                return false;
            }
            echo 'Server doesn\'t support partial content!' . PHP_EOL;
            // Content received is whole file from start
            if ($blockNo != 0) {
                // move block to start if needed
                $this->buffer[0] =& $this->buffer[$blockNo];
                unset($this->buffer[$blockNo]);
                $blockNo = 0;
            }
            // receive remaining parts while we're at it
            while (!feof($filePtr)) {
                $blockNo++;
                $this->buffer[$blockNo] = fread($filePtr, $this->blockSize);
            }
        }
        fclose($filePtr);
        return true;
    }
}
