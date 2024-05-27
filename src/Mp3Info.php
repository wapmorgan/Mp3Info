<?php

namespace wapmorgan\Mp3Info;

require __DIR__ . '/Mp3FileLocal.php';
require __DIR__ . '/Mp3FileRemote.php';

use \wapmorgan\Mp3Info\Mp3FileLocal;
use \wapmorgan\Mp3Info\Mp3FileRemote;
use \Exception;
use \RuntimeException;

/**
 * This class extracts information about an mpeg audio. (supported mpeg versions: MPEG-1, MPEG-2)
 * (supported mpeg audio layers: 1, 2, 3).
 *
 * It extracts:
 * * All tags stored in both at the beginning and at the end of file (id3v2 and id3v1). id3v2.4.0 and id3v2.2.0 are not supported, only the most popular id3v2.3.0 is supported.
 * * Audio parameters:
 * * * - Total duration (in seconds)
 * * * - BitRate (in bps)
 * * * - SampleRate (in Hz)
 * * * - Number of channels (stereo or not)
 * * * - ... and other information
 *
 * Used sources:
 * * {@link http://mpgedit.org/mpgedit/mpeg_format/mpeghdr.htm mpeg header description}
 * * {@link http://id3.org/Developer%20Information id3v2 tag specifications}. Specially: {@link http://id3.org/id3v2.3.0 id3v2.3.0}, {@link http://id3.org/id3v2-00 id3v2.2.0}, {@link http://id3.org/id3v2.4.0-changes id3v2.4.0}
 * * {@link https://multimedia.cx/mp3extensions.txt Descripion of VBR header "Xing"}
 * * {@link http://gabriel.mp3-tech.org/mp3infotag.html Xing, Info and Lame tags specifications}
 */
class Mp3Info
{
    const TAG1_SYNC = 'TAG';
    const TAG2_SYNC = 'ID3';
    const VBR_SYNC = 'Xing';
    const CBR_SYNC = 'Info';

    /**
     * Magic constants
     */
    const FRAME_SYNC = 0xffe0;
    const LAYER_1_FRAME_SIZE = 384;
    const LAYERS_23_FRAME_SIZE = 1152;

    const META = 1;
    const TAGS = 2;

    const MPEG_1 = 1;
    const MPEG_2 = 2;
    const MPEG_25 = 3;
    const CODEC_UNDEFINED = 4;

    const LAYER_1 = 1;
    const LAYER_2 = 2;
    const LAYER_3 = 3;

    const STEREO = 'stereo';
    const JOINT_STEREO = 'joint_stereo';
    const DUAL_MONO = 'dual_mono';
    const MONO = 'mono';

    /**
     * @var array
     */
    private static $_bitRateTable;

    /**
     * @var array
     */
    private static $_sampleRateTable;

    /**
     * @var array
     */
    private static $_vbrOffsets = [
        self::MPEG_1 => [21, 36],
        self::MPEG_2 => [13, 21],
        self::MPEG_25 => [13, 21],
    ];

    /**
     * @var int Limit in bytes for seeking a mpeg header in file
     */
    public static $headerSeekLimit = 2048;

    public static $framesCountRead = 2;

    /**
     * @var Mp3File File object for I/O handling
     */
    protected $fileObj;

    /**
     * @var int MPEG codec version (1 or 2 or 2.5 or undefined)
     */
    public $codecVersion;

    /**
     * @var int Audio layer version (1 or 2 or 3)
     */
    public $layerVersion;

    /**
     * @var int Audio size in bytes. Note that this value is NOT equals file size.
     */
    public $audioSize;

    /**
     * @var float Audio duration in seconds.microseconds (e.g. 3603.0171428571)
     */
    public $duration;

    /**
     * @var int Audio bit rate in bps (e.g. 128000)
     */
    public $bitRate;

    /**
     * @var int Audio sample rate in Hz (e.g. 44100)
     */
    public $sampleRate;

    /**
     * @var boolean Contains true if audio has variable bit rate
     */
    public $isVbr = false;

    /**
     * @var boolean Contains true if audio has cover
     */
    public $hasCover = false;

    /**
     * @var array Contains VBR properties
     */
    public $vbrProperties = [];

    /**
     * @var array Contains picture properties
     */
    public $coverProperties = [];

    /**
     * Channel mode (stereo or dual_mono or joint_stereo or mono)
     * @var string
     */
    public $channel;

    /**
     * @var array Unified list of tags (id3v1 and id3v2 united)
     */
    public $tags = [];

    /**
     * @var array Audio tags ver. 1 (aka id3v1)
     */
    public $tags1 = [];

    /**
     * @var array Audio tags ver. 2 (aka id3v2)
     */
    public $tags2 = [];

    /**
     * @var int Major version of id3v2 tag (if id3v2  present) (2 or 3 or 4)
     */
    public $id3v2MajorVersion;
    public $id3v2Version;

    /**
     * @var int Minor version of id3v2 tag (if id3v2 present)
     */
    public $id3v2MinorVersion;
    public $id3v2Revision;

