<?php
declare(strict_types=1);

use Bzn\ResourceEngine\ImageTags;
use Bzn\ResourceEngine\ImageTagSource;

require_once __DIR__ . '/../src/ImageTags.php';

/** A test-only stream measures skipped file bytes without adding instrumentation to production code. */
final class ImageTagCountingStream
{
    private const ZERO = 0;
    public mixed $context;
    public static string $bytes = '';
    public static int $readBytes = self::ZERO;
    private int $offset = self::ZERO;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    { $this->offset = self::ZERO; return true; }
    public function stream_read(int $count): string
    {
        $value = substr(self::$bytes, $this->offset, $count);
        $this->offset += strlen($value); self::$readBytes += strlen($value); return $value;
    }
    public function stream_seek(int $offset, int $whence): bool
    {
        $next = $whence === SEEK_SET ? $offset : ($whence === SEEK_CUR ? $this->offset + $offset : strlen(self::$bytes) + $offset);
        if ($next < self::ZERO || $next > strlen(self::$bytes)) return false;
        $this->offset = $next; return true;
    }
    public function stream_tell(): int { return $this->offset; }
    public function stream_eof(): bool { return $this->offset >= strlen(self::$bytes); }
    public function stream_stat(): array { return ['size' => strlen(self::$bytes), 'mode' => 0100000]; }
    public function stream_close(): void {}
}

/** Actual GD images prove decoded pixels as well as byte-exact retained metadata and malformed-input rejection. */
final class ImageTagsContract
{
    private const C = ['zero' => 0, 'one' => 1, 'two' => 2, 'word' => 4, 'eight' => 8, 'twelve' => 12,
        'width' => 3, 'height' => 2, 'jpeg_quality' => 87, 'webp_quality' => 81, 'red' => 210, 'green' => 34,
        'blue' => 87, 'alpha' => 53, 'opaque' => 0, 'png_signature' => "\x89PNG\r\n\x1a\n",
        'maximum_reader_bytes' => 65536, 'large_mask_bytes' => 2097152, 'scheme' => 'bzntagsfixture',
        'empty' => '', 'be_word' => 'N', 'le_word' => 'V', 'be_short' => 'n', 'crc' => 'crc32b'];
    private ImageTags $codec;
    private int $checks = self::C['zero'];

    public function __construct() { $this->codec = new ImageTags(); }

    /** A failed assertion reports the specific contract rather than a generic success count. */
    private function check(bool $value, string $message): void
    {
        if (!$value) throw new RuntimeException($message);
        $this->checks++;
    }

    /** Every negative case must reject before returning a modified file or tag index. */
    private function rejects(callable $operation, string $message): void
    {
        try { $operation(); } catch (Throwable) { $this->checks++; return; }
        throw new RuntimeException($message);
    }

