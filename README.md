# Mp3Info
The fastest PHP library to get mp3 tags&meta.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/mp3info/v/stable)](https://packagist.org/packages/wapmorgan/mp3info)
[![Total Downloads](https://poser.pugx.org/wapmorgan/mp3info/downloads)](https://packagist.org/packages/wapmorgan/mp3info)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/mp3info/v/unstable)](https://packagist.org/packages/wapmorgan/mp3info)
[![License](https://poser.pugx.org/wapmorgan/mp3info/license)](https://packagist.org/packages/wapmorgan/mp3info)

This class extracts information from mpeg/mp3 audio:

- Audio information:
	- Duration
	- Bit Rate
	- Sample Rate
	- Channels mode
	- Codec and Layer version
	- Frames count
- Audio image (cover)
- Audio tags:

| tag     | id3v1   | id3v2 |
|---------|---------|-------|
| song    | song    | TIT2  |
| artist  | artist  | TPE1  |
| album   | album   | TALB  |
| year    | year    | TYER  |
| comment | comment | COMM  |
| track   | track   | TRCK  |
| genre   | genre   | TCON  |

# Content
1. [Usage](#usage)
2. [Performance](#performance)
3. [Console scanner](#console-scanner)
4. [API](#api)
	- Audio information
	- Class methods
	- Settings
4. [Technical information](#technical-information)

## Usage
After creating an instance of `Mp3Info` with passing filename as the first argument to the constructor, you can retrieve data from object properties (listed below).

```php
use wapmorgan\Mp3Info\Mp3Info;
// To get basic audio information
$audio = new Mp3Info('./audio.mp3');

// If you need parse tags, you should set 2nd argument this way:
$audio = new Mp3Info('./audio.mp3', true);
```

And after that access object properties to get audio information:

```php
echo 'Audio duration: '.floor($audio->duration / 60).' min '.floor($audio->duration % 60).' sec'.PHP_EOL;
echo 'Audio bitrate: '.($audio->bitRate / 1000).' kb/s'.PHP_EOL;
// and so on ...
```

To access id3v1 tags use `$tags1` property.
To access id3v2 tags use `$tags2` property.
Also, you can use combined list of tags `$tags`, where id3v2 and id3v1 tags united with id3v1 keys.

```php
// simple id3v1 tags
echo 'Song '.$audio->tags1['song'].' from '.$audio->tags1['artist'].PHP_EOL;
// specific id3v2 tags
echo 'Song '.$audio->tags2['TIT2'].' from '.$audio->tags2['TPE1'].PHP_EOL;

// combined tags (simplies way to get as more information as possible)
echo 'Song '.$audio->tags['song'].' from '.$audio->tags['artist'].PHP_EOL;
```

## Performance

* Typically it parses one mp3-file with size around 6-7 mb in less than 0.001 sec.
* List of 112 files with constant & variable bitRate with total duration 5:22:28 are parsed in 1.76 sec. *getId3* library against exactly the same mp3 list works for 8x-10x slower - 9.9 sec.
* If you want, there's a very easy way to compare. Just install `nass600/get-id3` package and run console scanner against any folder with audios. It will print time that Mp3Info spent and that getId3.

## Console scanner
To test Mp3Info you can use built-in script that scans dirs and analyzes all mp3-files inside them. To launch script against current folder:

```bash
php bin/scan ./
```

## API

### Audio information

| Property           | Description                                                         | Values                                                      |
|--------------------|---------------------------------------------------------------------|-------------------------------------------------------------|
| `$codecVersion`    | MPEG codec version                                                  | 1 or 2                                                      |
| `$layerVersion`    | Audio layer version                                                 | 1 or 2 or 3                                                 |
| `$audioSize`       | Audio size in bytes. Note that this value is NOT equals file size.  | *int*                                                       |
| `$duration`        | Audio duration in seconds.microseconds                              | like 3603.0171428571 (means 1 hour and 3 sec)               |
| `$bitRate`         | Audio bit rate in bps                                               | like 128000 (means 128kb/s)                                 |
| `$sampleRate`      | Audio sample rate in Hz                                             | like 44100 (means 44.1KHz)                                  |
| `$isVbr`           | Contains `true` if audio has variable bit rate                      | *boolean*                                                   |
| `$hasCover`        | Contains `true` if audio has a bundled image                        | *boolean*                                                   |
| `$channel`         | Channel mode                                                        | `'stereo'` or `'dual_mono'` or `'joint_stereo'` or `'mono'` |
| `$tags1`           | Audio tags ver. 1 (aka id3v1).                                      | ["song" => "Song name", "year" => 2009]                     |
| `$tags2`           | Audio tags ver. 2 (aka id3v2), only text ones.                      | ["TIT2" => "Long song name", ...]                           |
| `$tags`            | Combined audio tags (from id3v1 & id3v2). Keys as in tags1.         | ["song" => "Long song name", "year" => 2009, ...]           |
| `$coverProperties` | Information about a bundled with audio image.                       | ["mime_type" => "image/jpeg", "picture_type" => 1, ...]     |
| `$_parsingTime`    | Contains time spent to read&extract audio information in *sec.msec* |                                                             |

### Class methods
- `$audio = new Mp3Info($filename, $parseTags = false)`
    Creates new instance of object and initiate parsing. If you need to parse audio tags (id3v1 and id3v2), pass `true` as second argument is.

- `$audio->getCover()`
	Returns raw content of bundled with audio image.

- `Mp3Info::isValidAudio($filename)`
    Static method that checks if file `$filename` looks like a mp3-file. Returns `true` if file looks like a mp3, otherwise false.

### Settings
You can adjust some variables to reconfigure before instantiating of object:

- `Mp3Info::$headerSeekLimit` - count of bytes to search for the first mpeg header in audio. Default: `2048` (bytes).
- `Mp3Info::$framesCountRead` - count of mpeg frames to read before compute audio duration. Default: `2` (frames). 

## Technical information
Supporting features:
* id3v1
* id3v2.3.0, id3v2.4.0
* CBR, Variable Bit Rate (VBR)

Used sources:
* [mpeg header description](http://mpgedit.org/mpgedit/mpeg_format/mpeghdr.htm)
* [id3v2 tag specifications](http://id3.org/Developer%20Information). Concretely: [id3v2.3.0](http://id3.org/id3v2.3.0), [id3v2.2.0](http://id3.org/id3v2-00), [id3v2.4.0](http://id3.org/id3v2.4.0-changes)
* [Descripion of VBR header "Xing"](https://multimedia.cx/mp3extensions.txt)
* [Xing, Info and Lame tags specifications](http://gabriel.mp3-tech.org/mp3infotag.html)