    /**
     * @var array List of id3v2 header flags (if id3v2 present)
     */
    public $id3v2Flags = [];

    /**
     * @var array List of id3v2 tags flags (if id3v2 present)
     */
    public $id3v2TagsFlags = [];

    /**
     * @var string Contains audio file name
     */
    public $_fileName;

    /**
     * @var int Contains file size
     */
    public $_fileSize;

    /**
     * @var int Number of audio frames in file
     */
    public $_framesCount = 0;

    /**
     * @var float Contains time spent to read&extract audio information.
     */
    public $_parsingTime;

    /**
     * @var int Calculated frame size for Constant Bit Rate
     */
    private $_cbrFrameSize;

    /**
     * @var int|null Size of id3v2-data
     */
    public $_id3Size;

    /**
     * $mode is self::META, self::TAGS or their combination.
     *
     * @param string $filename
     * @param bool $parseTags
     *
     * @throws \Exception
     */
    public function __construct(string $filename, bool $parseTags = false)
    {
        if (self::$_bitRateTable === null)
            self::$_bitRateTable = require __DIR__.'/../data/bitRateTable.php';
        if (self::$_sampleRateTable === null)
            self::$_sampleRateTable = require __DIR__.'/../data/sampleRateTable.php';

        $this->_fileName = $filename;
        if (str_contains($filename, '://')) {
            $this->fileObj = new Mp3FileRemote($filename);
        } else {
            $this->fileObj = new Mp3FileLocal($filename);
        }
        $this->_fileSize = $this->fileObj->getFileSize();

        $mode = $parseTags ? self::META | self::TAGS : self::META;
        $this->audioSize = $this->parseAudio($mode);
    }
    

    /**
     * @return bool|null|string
     */
    public function getCover()
    {
        if (empty($this->coverProperties)) {
            return null;
        }

        $curPos = $this->fileObj->getFilePos();
        $this->fileObj->seekTo($this->coverProperties['offset']);
        $data = $this->fileObj->getBytes($this->coverProperties['size']);
        $this->fileObj->seekTo($curPos);
        return $data;
    }

    protected function getSyncsafeSize(string $rawBytes): int
    {
        $sizeBytes = unpack('C4', $rawBytes);
        $size = $sizeBytes[1] << 21 | $sizeBytes[2] << 14 | $sizeBytes[3] << 7 | $sizeBytes[4];
        return $size;
    }

    /**
     * Reads audio file in binary mode.
     * mpeg audio file structure:
     * ID3V2 TAG - provides a lot of meta data. [optional]
     * MPEG AUDIO FRAMES - contains audio data. A frame consists of a frame header and a frame data. The first frame may contain extra information about mp3 (marked with "Xing" or "Info" string). Rest of frames can contain only audio data.
     * ID3V1 TAG - provides a few of meta data. [optional]
     * @param int $mode
     * @return float|int
     * @throws \Exception
     */
    private function parseAudio($mode)
    {
        $time = microtime(true);

        /** @var int Size of audio data (exclude tags size) */
        $audioSize = $this->fileObj->getFileSize();

        // parse tags
        if ($this->fileObj->getBytes(3) == self::TAG2_SYNC) {
            if ($mode & self::TAGS) {
                $audioSize -= ($this->_id3Size = $this->readId3v2Body());
            } else {
                $this->fileObj->seekForward(2); // 2 bytes of tag version
                $this->fileObj->seekForward(1); // 1 byte of tag flags
                $size = $this->getSyncsafeSize($this->fileObj->getBytes(4));
                $size += 10;   // add header size
                $audioSize -= ($this->_id3Size = $size);
            }
        }

        $this->fileObj->seekTo($this->fileObj->getFileSize() - 128);
        if ($this->fileObj->getBytes(3) == self::TAG1_SYNC) {
            if ($mode & self::TAGS) $audioSize -= $this->readId3v1Body();
            else $audioSize -= 128;
        }

        if ($mode & self::TAGS) {
            $this->fillTags();
        }

        $this->fileObj->seekTo(0);
        // audio meta
        if ($mode & self::META) {
            if ($this->_id3Size !== null) $this->fileObj->seekTo($this->_id3Size);
            /**
             * First frame can lie. Need to fix in the future.
             * @link https://github.com/wapmorgan/Mp3Info/issues/13#issuecomment-447470813
             * Read first N frames
             */
            for ($i = 0; $i < self::$framesCountRead; $i++) {
                $framesCount = $this->readMpegFrame();
            }

            $this->_framesCount = $framesCount !== null
                ? $framesCount
                : ceil($audioSize / $this->_cbrFrameSize);

            // recalculate average bit rate in vbr case
            if ($this->isVbr && $framesCount !== null) {
                $avgFrameSize = $audioSize / $framesCount;
                $this->bitRate = $avgFrameSize * $this->sampleRate / (1000 * $this->layerVersion == self::LAYER_3 ? 12 : 144);
            }

            // The faster way to detect audio duration:
            $samples_in_second = $this->layerVersion == 1 ? self::LAYER_1_FRAME_SIZE : self::LAYERS_23_FRAME_SIZE;
            // for VBR: adjust samples in second according to VBR quality
            // disabled for now
//            if ($this->isVbr && isset($this->vbrProperties['quality'])) {
//                $samples_in_second = floor($samples_in_second * $this->vbrProperties['quality'] / 100);
//            }
            // Calculate total number of audio samples (framesCount * sampleInFrameCount) / samplesInSecondCount
            $this->duration = ($this->_framesCount - 1) * $samples_in_second / $this->sampleRate;
        }

        $this->_parsingTime = microtime(true) - $time;
        return $audioSize;
    }