    /** GD runs only on a few six-pixel fixtures, with no production files or external image conversion. */
    private function image(string $format, bool $lossless = false): string
    {
        $image = imagecreatetruecolor(self::C['width'], self::C['height']);
        imagesavealpha($image, true); imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, self::C['red'], self::C['green'], self::C['blue'], self::C['alpha']);
        $solid = imagecolorallocatealpha($image, self::C['green'], self::C['red'], self::C['blue'], self::C['opaque']);
        imagefill($image, self::C['zero'], self::C['zero'], $transparent);
        imagesetpixel($image, self::C['one'], self::C['zero'], $solid);
        ob_start();
        if ($format === 'png') imagepng($image);
        elseif ($format === 'jpeg') imagejpeg($image, null, self::C['jpeg_quality']);
        else imagewebp($image, null, $lossless ? IMG_WEBP_LOSSLESS : self::C['webp_quality']);
        $bytes = ob_get_clean(); imagedestroy($image);
        $this->check(is_string($bytes) && $bytes !== self::C['empty'], 'Unable to create tiny ' . $format . ' fixture.');
        return $bytes;
    }

    /** Decoded RGBA values, dimensions and MIME are compared before and after metadata-only changes. */
    private function pixels(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        $this->check($image !== false, 'Metadata edit produced an unreadable image.');
        $width = imagesx($image); $height = imagesy($image); $pixels = [];
        for ($y = self::C['zero']; $y < $height; $y++) {
            // Loop: each original pixel, including alpha, must survive the container-only edit exactly.
            for ($x = self::C['zero']; $x < $width; $x++) $pixels[] = imagecolorat($image, $x, $y);
        }
        imagedestroy($image);
        return [$width, $height, $pixels, getimagesizefromstring($bytes)['mime']];
    }

    private function pngChunk(string $type, string $data): string
    { return pack(self::C['be_word'], strlen($data)) . $type . $data . hash(self::C['crc'], $type . $data, true); }
    private function riffChunk(string $type, string $data): string
    { return $type . pack(self::C['le_word'], strlen($data)) . $data . str_repeat("\0", strlen($data) % self::C['two']); }
    private function jpegChunk(string $marker, string $data): string
    { return "\xff" . $marker . pack(self::C['be_short'], strlen($data) + self::C['two']) . $data; }
    private function riffSize(string $bytes): string
    { return substr_replace($bytes, pack(self::C['le_word'], strlen($bytes) - self::C['eight']), self::C['word'], self::C['word']); }

    /** Foreign private/source/mask chunks are intentionally opaque; the tag owner must never parse or rewrite them. */
    private function foreignFixture(string $format, string $bytes): array
    {
        $opaque = '{"source":"exact-original-bytes","mask":"exact-mask-bytes","checksum":"keep"}';
        $exif = "II\x2a\0\x08\0\0\0\0\0\0\0\0\0";
        if ($format === 'png') {
            $chunks = [$this->pngChunk('iTXt', "bzn-image-mask\0\0\0\0\0" . $opaque),
                $this->pngChunk('eXIf', $exif), $this->pngChunk('tEXt', "Description\0original-description"),
                $this->pngChunk('bzNM', 'unknown-private-payload')];
            $insert = strlen($bytes) - self::C['twelve'];
            return [substr($bytes, self::C['zero'], $insert) . implode(self::C['empty'], $chunks) . substr($bytes, $insert), $chunks];
        }
        if ($format === 'jpeg') {
            $chunks = [$this->jpegChunk("\xe1", "Exif\0\0" . $exif),
                $this->jpegChunk("\xe1", "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta xmlns:x=\"adobe:ns:meta/\"/>"),
                $this->jpegChunk("\xe2", "ICC_PROFILE\0\x01\x01exact-icc-fragment"),
                $this->jpegChunk("\xef", 'FOREIGN-APP15' . $opaque), $this->jpegChunk("\xfe", $opaque)];
            $app0Length = unpack(self::C['be_short'], substr($bytes, self::C['word'], self::C['two']))[self::C['one']];
            $insert = self::C['word'] + $app0Length;
            return [substr($bytes, self::C['zero'], $insert) . implode(self::C['empty'], $chunks) . substr($bytes, $insert), $chunks];
        }
        // Branch: prepare a valid extended file, then append foreign chunks and correct only its enclosing size/flags.
        $extended = $this->codec->embed($bytes, []);
        $chunks = [$this->riffChunk('EXIF', $exif), $this->riffChunk('XMP ', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>'),
            $this->riffChunk('BZNM', $opaque), $this->riffChunk('ZZZZ', 'odd')];
        $flagsOffset = self::C['twelve'] + self::C['eight'];
        $extended[$flagsOffset] = chr(ord($extended[$flagsOffset]) | self::C['eight'] | self::C['word']);
        return [$this->riffSize($extended . implode(self::C['empty'], $chunks)), $chunks];
    }

    /** Round-trip, explicit clear and repeated edit all preserve every preexisting opaque payload and RGBA value. */
    private function roundTrip(string $format, string $bytes): void
    {
        $this->check($this->codec->read($bytes) === null, 'Untyped source must have no embedded tag record.');
        $tagged = $this->codec->embed($bytes, ['  Лифты  ', 'Сервис', 'Лифты', 'service', 'service']);
        $wanted = ['service', 'лифты', 'сервис'];
        $this->check($this->codec->read($tagged) === $wanted, $format . ': Unicode tags did not round-trip canonically.');
        $originalPixels = $this->pixels($bytes);
        $this->check($this->pixels($tagged) === $originalPixels, $format . ': tags changed decoded pixels or alpha.');
        [$foreign, $chunks] = $this->foreignFixture($format, $bytes);
        $changed = $this->codec->embed($foreign, ['one', 'two']);
        $again = $this->codec->embed($changed, ['three']);
        $cleared = $this->codec->embed($again, []);
        $this->check($this->codec->read($again) === ['three'], $format . ': old tags survived a replacement.');
        $this->check($this->codec->read($cleared) === [], $format . ': clear must be an explicit empty record.');
        $this->check($this->pixels($cleared) === $originalPixels, $format . ': opaque metadata round-trip changed pixels.');
        if ($format === 'jpeg') {
            $prefixLength = strpos($foreign, $chunks[count($chunks) - self::C['one']]) + strlen($chunks[count($chunks) - self::C['one']]);
            foreach ([$changed, $again, $cleared] as $result) {
                // Loop: adding/replacing/clearing tags cannot move JFIF or any leading Exif/ICC/COM segment.
                $this->check(substr($result, self::C['zero'], $prefixLength) === substr($foreign, self::C['zero'], $prefixLength),
                    'JPEG APP0/JFIF or leading Exif/ICC ordering changed.');
            }
            $this->check(substr($tagged, self::C['zero'], self::C['word']) === substr($bytes, self::C['zero'], self::C['word']),
                'JPEG no longer starts with its original SOI/APP0 marker.');
        }
        foreach ([$changed, $again, $cleared] as $result) {
            $previous = -self::C['one'];
            foreach ($chunks as $chunk) {
                // Loop: all unknown/EXIF/XMP/source/mask chunks stay byte-identical and in their original relative order.
                $position = strpos($result, $chunk);
                $this->check($position !== false && $position > $previous, $format . ': foreign metadata chunk changed, disappeared or moved.');
                $previous = $position;
            }
        }
        $this->check(strlen($again) <= strlen($changed) + strlen('three'), $format . ': repeated edits accumulated tag records.');
    }

    /** File indexing seeks over a two-MiB source/mask record instead of loading its bytes. */
    private function fileReader(string $png): void
    {
        $foreign = $this->pngChunk('iTXt', "bzn-image-mask\0\0\0\0\0" . str_repeat('x', self::C['large_mask_bytes']));
        $insert = strlen($png) - self::C['twelve'];
        $large = substr($png, self::C['zero'], $insert) . $foreign . substr($png, $insert);
        ImageTagCountingStream::$bytes = $this->codec->embed($large, ['file-tag']);
        ImageTagCountingStream::$readBytes = self::C['zero'];
        stream_wrapper_register(self::C['scheme'], ImageTagCountingStream::class);
        try {
            $this->check($this->codec->readFile(self::C['scheme'] . '://fixture') === ['file-tag'], 'File reader did not return canonical tags.');
            $this->check(ImageTagCountingStream::$readBytes < self::C['maximum_reader_bytes'], 'File reader allocated the skipped mask/pixel payload.');
            ImageTagCountingStream::$readBytes = self::C['zero'];
            $state = $this->codec->fileState(self::C['scheme'] . '://fixture', strlen(ImageTagCountingStream::$bytes));
            $this->check($state['tags'] === ['file-tag'] && $state['writable'] === true, 'Write admission lost readable tags or rejected a valid clear-sized edit.');
            $this->check(ImageTagCountingStream::$readBytes < self::C['maximum_reader_bytes'], 'Write admission read the skipped mask/pixel payload.');
        } finally { stream_wrapper_unregister(self::C['scheme']); ImageTagCountingStream::$bytes = self::C['empty']; }
    }

    /** Truncation, forged lengths, corrupt CRC and unsupported formats fail before any write is returned. */
    private function malformed(string $png, string $jpeg, string $webp): void
    {
        foreach ([$png, $jpeg, $webp] as $bytes) {
            // Loop: boundaries are checked for short signatures and incomplete declared chunks.
            $this->rejects(fn() => $this->codec->embed(substr($bytes, self::C['zero'], self::C['one']), []), 'Short source was accepted.');
            $this->rejects(fn() => $this->codec->embed(substr($bytes, self::C['zero'], intdiv(strlen($bytes), self::C['two'])), []), 'Truncated container was accepted.');
        }
        $tagged = $this->codec->embed($png, ['crc']);
        $at = strpos($tagged, '"crc"') + self::C['one'];
        $tagged[$at] = 'x';
        $this->rejects(fn() => $this->codec->read($tagged), 'Private CRC corruption was accepted.');
        $badRiff = substr_replace($webp, pack(self::C['le_word'], self::C['zero']), self::C['word'], self::C['word']);
        $this->rejects(fn() => $this->codec->embed($badRiff, []), 'Forged RIFF size was accepted.');
        $this->rejects(fn() => $this->codec->embed('GIF89a', []), 'Unsupported format acquired a fake tag record.');
        $this->rejects(fn() => $this->codec->normalize(["tag\ncontrol"]), 'Control bytes entered a tag.');
        $this->rejects(fn() => $this->codec->normalize([['nested']]), 'Nested tag array was accepted.');
        $mpf = $this->foreignFixture('jpeg', $jpeg)[self::C['zero']];
        $mpfSegment = $this->jpegChunk("\xe2", "MPF\0unsupported-cross-image-offsets");
        $mpf = substr($mpf, self::C['zero'], self::C['two']) . $mpfSegment . substr($mpf, self::C['two']);
        $this->rejects(fn() => $this->codec->embed($mpf, ['safe']), 'MPF cross-image offsets were silently invalidated.');
    }

    /** Numeric-looking tags remain strings throughout PHP set normalization, JSON encoding and strict reading. */
    private function numericTags(string $png): void
    {
        $input = ['0', '123', '-12', '001', '123', '0'];
        $normalized = $this->codec->normalize($input);
        $this->check(count($normalized) === self::C['word'], 'Numeric tag duplicates were not removed.');
        foreach ($normalized as $tag) {
            // Loop: PHP array key coercion must never leak an integer into the strict tag envelope.
            $this->check(is_string($tag), 'Numeric-looking tag changed its type.');
        }
        $tagged = $this->codec->embed($png, $input);
        $this->check($this->codec->read($tagged) === $normalized, 'Numeric tag embed/read did not round-trip.');
        $replaced = $this->codec->embed($tagged, ['001']);
        $this->check($this->codec->read($replaced) === ['001'], 'Numeric tag replacement retained an old tag.');
        $this->check($this->codec->read($this->codec->embed($replaced, [])) === [], 'Numeric tag clear was not explicit.');
    }

    /** Compressed and translated own iTXt records have the same identity as the canonical uncompressed writer. */
    private function pngVariants(string $png): void
    {
        $payload = json_encode(['format' => 'bzn-image-tags', 'version' => self::C['one'], 'tags' => ['old']], JSON_THROW_ON_ERROR);
        $prefix = 'bzn-image-tags' . "\0";
        $variants = [$prefix . "\x01\0ru\0Теги\0" . gzcompress($payload), $prefix . "\0\0en\0Tags\0" . $payload];
        $insert = strlen($png) - self::C['twelve'];
        foreach ($variants as $data) {
            // Loop: each legal variant must be read, replaced and cleared without leaving a hidden old keyword chunk.
            $source = substr($png, self::C['zero'], $insert) . $this->pngChunk('iTXt', $data) . substr($png, $insert);
            $this->check($this->codec->read($source) === ['old'], 'Legal iTXt compression/language variant was ignored.');
            $changed = $this->codec->embed($source, ['new']);
            $this->check($this->codec->read($changed) === ['new'] && substr_count($changed, $prefix) === self::C['one'], 'A hidden old iTXt tag set survived replacement.');
            $cleared = $this->codec->embed($source, []);
            $this->check($this->codec->read($cleared) === [] && substr_count($cleared, $prefix) === self::C['one'], 'An iTXt variant survived clear.');
            $duplicate = substr($source, self::C['zero'], $insert) . $this->pngChunk('iTXt', $data) . substr($source, $insert);
            $this->rejects(fn() => $this->codec->read($duplicate), 'Duplicate own iTXt records were accepted.');
            $this->rejects(fn() => $this->codec->embed($duplicate, []), 'Ambiguous duplicate metadata was silently rewritten.');
        }
        foreach (['tEXt' => $prefix . $payload, 'zTXt' => $prefix . "\0" . gzcompress($payload)] as $type => $data) {
            $source = substr($png, self::C['zero'], $insert) . $this->pngChunk($type, $data) . substr($png, $insert);
            $this->check($this->codec->read($source) === ['old'], 'Own keyword in ' . $type . ' was silently treated as foreign.');
            $cleared = $this->codec->embed($source, []);
            $this->check($this->codec->read($cleared) === [] && substr_count($cleared, $prefix) === self::C['one'], 'Own ' . $type . ' survived clear.');
        }
        foreach ([$prefix . "\x02\0\0\0" . $payload, $prefix . "\x01\0\0\0invalid-zlib", $prefix . "\0\0missing-delimiters"] as $data) {
            $source = substr($png, self::C['zero'], $insert) . $this->pngChunk('iTXt', $data) . substr($png, $insert);
            $this->rejects(fn() => $this->codec->read($source), 'Malformed own iTXt was treated as missing metadata.');
            $this->rejects(fn() => $this->codec->embed($source, []), 'Malformed own iTXt was overwritten without a safe parse.');
        }
        $bomb = $prefix . "\x01\0\0\0" . gzcompress(str_repeat('x', self::C['large_mask_bytes']));
        $source = substr($png, self::C['zero'], $insert) . $this->pngChunk('iTXt', $bomb) . substr($png, $insert);
        $this->rejects(fn() => $this->codec->read($source), 'Compressed metadata exceeded its output budget.');
    }

    /** A narrowed policy exercises real embed preflight without allocating a maximum-sized image. */
    private function sizePolicy(array $sources): void
    {
        foreach ($sources as $source) {
            // Loop: readers and output preflight must agree for every implemented container, including a new VP8X header.
            $exact = $this->codec->embed($source, []);
            $narrow = new ImageTags(strlen($source));
            $this->check($narrow->read($source) === null, 'Valid input at a narrowed limit was rejected.');
            $this->rejects(fn() => $narrow->embed($source, []), 'Output exceeded the same limit used by its own reader.');
            $atBoundary = new ImageTags(strlen($exact));
            $this->check($atBoundary->embed($source, []) === $exact && $atBoundary->read($exact) === [], 'Exact output limit was not symmetric.');
            $this->rejects(fn() => $atBoundary->embed($source, ['larger-than-empty']), 'Additional tag bytes bypassed output preflight.');
            $shrunk = $this->codec->embed($source, ['old-long-tag']);
            $replacement = new ImageTags(strlen($shrunk));
            $this->check($replacement->read($replacement->embed($shrunk, [])) === [], 'Removing an old tag span was not included in output size.');
        }
        $this->rejects(fn() => new ImageTags(ImageTagSource::MAXIMUM_BYTES + self::C['one']), 'An injected policy raised the shared production limit.');
    }

    public function run(): void
    {
        $png = $this->image('png'); $jpeg = $this->image('jpeg'); $webp = $this->image('webp');
        $this->roundTrip('png', $png); $this->roundTrip('jpeg', $jpeg); $this->roundTrip('webp', $webp);
        if (defined('IMG_WEBP_LOSSLESS')) $this->roundTrip('webp', $this->image('webp', true));
        $this->fileReader($png); $this->malformed($png, $jpeg, $webp); $this->numericTags($png); $this->pngVariants($png);
        $this->sizePolicy([$png, $jpeg, $webp]);
        echo 'PASS image-tags: ' . $this->checks . ' assertions; PNG/JPEG/WebP(lossy+lossless), exact RGBA and opaque metadata; file reader '
            . ImageTagCountingStream::$readBytes . ' bytes, skipped mask ' . self::C['large_mask_bytes'] . ' bytes.' . PHP_EOL;
    }
}

(new ImageTagsContract())->run();
