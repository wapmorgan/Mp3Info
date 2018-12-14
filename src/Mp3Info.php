<?php
namespace wapmorgan\Mp3Info;

use Exception;

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
 * * {@link http://gabriel.mp3-tech.org/mp3infotag.html Xing, Info and Lame tags specifications}
 */
class Mp3Info {
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
    const LAYER_1 = 1;
    const LAYER_2 = 2;

    const LAYER_3 = 3;
    const STEREO = 'stereo';
    const JOINT_STEREO = 'joint_stereo';
    const DUAL_MONO = 'dual_mono';

    const MONO = 'mono';

    /**
     * Boolean trigger to enable / disable trace output
     */
    static public $traceOutput = false;
    /**
     * @var array
     */
    static private $_bitRateTable;

    /**
     * @var array
     */
    static private $_sampleRateTable;
    /**
     * MPEG codec version (1 or 2)
     * @var int
     */
    public $codecVersion;

    /**
     * Audio layer version (1 or 2 or 3)
     * @var int
     */
    public $layerVersion;
    /**
     * Audio size in bytes. Note that this value is NOT equals file size.
     * @var int
     */
    public $audioSize;
    /**
     * Contains audio file name
     * @var string
     */
    public $_fileName;
    /**
     * Contains file size
     * @var int
     */
    public $_fileSize;
    /**
     * Audio duration in seconds.microseconds (e.g. 3603.0171428571)
     * @var float
     */
    public $duration;
    /**
     * Audio bit rate in bps (e.g. 128000)
     */
    public $bitRate;
    /**
     * Audio sample rate in Hz (e.g. 44100)
     * @var int
     */
    public $sampleRate;
    /**
     * Contains true if audio has variable bit rate
     * @var boolean
     */
    public $isVbr = false;
    /**
     * Channel mode (stereo or dual_mono or joint_stereo or mono)
     * @var string
     */
    public $channel;
    /**
     * Number of audio frames in file
     * @var int
     */
    public $framesCount = 0;
    /**
     * Contains extra flags
     * @var array
     */
    public $extraFlags = array();
    /**
     * Audio tags ver. 1 (aka id3v1)
     * @var array
     */
    public $tags1 = array();
    /**
     * Audio tags ver. 2 (aka id3v2)
     * @var array
     */
    public $tags2 = array();
    /**
     * Major version of id3v2 tag (if id3v2  present) (2 or 3 or 4)
     * @var int
     */
    public $id3v2MajorVersion;
    /**
     * Minor version of id3v2 tag (if id3v2  present)
     * @var int
     */
    public $id3v2MinorVersion;
    /**
     * List of id3v2 header flags (if id3v2  present)
     * @var array
     */
    public $id3v2Flags = array();
    /**
     * List of id3v2 tags flags (if id3v2 present)
     * @var array
     */
    public $id3v2TagsFlags = array();

    /**
     * Contains time spent to read&extract audio information.
     * @var float
     */
    public $_parsingTime;

    /**
     * Calculated frame size for Constant Bit Rate
     * @var int
     */
    private $__cbrFrameSize;

    /**
     * $mode is self::META, self::TAGS or their combination.
     *
     * @param string $filename
     * @param bool $parseTags
     *
     * @throws \Exception
     */
    public function __construct($filename, $parseTags = false) {
        if (self::$_bitRateTable === null)
            self::$_bitRateTable = require dirname(__FILE__).'/../data/bitRateTable.php';
        if (self::$_sampleRateTable === null)
            self::$_sampleRateTable = require dirname(__FILE__).'/../data/sampleRateTable.php';

        if (!file_exists($filename))
            throw new \Exception('File '.$filename.' is not present!');
        $mode = $parseTags ? self::META | self::TAGS : self::META;
        $this->audioSize = $this->parseAudio($this->_fileName = $filename, $this->_fileSize = filesize($filename), $mode);
    }

    /**
     * Reads audio file in binary mode.
     * mpeg audio file structure:
     * ID3V2 TAG - provides a lot of meta data. [optional]
     * MPEG AUDIO FRAMES - contains audio data. A frame consists of a frame header and a frame data. The first frame may contain extra information about mp3 (marked with "Xing" or "Info" string). Rest of frames can contain only audio data.
     * ID3V1 TAG - provides a few of meta data. [optional]
     * @param $filename
     * @param $fileSize
     * @param $mode
     * @return float|int
     * @throws \Exception
     */
    private function parseAudio($filename, $fileSize, $mode) {
        $time = microtime(true);
        $fp = fopen($filename, 'rb');

        /** Size of audio data (exclude tags size)
         * @var int */
        $audioSize = $fileSize;

        // parse tags
        if (fread($fp, 3) == self::TAG2_SYNC) {
            if ($mode & self::TAGS) $audioSize -= ($id3v2Size = $this->readId3v2Body($fp));
            else {
                fseek($fp, 2, SEEK_CUR); // 2 bytes of tag version
                fseek($fp, 1, SEEK_CUR); // 1 byte of tag flags
                $sizeBytes = $this->readBytes($fp, 4);
                array_walk($sizeBytes, function (&$value) {
                    $value = substr(str_pad(base_convert($value, 10, 2), 8, 0, STR_PAD_LEFT), 1);
                });
                $size = bindec(implode(null, $sizeBytes)) + 10;
                $audioSize -= ($id3v2Size = $size);
            }
        }
        fseek($fp, $fileSize - 128);
        if (fread($fp, 3) == self::TAG1_SYNC) {
            if ($mode & self::TAGS) $audioSize -= $this->readId3v1Body($fp);
            else $audioSize -= 128;
        }

        fseek($fp, 0);
        // audio meta
        if ($mode & self::META) {
            if (isset($id3v2Size)) fseek($fp, $id3v2Size);
            /**
             * First frame can lie. Need to fix in future.
             * @link https://github.com/wapmorgan/Mp3Info/issues/13#issuecomment-447470813
             */
            $framesCount = $this->readFirstFrame($fp);

            $this->framesCount = $framesCount !== null
                ? $framesCount
                : ceil($audioSize / $this->__cbrFrameSize);

            // recalculate average bit rate in vbr case
            if ($this->isVbr && !is_null($framesCount)) {
                $avgFrameSize = $audioSize / $framesCount;
                $this->bitRate = $avgFrameSize * $this->sampleRate / (1000 * $this->layerVersion == 3 ? 12 : 144);
            }

            // The faster way to detect audio duration:
            // Calculate total number of audio samples (framesCount * sampleInFrameCount) / samplesInSecondCount
            $this->duration = ($this->framesCount - 1)
                * ($this->layerVersion == 1 ? self::LAYER_1_FRAME_SIZE : self::LAYERS_23_FRAME_SIZE)
                / $this->sampleRate;
        }
        fclose($fp);

        $this->_parsingTime = microtime(true) - $time;
        return $audioSize;
    }

    /**
     * Read first frame information.
     * @param resource $fp
     * @return int Number of frames (if present if first frame)
     * @throws \Exception
     */
    private function readFirstFrame($fp) {
        $pos = ftell($fp);
        $headerBytes = $this->readBytes($fp, 4);

        // if bytes are null, search for something else 2048 bytes forward
        if ($headerBytes[0] !== 0xFF) {
            $limit_pos = $pos + 2048;
            do {
                $pos = ftell($fp);
                $bytes = $this->readBytes($fp, 1);
                if ($bytes[0] === 0xFF) {
                    fseek($fp, $pos);
                    $headerBytes = $this->readBytes($fp, 4);
                    break;
                }
            } while (ftell($fp) < $limit_pos);
        }

        if ($headerBytes[0] !== 0xFF || (($headerBytes[1] >> 5) & 0b111) != 0b111) throw new \Exception("At 0x".$pos."(".dechex($pos).") should be the first frame header!");

        switch ($headerBytes[1] >> 3 & 0b11) {
            case 0b10: $this->codecVersion = self::MPEG_2; break;
            case 0b11: $this->codecVersion = self::MPEG_1; break;
        }

        switch ($headerBytes[1] >> 1 & 0b11) {
            case 0b01: $this->layerVersion = self::LAYER_3; break;
            case 0b10: $this->layerVersion = self::LAYER_2; break;
            case 0b11: $this->layerVersion = self::LAYER_1; break;
        }
        $this->bitRate = self::$_bitRateTable[$this->codecVersion][$this->layerVersion][$headerBytes[2] >> 4];
        $this->sampleRate = self::$_sampleRateTable[$this->codecVersion][bindec($headerBytes[2] >> 2 & 0b11)];

        switch ($headerBytes[3] >> 6) {
            case 0b00: $this->channel = self::STEREO; break;
            case 0b01: $this->channel = self::JOINT_STEREO; break;
            case 0b10: $this->channel = self::DUAL_MONO; break;
            case 0b11: $this->channel = self::MONO; break;
        }

        switch ($this->codecVersion.($this->channel == self::MONO ? 'mono' : 'stereo')) {
            case '1stereo': $offset = 36; break;
            case '1mono': $offset = 21; break;
            case '2stereo': $offset = 21; break;
            case '2mono': $offset = 13; break;
        }
        fseek($fp, $pos + $offset);
        if (fread($fp, 4) == self::VBR_SYNC) {
            $this->isVbr = true;
            $flagsBytes = $this->readBytes($fp, 4);
            $this->extraFlags['frames'] = (bool)($flagsBytes[3] & 1);
            $this->extraFlags['bytes'] = (bool)($flagsBytes[3] & 2);
            $this->extraFlags['TOC'] = (bool)($flagsBytes[3] & 4);
            $this->extraFlags['VBR'] = (bool)($flagsBytes[3] & 8);
            if ($this->extraFlags['frames']) $framesCount = implode(null, unpack('N', fread($fp, 4)));
        }
        // go to the end of frame
        if ($this->layerVersion == 1) {
            $this->__cbrFrameSize = floor((12 * $this->bitRate / $this->sampleRate + ($headerBytes[2] >> 1 & 0b1)) * 4);
        } else {
            $this->__cbrFrameSize = floor(144 * $this->bitRate / $this->sampleRate + ($headerBytes[2] >> 1 & 0b1));
        }
        fseek($fp, $pos + $this->__cbrFrameSize);

        return isset($framesCount) ? $framesCount : null;
    }

    /**
     * @param $fp
     * @param $n
     *
     * @return array
     * @throws \Exception
     */
    private function readBytes($fp, $n) {
        $raw = fread($fp, $n);
        if (strlen($raw) !== $n) throw new \Exception('Unexpected end of file!');
        $bytes = array();
        for($i = 0; $i < $n; $i++) $bytes[$i] = ord($raw[$i]);
        return $bytes;
    }

    /**
     * Reads id3v1 tag.
     * @return int Returns length of id3v1 tag.
     */
    private function readId3v1Body($fp) {
        $this->tags1['song'] = trim(fread($fp, 30));
        $this->tags1['artist'] = trim(fread($fp, 30));
        $this->tags1['album'] = trim(fread($fp, 30));
        $this->tags1['year'] = trim(fread($fp, 4));
        $this->tags1['comment'] = trim(fread($fp, 28));
        fseek($fp, 1, SEEK_CUR);
        $this->tags1['track'] = ord(fread($fp, 1));
        $this->tags1['genre'] = ord(fread($fp, 1));
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
    private function readId3v2Body($fp) {
        // read the rest of the id3v2 header
        $raw = fread($fp, 7);
        $data = unpack('cmajor_version/cminor_version/H*', $raw);
        $this->id3v2MajorVersion = $data['major_version'];
        $this->id3v2MinorVersion = $data['minor_version'];
        $data = str_pad(base_convert($data[1], 16, 2), 40, 0, STR_PAD_LEFT);
        $flags = substr($data, 0, 8);
        if ($this->id3v2MajorVersion == 2) { // parse id3v2.2.0 header flags
            $this->id3v2Flags = array(
                'unsynchronisation' => (bool)substr($flags, 0, 1),
                'compression' => (bool)substr($flags, 1, 1),
            );
        } else if ($this->id3v2MajorVersion == 3) { // parse id3v2.3.0 header flags
            $this->id3v2Flags = array(
                'unsynchronisation' => (bool)substr($flags, 0, 1),
                'extended_header' => (bool)substr($flags, 1, 1),
                'experimental_indicator' => (bool)substr($flags, 2, 1),
            );
            if ($this->id3v2Flags['extended_header'])
                throw new \Exception('NEED TO PARSE EXTENDED HEADER!');
        } else if ($this->id3v2MajorVersion == 4) { // parse id3v2.4.0 header flags
            /*throw new \Exception('NEED TO PARSE id3v2.4.0 header flags!');*/
            {}
        }
        $size = substr($data, 8, 32);

        // some fucking shit
        // getting only 7 of 8 bits of size bytes
        $sizes = str_split($size, 8);
        array_walk($sizes, function (&$value) { $value = substr($value, 1);});
        $size = implode("", $sizes);
        $size = bindec($size);

        if ($this->id3v2MajorVersion == 2)  // parse id3v2.2.0 body
            /*throw new \Exception('NEED TO PARSE id3v2.2.0 flags!');*/
            {}
        else if ($this->id3v2MajorVersion == 3) // parse id3v2.3.0 body
            $this->parseId3v23Body($fp, 10 + $size);
        else if ($this->id3v2MajorVersion == 4)  // parse id3v2.4.0 body
            /*throw new \Exception('NEED TO PARSE id3v2.4.0 flags!');*/
            {}

        return 10 + $size; // 10 bytes - header, rest - body
    }

    /**
     * Parses id3v2.3.0 tag body.
     * @todo Complete.
     */
    private function parseId3v23Body($fp, $lastByte) {
        while (ftell($fp) < $lastByte) {
            $raw = fread($fp, 10);
            $frame_id = substr($raw, 0, 4);

            if ($frame_id == str_repeat(chr(0), 4)) {
                fseek($fp, $lastByte);
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
                    $this->tags2[$frame_id] = $this->handleTextFrame($frame_size, fread($fp, $frame_size));
                    break;
                // case 'TBPM':    # BPM (beats per minute)
                // case 'TCOM':    # Composer
                // case 'TCOP':    # Copyright message
                // case 'TDAT':    # Date
                // case 'TDLY':    # Playlist delay
                // case 'TENC':    # Encoded by
                // case 'TEXT':    # Lyricist/Text writer
                // case 'TFLT':    # File type
                // case 'TIME':    # Time
                // case 'TIT1':    # Content group description
                // case 'TIT3':    # Subtitle/Description refinement
                // case 'TKEY':    # Initial key
                // case 'TLAN':    # Language(s)
                // case 'TLEN':    # Length
                // case 'TMED':    # Media type
                // case 'TOAL':    # Original album/movie/show title
                // case 'TOFN':    # Original filename
                // case 'TOLY':    # Original lyricist(s)/text writer(s)
                // case 'TOPE':    # Original artist(s)/performer(s)
                // case 'TORY':    # Original release year
                // case 'TOWN':    # File owner/licensee
                // case 'TPE2':    # Band/orchestra/accompaniment
                // case 'TPE3':    # Conductor/performer refinement
                // case 'TPE4':    # Interpreted, remixed, or otherwise modified by
                // case 'TPOS':    # Part of a set
                // case 'TPUB':    # Publisher
                // case 'TRDA':    # Recording dates
                // case 'TRSN':    # Internet radio station name
                // case 'TRSO':    # Internet radio station owner
                // case 'TSIZ':    # Size
                // case 'TSRC':    # ISRC (international standard recording code)
                // case 'TSSE':    # Software/Hardware and settings used for encoding

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
                    $dataEnd = ftell($fp) + $frame_size;
                    $raw = fread($fp, 4);
                    $data = unpack('C1encoding/A3language', $raw);
                    // read until \null character
                    $short_description = null;
                    $last_null = false;
                    $actual_text = false;
                    while (ftell($fp) < $dataEnd) {
                        $char = fgetc($fp);
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
                // case 'APIC':    # Attached picture
                //     break;
                // case 'GEOB':    # General encapsulated object
                //     break;
                case 'PCNT':    # Play counter
                    $data = unpack('L', fread($fp, $frame_size));
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
                    fseek($fp, $frame_size, SEEK_CUR);
                    break;
            }
        }
    }

    /**
     * Simple function that checks mpeg-audio correctness of given file.
     * Actually it checks that first 3 bytes of file is a id3v2 tag mark or
     * that first 11 bits of file is a frame header sync mark. To perform full
     * test create an instance of Mp3Info with given file.
     *
     * @param string $filename File to be tested.
     *
     * @return boolean True if file is looks correct, False otherwise.
     * @throws \Exception
     */
    static public function isValidAudio($filename) {
        if (!file_exists($filename))
            throw new Exception('File '.$filename.' is not present!');
        $raw = file_get_contents($filename, false, null, 0, 3);
        return ($raw == self::TAG2_SYNC || (self::FRAME_SYNC == (unpack('n*', $raw)[1] & self::FRAME_SYNC)));
    }

    /**
     * @param $frameSize
     * @param $raw
     *
     * @return array
     */
    private function handleTextFrame($frameSize, $raw)
    {
        $data = unpack('C1encoding/A' . ($frameSize - 1) . 'information', $raw);

        if ($data['encoding'] == 0x00) # ISO-8859-1
            return mb_convert_encoding($data['information'], 'utf-8', 'iso-8859-1');
        else # utf-16
            return mb_convert_encoding($data['information']."\00", 'utf-8', 'utf-16');
    }
}