    /**
     * Read first frame information.
     * @return int Number of frames (if present if first frame of VBR-file)
     * @throws \Exception
     */
    private function readMpegFrame()
    {
        $header_seek_pos = $this->fileObj->getFilePos() + self::$headerSeekLimit;
        do {
            $pos = $this->fileObj->getFilePos();
            $first_header_byte = $this->fileObj->getBytes(1);
            if (ord($first_header_byte[0]) === 0xFF) {
                $second_header_byte = $this->fileObj->getBytes(1);
                if (((ord($second_header_byte[0]) >> 5) & 0b111) == 0b111) {
                    $this->fileObj->seekTo($pos);
                    $header_bytes = $this->fileObj->getBytes(4);
                    break;
                } else {
                    $this->fileObj->seekForward(-1);
                }
            } else {
                $this->fileObj->seekForward(-1);
            }
            $this->fileObj->seekForward(1);
        } while ($this->fileObj->getFilePos() <= $header_seek_pos);

        if (!isset($header_bytes) || ord($header_bytes[0]) !== 0xFF || ((ord($header_bytes[1]) >> 5) & 0b111) != 0b111) {
            throw new \Exception('At '.$pos
                .'(0x'.dechex($pos).') should be a frame header!');
        }

        switch (ord($header_bytes[1]) >> 3 & 0b11) {
            case 0b00: $this->codecVersion = self::MPEG_25; break;
            case 0b01: return null; break;
            case 0b10: $this->codecVersion = self::MPEG_2; break;
            case 0b11: $this->codecVersion = self::MPEG_1; break;
        }

        switch (ord($header_bytes[1]) >> 1 & 0b11) {
            case 0b01: $this->layerVersion = self::LAYER_3; break;
            case 0b10: $this->layerVersion = self::LAYER_2; break;
            case 0b11: $this->layerVersion = self::LAYER_1; break;
        }

        $this->bitRate = self::$_bitRateTable[$this->codecVersion][$this->layerVersion][ord($header_bytes[2]) >> 4];
        $this->sampleRate = self::$_sampleRateTable[$this->codecVersion][(ord($header_bytes[2]) >> 2) & 0b11];
        if ($this->sampleRate === false) return null;

        switch (ord($header_bytes[3]) >> 6) {
            case 0b00: $this->channel = self::STEREO; break;
            case 0b01: $this->channel = self::JOINT_STEREO; break;
            case 0b10: $this->channel = self::DUAL_MONO; break;
            case 0b11: $this->channel = self::MONO; break;
        }

        $vbr_offset = self::$_vbrOffsets[$this->codecVersion][$this->channel == self::MONO ? 0 : 1];

        // check for VBR
        $this->fileObj->seekTo($pos + $vbr_offset);
        if ($this->fileObj->getBytes(4) == self::VBR_SYNC) {
            $this->isVbr = true;
            $flagsBytes = $this->fileObj->getBytes(4);

            // VBR frames count presence
            if ((ord($flagsBytes[3]) & 2)) {
                $this->vbrProperties['frames'] = implode(unpack('N', $this->fileObj->getBytes(4)));
            }
            // VBR stream size presence
            if (ord($flagsBytes[3]) & 4) {
                $this->vbrProperties['bytes'] = implode(unpack('N', $this->fileObj->getBytes(4)));
            }
            // VBR TOC presence
            if (ord($flagsBytes[3]) & 1) {
                $this->fileObj->seekForward(100);
            }
            // VBR quality
            if (ord($flagsBytes[3]) & 8) {
                $this->vbrProperties['quality'] = implode(unpack('N', $this->fileObj->getBytes(4)));
            }
        }

        // go to the end of frame
        if ($this->layerVersion == self::LAYER_1) {
            $this->_cbrFrameSize = floor((12 * $this->bitRate / $this->sampleRate + (ord($header_bytes[2]) >> 1 & 0b1)) * 4);
        } else {
            $this->_cbrFrameSize = floor(144 * $this->bitRate / $this->sampleRate + (ord($header_bytes[2]) >> 1 & 0b1));
        }

        $this->fileObj->seekTo($pos + $this->_cbrFrameSize);

        return isset($this->vbrProperties['frames']) ? $this->vbrProperties['frames'] : null;
    }

