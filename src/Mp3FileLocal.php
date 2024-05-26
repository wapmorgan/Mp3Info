<?php

namespace wapmorgan\Mp3Info;

class Mp3FileLocal
{
    public string $fileName;

    protected int $fileSize;

    private $_filePtr;

    /**
     * Creates a new local file object.
     *
     * @param string $fileName URL to open
     */
    public function __construct(string $fileName)
    {
        $this->fileName = $fileName;
        $this->_filePtr = fopen($this->fileName, 'rb');
        $this->fileSize = filesize($this->fileName);
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
     * Returns the given amount of Bytes from the current file position.
     *
     * @param int $numBytes Bytes to read
     *
     * @return string Read Bytes
     */
    public function getBytes(int $numBytes): string
    {
        return fread($this->_filePtr, $numBytes);
    }

    /**
     * Returns the current file position
     *
     * @return int File position
     */
    public function getFilePos(): int
    {
        return ftell($this->_filePtr);
    }

    /**
     * Sets the file point to the given position.
     *
     * @param int $posBytes Position to jump to
     *
     * @return bool TRUE if successful
     */
    public function seekTo(int $posBytes): bool
    {
        $result = fseek($this->_filePtr, $posBytes);
        return ($result == 0);
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
        $newPos = $this->getFilePos() + $posBytes;
        return $this->seekTo($newPos);
    }

}