    /**
     * Reads id3v1 tag.
     * @return int Returns length of id3v1 tag.
     */
    private function readId3v1Body()
    {
        $this->tags1['song'] = trim($this->fileObj->getBytes(30));
        $this->tags1['artist'] = trim($this->fileObj->getBytes(30));
        $this->tags1['album'] = trim($this->fileObj->getBytes(30));
        $this->tags1['year'] = trim($this->fileObj->getBytes(4));
        $this->tags1['comment'] = trim($this->fileObj->getBytes(28));
        $this->fileObj->seekForward(1);
        $this->tags1['track'] = ord($this->fileObj->getBytes(1));
        $this->tags1['genre'] = ord($this->fileObj->getBytes(1));
        return 128;
    }

    /**
     * Reads id3v2 tag.
     * -----------------------------------
     * Overall tag header structure (10 bytes)
     *  ID3v2/file identifier      "ID3" (3 bytes)
     *  ID3v2 version              (2 bytes)
     *  ID3v2 flags                (1 byte)
     *  ID3v2 size             4 * %0xxxxxxx (4 bytes)
     * -----------------------------------
     * id3v2.2.0 tag header (10 bytes)
     *  ID3/file identifier      "ID3" (3 bytes)
     *  ID3 version              $02 00 (2 bytes)
     *  ID3 flags                %xx000000 (1 byte)
     *  ID3 size             4 * %0xxxxxxx (4 bytes)
     * Flags:
     *  x (bit 7) - unsynchronisation
     *  x (bit 6) - compression
     * -----------------------------------
     * id3v2.3.0 tag header (10 bytes)
     *  ID3v2/file identifier   "ID3" (3 bytes)
     *  ID3v2 version           $03 00 (2 bytes)
     *  ID3v2 flags             %abc00000 (1 byte)
     *  ID3v2 size              4 * %0xxxxxxx (4 bytes)
     * Flags:
     *  a - Unsynchronisation
     *  b - Extended header
     *  c - Experimental indicator
     * Extended header structure (10 bytes)
     *  Extended header size   $xx xx xx xx
     *  Extended Flags         $xx xx
     *  Size of padding        $xx xx xx xx
     * Extended flags:
     *  %x0000000 00000000
     *  x - CRC data present
     * -----------------------------------
     * id3v2.4.0 tag header (10 bytes)
     *  ID3v2/file identifier      "ID3" (3 bytes)
     *  ID3v2 version              $04 00 (2 bytes)
     *  ID3v2 flags                %abcd0000 (1 byte)
     *  ID3v2 size             4 * %0xxxxxxx (4 bytes)
     * Flags:
     *  a - Unsynchronisation
     *  b - Extended header
     *  c - Experimental indicator
     *  d - Footer present
     * @param resource $fp
     * @return int Returns length of id3v2 tag.
     * @throws \Exception
     */
    private function readId3v2Body()
    {
        // read the rest of the id3v2 header
        $raw = $this->fileObj->getBytes(3);
        $data = unpack('Cversion/Crevision/Cflags', $raw);
        $this->id3v2Version = $data['version'];
        $this->id3v2Revision = $data['revision'];
        // backwards compatibility:
        $this->id3v2MajorVersion = $data['version'];
        $this->id3v2MinorVersion = $data['revision'];

        $flags = str_pad(decbin($data['flags']), 8, '0', STR_PAD_LEFT);
        $this->id3v2Flags = array();

        if ($this->id3v2Version >= 2) {
            // parse id3v2.2.0 header flags
            $this->id3v2Flags['unsynchronisation'] = (bool)substr($flags, 0, 1);
            $this->id3v2Flags['compression'] = (bool)substr($flags, 1, 1);
        }
        
        if ($this->id3v2Version >= 3) {
            // id3v2.3 changes second bit from compression to extended_header
            $this->id3v2Flags['extended_header'] = &$this->id3v2Flags['compression'];
            unset($this->id3v2Flags['compression']);
            // parse additional id3v2.3.0 header flags
            $this->id3v2Flags['experimental_indicator'] = (bool)substr($flags, 2, 1);

            if ($this->id3v2Flags['extended_header']) {
                throw new Exception('NEED TO PARSE EXTENDED HEADER!');
            }
        }

        if ($this->id3v2Version >= 4) {
            // parse additional id3v2.4.0 header flags
            $this->id3v2Flags['footer_present'] = (bool)substr($flags, 3, 1);

            if ($this->id3v2Flags['footer_present']) {
                throw new Exception('NEED TO PARSE id3v2.4 FOOTER!');
            }
        }

        $size = $this->getSyncsafeSize($this->fileObj->getBytes(4));

        if ($this->id3v2Version == 2) {
            // parse id3v2.2.0 body
            /*throw new \Exception('NEED TO PARSE id3v2.2.0 flags!');*/
        } elseif ($this->id3v2Version == 3) {
            // parse id3v2.3.0 body
            $this->parseId3v23Body(10 + $size);
        } elseif ($this->id3v2Version == 4) {
            // parse id3v2.4.0 body
            $this->parseId3v24Body(10 + $size);
        }

        return 10 + $size; // 10 bytes - header, rest - body
    }

    /**
     * Parses id3v2.3.0 tag body.
     * @todo Complete.
     */
    protected function parseId3v23Body($lastByte)
    {
        while ($this->fileObj->getFilePos() < $lastByte) {
            $raw = $this->fileObj->getBytes(10);
            $frame_id = substr($raw, 0, 4);

            if ($frame_id == str_repeat(chr(0), 4)) {
                $this->fileObj->seekTo($lastByte);
                break;
            }

            $data = unpack('Nframe_size/H2flags', substr($raw, 4));
            $frame_size = $data['frame_size'];
            $flags = base_convert($data['flags'], 16, 2);
            $this->id3v2TagsFlags[$frame_id] = array(
                'flags' => array(
                    'tag_alter_preservation' => (bool)substr($flags, 0, 1),
                    'file_alter_preservation' => (bool)substr($flags, 1, 1),
                    'read_only' => (bool)substr($flags, 2, 1),
                    'compression' => (bool)substr($flags, 8, 1),
                    'encryption' => (bool)substr($flags, 9, 1),
                    'grouping_identity' => (bool)substr($flags, 10, 1),
                ),
            );

            switch ($frame_id) {
                // case 'UFID':    # Unique file identifier
                //     break;

                ################# Text information frames
                case 'TALB':    # Album/Movie/Show title
                case 'TCON':    # Content type
                case 'TYER':    # Year
                case 'TXXX':    # User defined text information frame
                case 'TRCK':    # Track number/Position in set
                case 'TIT2':    # Title/songname/content description
                case 'TPE1':    # Lead performer(s)/Soloist(s)
                case 'TBPM':    # BPM (beats per minute)
                case 'TCOM':    # Composer
                case 'TCOP':    # Copyright message
                case 'TDAT':    # Date
                case 'TDLY':    # Playlist delay
                case 'TENC':    # Encoded by
                case 'TEXT':    # Lyricist/Text writer
                case 'TFLT':    # File type
                case 'TIME':    # Time
                case 'TIT1':    # Content group description
                case 'TIT3':    # Subtitle/Description refinement
                case 'TKEY':    # Initial key
                case 'TLAN':    # Language(s)
                case 'TLEN':    # Length
                case 'TMED':    # Media type
                case 'TOAL':    # Original album/movie/show title
                case 'TOFN':    # Original filename
                case 'TOLY':    # Original lyricist(s)/text writer(s)
                case 'TOPE':    # Original artist(s)/performer(s)
                case 'TORY':    # Original release year
                case 'TOWN':    # File owner/licensee
                case 'TPE2':    # Band/orchestra/accompaniment
                case 'TPE3':    # Conductor/performer refinement
                case 'TPE4':    # Interpreted, remixed, or otherwise modified by
                case 'TPOS':    # Part of a set
                case 'TPUB':    # Publisher
                case 'TRDA':    # Recording dates
                case 'TRSN':    # Internet radio station name
                case 'TRSO':    # Internet radio station owner
                case 'TSIZ':    # Size
                case 'TSRC':    # ISRC (international standard recording code)
                case 'TSSE':    # Software/Hardware and settings used for encoding
                    $this->tags2[$frame_id] = $this->handleTextFrame($frame_size, $this->fileObj->getBytes($frame_size));
                    break;
                ################# Text information frames

                ################# URL link frames
                // case 'WCOM':    # Commercial information
                //     break;
                // case 'WCOP':    # Copyright/Legal information
                //     break;
                // case 'WOAF':    # Official audio file webpage
                //     break;
                // case 'WOAR':    # Official artist/performer webpage
                //     break;
                // case 'WOAS':    # Official audio source webpage
                //     break;
                // case 'WORS':    # Official internet radio station homepage
                //     break;
                // case 'WPAY':    # Payment
                //     break;
                // case 'WPUB':    # Publishers official webpage
                //     break;
                // case 'WXXX':    # User defined URL link frame
                //     break;
                ################# URL link frames

                // case 'IPLS':    # Involved people list
                //     break;
                // case 'MCDI':    # Music CD identifier
                //     break;
                // case 'ETCO':    # Event timing codes
                //     break;
                // case 'MLLT':    # MPEG location lookup table
                //     break;
                // case 'SYTC':    # Synchronized tempo codes
                //     break;
                // case 'USLT':    # Unsychronized lyric/text transcription
                //     break;
                // case 'SYLT':    # Synchronized lyric/text
                //     break;
                case 'COMM':    # Comments
                    $dataEnd = $this->fileObj->getFilePos() + $frame_size;
                    $raw = $this->fileObj->getBytes(4);
                    $data = unpack('C1encoding/A3language', $raw);
                    // read until \null character
                    $short_description = '';
                    $last_null = false;
                    $actual_text = false;
                    while ($this->fileObj->getFilePos() < $dataEnd) {
                        $char = $this->fileObj->getBytes(1);
                        if ($char == "\00" && $actual_text === false) {
                            if ($data['encoding'] == 0x1) { # two null-bytes for utf-16
                                if ($last_null)
                                    $actual_text = null;
                                else
                                    $last_null = true;
                            } else # no condition for iso-8859-1
                                $actual_text = null;

                        }
                        else if ($actual_text !== false) $actual_text .= $char;
                        else $short_description .= $char;
                    }
                    if ($actual_text === false) $actual_text = $short_description;
                    // list($short_description, $actual_text) = sscanf("s".chr(0)."s", $data['texts']);
                    // list($short_description, $actual_text) = explode(chr(0), $data['texts']);
                    $this->tags2[$frame_id][$data['language']] = array(
                        'short' => (bool)($data['encoding'] == 0x00) ? mb_convert_encoding($short_description, 'utf-8', 'iso-8859-1') : mb_convert_encoding($short_description, 'utf-8', 'utf-16'),
                        'actual' => (bool)($data['encoding'] == 0x00) ? mb_convert_encoding($actual_text, 'utf-8', 'iso-8859-1') : mb_convert_encoding($actual_text, 'utf-8', 'utf-16'),
                    );
                    break;
                // case 'RVAD':    # Relative volume adjustment
                //     break;
                // case 'EQUA':    # Equalization
                //     break;
                // case 'RVRB':    # Reverb
                //     break;
                 case 'APIC':    # Attached picture
                     $this->hasCover = true;
                     $dataEnd = $this->fileObj->getFilePos() + $frame_size;
                     $this->coverProperties = ['text_encoding' => ord($this->fileObj->getBytes(1))];
//                     fseek($fp, $frame_size - 4, SEEK_CUR);
                     $this->coverProperties['mime_type'] = $this->readTextUntilNull($dataEnd);
                     $this->coverProperties['picture_type'] = ord($this->fileObj->getBytes(1));
                     $this->coverProperties['description'] = $this->readTextUntilNull($dataEnd);
                     $this->coverProperties['offset'] = $this->fileObj->getFilePos();
                     $this->coverProperties['size'] = $dataEnd - $this->fileObj->getFilePos();
                     $this->fileObj->seekTo($dataEnd);
                     break;
                // case 'GEOB':    # General encapsulated object
                //     break;
                case 'PCNT':    # Play counter
                    $data = unpack('L', $this->fileObj->getBytes($frame_size));
                    $this->tags2[$frame_id] = $data[1];
                    break;
                // case 'POPM':    # Popularimeter
                //     break;
                // case 'RBUF':    # Recommended buffer size
                //     break;
                // case 'AENC':    # Audio encryption
                //     break;
                // case 'LINK':    # Linked information
                //     break;
                // case 'POSS':    # Position synchronisation frame
                //     break;
                // case 'USER':    # Terms of use
                //     break;
                // case 'OWNE':    # Ownership frame
                //     break;
                // case 'COMR':    # Commercial frame
                //     break;
                // case 'ENCR':    # Encryption method registration
                //     break;
                // case 'GRID':    # Group identification registration
                //     break;
                // case 'PRIV':    # Private frame
                //     break;
                default:
                    $this->fileObj->seekForward($frame_size);
                    break;
            }
        }
    }

    /**
     * Parses id3v2.4.0 tag body.
     *
     * @param $lastByte
     */
    protected function parseId3v24Body($lastByte)
    {
        while ($this->fileObj->getFilePos() < $lastByte) {
            $frame_id = $this->fileObj->getBytes(4);

            if ($frame_id == str_repeat(chr(0), 4)) {
                $this->fileObj->seekTo($lastByte);
                break;
            }

            $frame_size = $this->getSyncsafeSize($this->fileObj->getBytes(4));

            $data = unpack('H2flags', $this->fileObj->getBytes(2));
            $flags = base_convert($data['flags'], 16, 2);
            $this->id3v2TagsFlags[$frame_id] = array(
                'frame_size' => $frame_size,
                'flags' => array(
                    'tag_alter_preservation' => (bool)substr($flags, 1, 1),
                    'file_alter_preservation' => (bool)substr($flags, 2, 1),
                    'read_only' => (bool)substr($flags, 3, 1),
                    'grouping_identity' => (bool)substr($flags, 9, 1),
                    'compression' => (bool)substr($flags, 12, 1),
                    'encryption' => (bool)substr($flags, 13, 1),
                    'unsynchronisation' => (bool)substr($flags, 14, 1),
                    'data_length_indicator' => (bool)substr($flags, 15, 1),
                ),
            );

            switch ($frame_id) {
                // case 'UFID':    # Unique file identifier
                //     break;

                ################# Text information frames
                case 'TALB':    # Album/Movie/Show title
                case 'TCON':    # Content type
                case 'TYER':    # Year
                case 'TRCK':    # Track number/Position in set
                case 'TIT2':    # Title/songname/content description
                case 'TPE1':    # Lead performer(s)/Soloist(s)
                case 'TBPM':    # BPM (beats per minute)
                case 'TCOM':    # Composer
                case 'TCOP':    # Copyright message
                case 'TDAT':    # Date
                case 'TDRC':    # Recording time
                case 'TDLY':    # Playlist delay
                case 'TENC':    # Encoded by
                case 'TEXT':    # Lyricist/Text writer
                case 'TFLT':    # File type
                case 'TIME':    # Time
                case 'TIT1':    # Content group description
                case 'TIT3':    # Subtitle/Description refinement
                case 'TKEY':    # Initial key
                case 'TLAN':    # Language(s)
                case 'TLEN':    # Length
                case 'TMED':    # Media type
                case 'TOAL':    # Original album/movie/show title
                case 'TOFN':    # Original filename
                case 'TOLY':    # Original lyricist(s)/text writer(s)
                case 'TOPE':    # Original artist(s)/performer(s)
                case 'TORY':    # Original release year
                case 'TOWN':    # File owner/licensee
                case 'TPE2':    # Band/orchestra/accompaniment
                case 'TPE3':    # Conductor/performer refinement
                case 'TPE4':    # Interpreted, remixed, or otherwise modified by
                case 'TPOS':    # Part of a set
                case 'TPUB':    # Publisher
                case 'TRDA':    # Recording dates
                case 'TRSN':    # Internet radio station name
                case 'TRSO':    # Internet radio station owner
                case 'TSIZ':    # Size
                case 'TSRC':    # ISRC (international standard recording code)
                case 'TSSE':    # Software/Hardware and settings used for encoding
                    $this->tags2[$frame_id] = $this->handleTextFrame($frame_size, $this->fileObj->getBytes($frame_size));
                    break;

                case 'TXXX':    # User defined text information frame
                    $dataEnd = $this->fileObj->getFilePos() + $frame_size;
                    $encoding = ord($this->fileObj->getBytes(1));
                    $description_raw = $this->readTextUntilNull($dataEnd);
                    $description = $this->_getUtf8Text($encoding, $description_raw);
                    $value = $this->fileObj->getBytes($dataEnd - $this->fileObj->getFilePos());
                    $tagName = $frame_id . ':' . $description;
                    if (key_exists($tagName, $this->tags2)) {
                        // this should never happen! TXXX-description must be unique.
                        if (!is_array($this->tags2[$tagName])) {
                            $this->tags2[$tagName] = array($this->tags2[$tagName]);
                        }
                        $this->tags2[$tagName][] = $value;
                    } else {
                        $this->tags2[$tagName] = $value;
                    }
                    break;

                ################# Text information frames

                ################# URL link frames
                // case 'WCOM':    # Commercial information
                //     break;
                // case 'WCOP':    # Copyright/Legal information
                //     break;
                // case 'WOAF':    # Official audio file webpage
                //     break;
                // case 'WOAR':    # Official artist/performer webpage
                //     break;
                // case 'WOAS':    # Official audio source webpage
                //     break;
                // case 'WORS':    # Official internet radio station homepage
                //     break;
                // case 'WPAY':    # Payment
                //     break;
                // case 'WPUB':    # Publishers official webpage
                //     break;
                // case 'WXXX':    # User defined URL link frame
                //     break;
                ################# URL link frames

                // case 'IPLS':    # Involved people list
                //     break;
                // case 'MCDI':    # Music CD identifier
                //     break;
                // case 'ETCO':    # Event timing codes
                //     break;
                // case 'MLLT':    # MPEG location lookup table
                //     break;
                // case 'SYTC':    # Synchronized tempo codes
                //     break;
                // case 'USLT':    # Unsychronized lyric/text transcription
                //     break;
                // case 'SYLT':    # Synchronized lyric/text
                //     break;
                case 'COMM':    # Comments
                    $dataEnd = $this->fileObj->getFilePos() + $frame_size;
                    $encoding = unpack('C', $this->fileObj->getBytes(1))[1];
                    $language = $this->fileObj->getBytes(3);
                    $allText_raw = $this->fileObj->getBytes($dataEnd - $this->fileObj->getFilePos());
                    $allText = $this->_getUtf8Text($encoding, $allText_raw);

                    list($short_description, $actual_text) = explode("\0", $allText, 2);

                    $this->tags2[$frame_id][$language] = array(
                        'short' => $short_description,
                        'actual' => $actual_text,
                    );
                    break;
                // case 'RVAD':    # Relative volume adjustment
                //     break;
                // case 'EQUA':    # Equalization
                //     break;
                // case 'RVRB':    # Reverb
                //     break;
                case 'APIC':    # Attached picture
                    $this->hasCover = true;
                    $dataEnd = $this->fileObj->getFilePos() + $frame_size;
                    $this->coverProperties = ['text_encoding' => ord($this->fileObj->getBytes(1))];
//                     $this->fileObj->seekForward($frame_size - 4);
                    $this->coverProperties['mime_type'] = $this->readTextUntilNull($dataEnd);
                    $this->coverProperties['picture_type'] = ord($this->fileObj->getBytes(1));
                    $this->coverProperties['description'] = $this->readTextUntilNull($dataEnd);
                    $this->coverProperties['offset'] = $this->fileObj->getFilePos();
                    $this->coverProperties['size'] = $dataEnd - $this->fileObj->getFilePos();
                    $this->fileObj->seekTo($dataEnd);
                    break;
                // case 'GEOB':    # General encapsulated object
                //     break;
                case 'PCNT':    # Play counter
                    $data = unpack('L', $this->fileObj->getBytes($frame_size));
                    $this->tags2[$frame_id] = $data[1];
                    break;
                // case 'POPM':    # Popularimeter
                //     break;
                // case 'RBUF':    # Recommended buffer size
                //     break;
                // case 'AENC':    # Audio encryption
                //     break;
                // case 'LINK':    # Linked information
                //     break;
                // case 'POSS':    # Position synchronisation frame
                //     break;
                // case 'USER':    # Terms of use
                //     break;
                // case 'OWNE':    # Ownership frame
                //     break;
                // case 'COMR':    # Commercial frame
                //     break;
                // case 'ENCR':    # Encryption method registration
                //     break;
                // case 'GRID':    # Group identification registration
                //     break;
                // case 'PRIV':    # Private frame
                //     break;
                default:
                    $this->fileObj->seekForward($frame_size);
                    break;
            }
        }
    }

    /**
     * Converts text encoding according to ID3 indicator
     *
     * @param int    $encoding Encoding ID from ID3 frame
     * @param string $rawText  Raw text from ID3 frame
     *
     * @return string
     */
    private function _getUtf8Text(int $encoding, string|null $rawText): string
    {
        if (is_null($rawText)) {
            $rawText = '';
        }
        
        switch ($encoding) {
            case 0x00:   // ISO-8859-1
                return mb_convert_encoding($rawText, 'utf-8', 'iso-8859-1');

            case 0x01:   // UTF-16 with BOM
                return mb_convert_encoding($rawText . "\00", 'utf-8', 'utf-16');

            // Following is for id3v2.4.x only
            case 0x02:   // UTF-16 without BOM
                return mb_convert_encoding($rawText . "\00", 'utf-8', 'utf-16');
            case 0x03:   // UTF-8
                return $rawText;

            default:
                throw new RuntimeException('Unknown text encoding type: ' . $encoding);
        }
    }

    /**
     * @param $frameSize
     * @param $raw
     *
     * @return string
     */
    private function handleTextFrame($frameSize, $raw)
    {
        $data = unpack('C1encoding/A' . ($frameSize - 1) . 'information', $raw);
        return $this->_getUtf8Text($data['encoding'], $data['information']);
    }

    /**
     * @param resource $fp
     * @param int $dataEnd
     * @return string|null
     */
    private function readTextUntilNull($dataEnd)
    {
        $text = null;
        while ($this->fileObj->getFilePos() < $dataEnd) {
            $char = $this->fileObj->getBytes(1);
            if ($char === "\00") {
                return $text;
            }
            $text .= $char;
        }
        return $text;
    }

    /**
     * Fills `tags` property with values id3v2 and id3v1 tags.
     */
    protected function fillTags()
    {
        foreach ([
            'song' => 'TIT2',
            'artist' => 'TPE1',
            'album' => 'TALB',
            'year' => 'TYER',
            'comment' => 'COMM',
            'track' => 'TRCK',
            'genre' => 'TCON',
        ] as $tag => $id3v2_tag) {
            if (!isset($this->tags2[$id3v2_tag]) && (!isset($this->tags1[$tag]) || empty($this->tags1[$tag])))
                continue;

            $this->tags[$tag] = isset($this->tags2[$id3v2_tag])
                ? ($id3v2_tag === 'COMM' ? current($this->tags2[$id3v2_tag])['actual'] : $this->tags2[$id3v2_tag])
                : $this->tags1[$tag];
        }
    }

    /**
     * Simple function that checks mpeg-audio correctness of given file.
     * Actually it checks that first 3 bytes of file is a id3v2 tag mark or
     * that first 11 bits of file is a frame header sync mark or that 3 bytes on -128 position of file is id3v1 tag.
     * To perform full test create an instance of Mp3Info with given file.
     *
     * @param string $filename File to be tested.
     * @return boolean True if file looks that correct mpeg audio, False otherwise.
     * @throws \Exception
     */
    public static function isValidAudio($filename)
    {
        if (str_contains($filename, '://')) {
            $fileObj = new Mp3FileRemote($filename);
        } else {
            if (!file_exists($filename)) {
                throw new Exception('File ' . $filename . ' is not present!');
            }
            $fileObj = new Mp3FileLocal($filename);
        }

        $filesize = $fileObj->getFileSize();

        $raw = $fileObj->getBytes(3);
        if ($raw === self::TAG2_SYNC) {
            // id3v2 tag
            return true;
        }
        if (self::FRAME_SYNC === (unpack('n*', $raw)[1] & self::FRAME_SYNC)) {
            // mpeg header tag
            return true;
        }
        if ($filesize > 128) {
            $fileObj->seekTo($filesize - 128);
            if ($fileObj->getBytes(3) === self::TAG1_SYNC) {
                // id3v1 tag
                return true;
            }
        }
        return false;
    }
}