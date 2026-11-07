<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;
use RuntimeException;

/** One bounded random-access reader lets file indexes skip pixel and source/mask payloads. */
final class ImageTagSource
{
    private const ZERO = 0;
    private const EMPTY = '';
    private const READ_MODE = 'rb';
    public const MAXIMUM_BYTES = 134217728;
    private mixed $handle = null;
    private int $length;

    /** Byte input is retained; file input is opened only after the caller resolves its own safe path. */
    public function __construct(private readonly ?string $bytes = null, ?string $file = null, int $maximumBytes = self::MAXIMUM_BYTES)
    {
        if ($bytes !== null) $this->length = strlen($bytes);
        else {
            $this->handle = is_string($file) ? fopen($file, self::READ_MODE) : false;
            $details = is_resource($this->handle) ? fstat($this->handle) : false;
            if (!is_array($details)) throw new RuntimeException('Image metadata source is unavailable.');
            $this->length = (int) $details['size'];
        }
        // Branch: parser work is bounded before any file contents can be allocated.
        if ($maximumBytes <= self::ZERO || $maximumBytes > self::MAXIMUM_BYTES || $this->length <= self::ZERO || $this->length > $maximumBytes) {
            $this->close();
            throw new InvalidArgumentException('Image metadata source exceeds its limit.');
        }
    }

    public function size(): int { return $this->length; }

    /** The same bounds apply to memory slices and seek/read file operations. */
    public function read(int $offset, int $length): string
    {
        if ($offset < self::ZERO || $length < self::ZERO || $offset > $this->length - $length) {
            throw new InvalidArgumentException('Image metadata boundary is invalid.');
        }
        if ($this->bytes !== null) return substr($this->bytes, $offset, $length);
        if (fseek($this->handle, $offset) !== self::ZERO) throw new RuntimeException('Image metadata seek failed.');
        $result = self::EMPTY;
        // Loop: short reads cannot silently accept a truncated chunk or a concurrently shortened file.
        while (strlen($result) < $length) {
            $next = fread($this->handle, $length - strlen($result));
            if (!is_string($next) || $next === self::EMPTY) throw new RuntimeException('Image metadata read failed.');
            $result .= $next;
        }
        return $result;
    }

    /** An explicit finally or destruction releases only this reader's own file descriptor. */
    public function close(): void
    {
        if (is_resource($this->handle)) fclose($this->handle);
        $this->handle = null;
    }
    public function __destruct() { $this->close(); }
}

/** Encapsulates tag normalization and lossless container edits; storage, authorization and locks stay outside. */
final class ImageTags
{
    private const C = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'word' => 4, 'five' => 5,
        'png_header' => 8, 'chunk_header' => 8, 'png_overhead' => 12, 'riff_header' => 12,
        'maximum_tags' => 64, 'maximum_tag_bytes' => 256, 'maximum_metadata_bytes' => 32768,
        'maximum_segments' => 100000, 'json_depth' => 8, 'jpeg_segment_limit' => 65535, 'png_text_header_limit' => 512,
        'png' => "\x89PNG\r\n\x1a\n", 'jpeg' => "\xff\xd8", 'riff' => 'RIFF', 'webp' => 'WEBP',
        'keyword' => 'bzn-image-tags', 'webp_chunk' => 'BZNT', 'jpeg_prefix' => "BZN-IMAGE-TAGS\0",
        'format' => 'bzn-image-tags', 'version' => 1, 'empty' => '', 'tag_key_prefix' => 'tag:',
        'metadata_error' => 'Image tag metadata is invalid.', 'format_error' => 'Image tag format is not supported.',
        'be_word' => 'N', 'le_word' => 'V', 'be_short' => 'n', 'le_short' => 'v', 'crc' => 'crc32b',
        'png_ihdr_size' => 13,
        'vp8x_size' => 10, 'vp8x_alpha' => 16, 'vp8x_icc' => 32, 'vp8x_exif' => 8,
        'vp8x_xmp' => 4, 'vp8x_animation' => 2, 'canvas_limit' => 16777216,
        'vp8_signature' => "\x9d\x01\x2a", 'vp8_dimension_mask' => 16383,
        'vp8l_signature' => 47, 'vp8l_width_mask' => 16383, 'vp8l_height_shift' => 14,
        'vp8l_alpha_shift' => 28, 'vp8l_version_shift' => 29,
        'jpeg_marker' => 255, 'jpeg_app15' => 239, 'jpeg_scan' => 218, 'jpeg_end' => 217,
        'jpeg_soi' => 216, 'jpeg_restart_first' => 208, 'jpeg_restart_last' => 215, 'jpeg_tem' => 1,
        'jpeg_app_first' => 224, 'jpeg_app_last' => 239, 'jpeg_comment' => 254, 'jpeg_app2' => 226,
        'jpeg_mpf_prefix' => "MPF\0",
    ];

    /** Tests may narrow the one shared container limit; no instance can raise the production maximum. */
    public function __construct(private readonly int $maximumBytes = ImageTagSource::MAXIMUM_BYTES)
    {
        if ($maximumBytes <= self::C['zero'] || $maximumBytes > ImageTagSource::MAXIMUM_BYTES) {
            throw new InvalidArgumentException(self::C['metadata_error']);
        }
    }

    /** One canonical lowercase set is shared by single replacement, bulk union and readers. */
    public function normalize(array $tags): array
    {
        if (count($tags) > self::C['maximum_tags']) throw new InvalidArgumentException(self::C['metadata_error']);
        $values = [];
        foreach ($tags as $tag) {
            // Loop: arrays, control bytes and malformed UTF-8 never become JSON fields or path fragments.
            if (!is_string($tag) || preg_match('//u', $tag) !== self::C['one']
                || preg_match('/[\x00-\x1f\x7f]/u', $tag) === self::C['one']) {
                throw new InvalidArgumentException(self::C['metadata_error']);
            }
            $value = trim($tag);
            if (strlen($value) > self::C['maximum_tag_bytes']) throw new InvalidArgumentException(self::C['metadata_error']);
            if ($value === self::C['empty']) continue;
            $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
            // Branch: an explicit prefix prevents PHP from converting numeric tag strings to integer array keys.
            $values[self::C['tag_key_prefix'] . $value] = $value;
        }
        $result = array_values($values);
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /** A missing private record is distinct from an explicit empty set which overrides a legacy index. */
    public function read(string $bytes): ?array { return $this->readSource(new ImageTagSource($bytes, null, $this->maximumBytes)); }

    /** Index rebuilding reads chunk headers and owned metadata, never the skipped image/mask chunks. */
    public function readFile(string $file): ?array { return $this->readSource(new ImageTagSource(null, $file, $this->maximumBytes)); }

    /** An adapter receives actual write admission from the same bounded parser, without duplicating format rules. */
    public function fileState(string $file, ?int $maximumWriteBytes = null): array
    {
        $writeLimit = $maximumWriteBytes ?? $this->maximumBytes;
        if ($writeLimit <= self::C['zero'] || $writeLimit > $this->maximumBytes) throw new InvalidArgumentException(self::C['metadata_error']);
        return $this->readStateSource(new ImageTagSource(null, $file, $this->maximumBytes), $writeLimit);
    }

    /** Existing readers keep their nullable-tag contract while sharing the richer single-pass state reader. */
    private function readSource(ImageTagSource $source): ?array { return $this->readStateSource($source)['tags']; }

    /** A single reader accepts only the bounded versioned envelope emitted by this owner. */
    private function readStateSource(ImageTagSource $source, ?int $writeLimit = null): array
    {
        try {
            $layout = $this->inspect($source);
            $tags = null;
            foreach ($layout['owned'] as $record) {
                // Loop: duplicate owned records are rejected, so no hidden older tag set can survive a clear operation.
                if ($tags !== null) throw new InvalidArgumentException(self::C['metadata_error']);
                $value = json_decode($record['payload'], true, self::C['json_depth'], JSON_THROW_ON_ERROR);
                if (!is_array($value) || ($value['format'] ?? null) !== self::C['format']
                    || ($value['version'] ?? null) !== self::C['version'] || !is_array($value['tags'] ?? null)
                    || !array_is_list($value['tags'])) throw new InvalidArgumentException(self::C['metadata_error']);
                $tags = $this->normalize($value['tags']);
            }
            $writable = ($layout['writable'] ?? true) === true;
            if ($writeLimit !== null && $writable) {
                // Branch: admission uses the same minimal edit plan and both input/output limits as the actual writer.
                try { $writable = $source->size() <= $writeLimit && $this->editPlan($source->size(), $layout, [])['size'] <= $writeLimit; }
                catch (InvalidArgumentException) { $writable = false; }
            }
            return ['tags' => $tags, 'writable' => $writable];
        } finally { $source->close(); }
    }

    /** Replacing only this owner's record keeps every other source, mask, EXIF and unknown chunk verbatim. */
    public function embed(string $bytes, array $tags): string
    {
        $tags = $this->normalize($tags);
        $layout = $this->inspect(new ImageTagSource($bytes, null, $this->maximumBytes));
        $plan = $this->editPlan(strlen($bytes), $layout, $tags);
        $header = $plan['header']; $chunk = $plan['chunk'];
        // Branch: preflight the exact output before rebuilding large ranges, using the same limit as all readers.
        if ($plan['size'] > $this->maximumBytes) throw new InvalidArgumentException(self::C['metadata_error']);
        $kept = self::C['empty']; $cursor = self::C['zero'];
        foreach ($layout['owned'] as $record) {
            // Loop: only precisely identified private spans are omitted; unrelated raw ranges retain their exact order.
            $kept .= substr($bytes, $cursor, $record['offset'] - $cursor);
            $cursor = $record['offset'] + $record['length'];
        }
        $kept .= substr($bytes, $cursor);
        $insert = $layout['insert'];
        foreach ($layout['owned'] as $record) {
            // Loop: a removed record before the insertion point shifts only the new tag location.
            if ($record['offset'] < $layout['insert']) $insert -= $record['length'];
        }
        if ($header !== self::C['empty']) {
            // Branch: retained original WebP chunk offsets shift together after the newly inserted VP8X header.
            $kept = substr($kept, self::C['zero'], self::C['riff_header']) . $header . substr($kept, self::C['riff_header']);
            $insert += strlen($header);
        }
        $result = substr($kept, self::C['zero'], $insert) . $chunk . substr($kept, $insert);
        // Branch: only RIFF's enclosing byte count changes; original chunk contents and padding remain untouched.
        if ($layout['format'] === 'webp') $result = substr_replace($result,
            pack(self::C['le_word'], strlen($result) - self::C['chunk_header']), self::C['word'], self::C['word']);
        return $result;
    }

    /** Admission and writing share one chunk/VP8X/removal size plan; it never reads or decodes image payloads. */
    private function editPlan(int $inputSize, array $layout, array $tags): array
    {
        if (($layout['writable'] ?? true) !== true) throw new InvalidArgumentException(self::C['format_error']);
        if (count($layout['owned']) > self::C['one']) throw new InvalidArgumentException(self::C['metadata_error']);
        $payload = json_encode(['format' => self::C['format'], 'version' => self::C['version'], 'tags' => $tags],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($payload) > self::C['maximum_metadata_bytes']) throw new InvalidArgumentException(self::C['metadata_error']);
        $header = self::C['empty'];
        if ($layout['format'] === 'png') {
            $chunk = $this->pngChunk('iTXt', self::C['keyword'] . str_repeat("\0", self::C['five']) . $payload);
        } elseif ($layout['format'] === 'jpeg') {
            $data = self::C['jpeg_prefix'] . $payload;
            $chunk = "\xff\xef" . pack(self::C['be_short'], strlen($data) + self::C['two']) . $data;
        } else {
            // Branch: a simple WebP needs its real native canvas header as well as the new private chunk.
            $header = $layout['extended'] ? self::C['empty'] : $this->webpHeader($layout);
            $chunk = $this->riffChunk(self::C['webp_chunk'], $payload);
        }
        $removed = array_sum(array_column($layout['owned'], 'length'));
        return ['header' => $header, 'chunk' => $chunk, 'size' => $inputSize - $removed + strlen($header) + strlen($chunk)];
    }

    /** Format dispatch examines only signatures; declared extensions cannot select a different binary parser. */
    private function inspect(ImageTagSource $source): array
    {
        $head = $source->read(self::C['zero'], min(self::C['riff_header'], $source->size()));
        if (str_starts_with($head, self::C['png'])) return $this->png($source);
        if (str_starts_with($head, self::C['jpeg'])) return $this->jpeg($source);
        if (strlen($head) === self::C['riff_header'] && substr($head, self::C['zero'], self::C['word']) === self::C['riff']
            && substr($head, self::C['chunk_header']) === self::C['webp']) return $this->webp($source);
        throw new InvalidArgumentException(self::C['format_error']);
    }

    /** PNG walks declared chunk bounds and checks the private iTXt CRC before treating it as editable metadata. */
    private function png(ImageTagSource $source): array
    {
        $offset = self::C['png_header']; $owned = []; $segments = self::C['zero']; $insert = null;
        while ($offset + self::C['png_overhead'] <= $source->size()) {
            $this->segment($segments);
            $header = $source->read($offset, self::C['chunk_header']);
            $length = $this->number(substr($header, self::C['zero'], self::C['word']), false);
            $type = substr($header, self::C['word']);
            $end = $offset + self::C['png_overhead'] + $length;
            if ($end > $source->size()) throw new InvalidArgumentException(self::C['metadata_error']);
            // Branch: IHDR remains the first chunk and the new ancillary record is inserted immediately after it.
            if ($insert === null) {
                if ($type !== 'IHDR' || $length !== self::C['png_ihdr_size']) throw new InvalidArgumentException(self::C['metadata_error']);
                $insert = $end;
            }
            if (in_array($type, ['iTXt', 'tEXt', 'zTXt'], true)) {
                $prefix = self::C['keyword'] . "\0";
                if ($length >= strlen($prefix) && $source->read($offset + self::C['chunk_header'], strlen($prefix)) === $prefix) {
                    if ($length > self::C['maximum_metadata_bytes'] + self::C['png_text_header_limit']) throw new InvalidArgumentException(self::C['metadata_error']);
                    $data = $source->read($offset + self::C['chunk_header'], $length);
                    $crc = $source->read($end - self::C['word'], self::C['word']);
                    if (!hash_equals(hash(self::C['crc'], $type . $data, true), $crc)) throw new InvalidArgumentException(self::C['metadata_error']);
                    $owned[] = ['offset' => $offset, 'length' => $end - $offset, 'payload' => $this->pngText($data, strlen($prefix), $type)];
                }
            }
            if ($type === 'IEND') {
                if ($length !== self::C['zero']) throw new InvalidArgumentException(self::C['metadata_error']);
                return ['format' => 'png', 'owned' => $owned, 'insert' => $insert];
            }
            $offset = $end;
        }
        throw new InvalidArgumentException(self::C['metadata_error']);
    }

    /** Ownership is decided by keyword; compression/language variants cannot hide an old private tag record. */
    private function pngText(string $data, int $offset, string $type): string
    {
        // Branch: the same private keyword cannot hide an older ASCII text or compressed-text tag record.
        if ($type === 'tEXt') {
            $payload = substr($data, $offset); $this->metadataSize(strlen($payload)); return $payload;
        }
        if ($type === 'zTXt') {
            if (strlen($data) <= $offset || ord($data[$offset]) !== self::C['zero']) throw new InvalidArgumentException(self::C['metadata_error']);
            return $this->inflate(substr($data, $offset + self::C['one']));
        }
        if (strlen($data) < $offset + self::C['word']) throw new InvalidArgumentException(self::C['metadata_error']);
        $flag = ord($data[$offset]); $method = ord($data[$offset + self::C['one']]);
        $languageEnd = strpos($data, "\0", $offset + self::C['two']);
        $translatedEnd = $languageEnd === false ? false : strpos($data, "\0", $languageEnd + self::C['one']);
        if (!in_array($flag, [self::C['zero'], self::C['one']], true) || $method !== self::C['zero']
            || $languageEnd === false || $translatedEnd === false || $translatedEnd > self::C['png_text_header_limit']) {
            throw new InvalidArgumentException(self::C['metadata_error']);
        }
        $language = substr($data, $offset + self::C['two'], $languageEnd - $offset - self::C['two']);
        $translated = substr($data, $languageEnd + self::C['one'], $translatedEnd - $languageEnd - self::C['one']);
        if (preg_match('/^[A-Za-z0-9-]*$/D', $language) !== self::C['one'] || preg_match('//u', $translated) !== self::C['one']) {
            throw new InvalidArgumentException(self::C['metadata_error']);
        }
        $payload = substr($data, $translatedEnd + self::C['one']);
        $this->metadataSize(strlen($payload));
        return $flag === self::C['one'] ? $this->inflate($payload) : $payload;
    }

    /** Both PNG compressed text variants share one bounded decompressor. */
    private function inflate(string $payload): string
    {
        $this->metadataSize(strlen($payload));
        // Branch: zlib output is capped during decompression so a metadata bomb never expands into image-sized memory.
        $result = @gzuncompress($payload, self::C['maximum_metadata_bytes']);
        if (!is_string($result)) throw new InvalidArgumentException(self::C['metadata_error']);
        $this->metadataSize(strlen($result)); return $result;
    }

    /** JPEG stops at the first scan; APP/COM bytes and the complete entropy-coded tail stay untouched. */
    private function jpeg(ImageTagSource $source): array
    {
        $offset = self::C['two']; $owned = []; $segments = self::C['zero'];
        $insert = $offset; $leading = true; $writable = true;
        while ($offset + self::C['two'] <= $source->size()) {
            $this->segment($segments); $start = $offset;
            if (ord($source->read($offset++, self::C['one'])) !== self::C['jpeg_marker']) throw new InvalidArgumentException(self::C['metadata_error']);
            do {
                // Loop: marker fill bytes share the same bounded parser work budget as normal segments.
                $this->segment($segments);
                $marker = ord($source->read($offset++, self::C['one']));
            } while ($marker === self::C['jpeg_marker']);
            if ($marker === self::C['jpeg_scan'] || $marker === self::C['jpeg_end']) {
                return ['format' => 'jpeg', 'owned' => $owned, 'insert' => $insert, 'writable' => $writable];
            }
            if ($marker === self::C['jpeg_tem'] || $marker >= self::C['jpeg_restart_first'] && $marker <= self::C['jpeg_restart_last']) continue;
            if ($marker === self::C['zero'] || $marker === self::C['jpeg_soi']) throw new InvalidArgumentException(self::C['metadata_error']);
            $length = $this->short($source->read($offset, self::C['two']));
            $end = $offset + $length;
            if ($length < self::C['two'] || $end > $source->size()) throw new InvalidArgumentException(self::C['metadata_error']);
            $payloadLength = $length - self::C['two'];
            // Branch: MPF uses cross-image offsets; preserve its bytes and refuse an unsupported offset rewrite.
            if ($marker === self::C['jpeg_app2'] && $payloadLength >= strlen(self::C['jpeg_mpf_prefix'])
                && $source->read($offset + self::C['two'], strlen(self::C['jpeg_mpf_prefix'])) === self::C['jpeg_mpf_prefix']) $writable = false;
            if ($marker === self::C['jpeg_app15'] && $payloadLength >= strlen(self::C['jpeg_prefix'])
                && $source->read($offset + self::C['two'], strlen(self::C['jpeg_prefix'])) === self::C['jpeg_prefix']) {
                $this->metadataSize($payloadLength - strlen(self::C['jpeg_prefix']));
                $owned[] = ['offset' => $start, 'length' => $end - $start,
                    'payload' => $source->read($offset + self::C['two'] + strlen(self::C['jpeg_prefix']), $payloadLength - strlen(self::C['jpeg_prefix']))];
            }
            // Branch: APP0/JFIF and existing Exif/ICC/COM prefixes retain their exact original position after SOI.
            if ($leading && ($marker >= self::C['jpeg_app_first'] && $marker <= self::C['jpeg_app_last'] || $marker === self::C['jpeg_comment'])) $insert = $end;
            else $leading = false;
            $offset = $end;
        }
        throw new InvalidArgumentException(self::C['metadata_error']);
    }

    /** RIFF skips original image/animation/mask/EXIF/XMP chunks through even-byte boundaries. */
    private function webp(ImageTagSource $source): array
    {
        if ($this->number($source->read(self::C['word'], self::C['word']), true) + self::C['chunk_header'] !== $source->size()) {
            throw new InvalidArgumentException(self::C['metadata_error']);
        }
        $offset = self::C['riff_header']; $owned = []; $segments = self::C['zero'];
        $result = ['format' => 'webp', 'extended' => false, 'flags' => self::C['zero'], 'width' => null, 'height' => null];
        $image = false;
        while ($offset + self::C['chunk_header'] <= $source->size()) {
            $this->segment($segments); $header = $source->read($offset, self::C['chunk_header']);
            $type = substr($header, self::C['zero'], self::C['word']);
            $length = $this->number(substr($header, self::C['word']), true);
            $end = $offset + self::C['chunk_header'] + $length;
            $padded = $end + $length % self::C['two'];
            if ($padded > $source->size() || $padded !== $end && $source->read($end, self::C['one']) !== "\0") {
                throw new InvalidArgumentException(self::C['metadata_error']);
            }
            if ($type === self::C['webp_chunk']) {
                $this->metadataSize($length);
                $owned[] = ['offset' => $offset, 'length' => $padded - $offset,
                    'payload' => $source->read($offset + self::C['chunk_header'], $length)];
            } elseif ($type === 'VP8X') {
                if ($length !== self::C['vp8x_size'] || $result['extended']) throw new InvalidArgumentException(self::C['metadata_error']);
                $result['extended'] = true;
            } elseif ($type === 'VP8 ' || $type === 'VP8L') {
                // Branch: a simple file needs only its tiny bitstream header to construct a valid extended canvas header.
                $dimensions = $this->webpDimensions($source, $offset + self::C['chunk_header'], $length, $type);
                $dimensions['flags'] = $result['flags'] | ($dimensions['flags'] ?? self::C['zero']);
                $result = array_replace($result, $dimensions);
                $image = true;
            } elseif ($type === 'ANMF') $image = true;
            $flag = ['ALPH' => self::C['vp8x_alpha'], 'ICCP' => self::C['vp8x_icc'], 'EXIF' => self::C['vp8x_exif'],
                'XMP ' => self::C['vp8x_xmp'], 'ANIM' => self::C['vp8x_animation']][$type] ?? self::C['zero'];
            $result['flags'] |= $flag;
            $offset = $padded;
        }
        if ($offset !== $source->size() || !$image) throw new InvalidArgumentException(self::C['metadata_error']);
        return $result + ['owned' => $owned, 'insert' => $source->size()];
    }

    /** The VP8/VP8L header exposes dimensions and alpha without decoding any pixel. */
    private function webpDimensions(ImageTagSource $source, int $offset, int $length, string $type): array
    {
        $lossless = $type === 'VP8L'; $minimum = $lossless ? self::C['five'] : self::C['vp8x_size'];
        if ($length < $minimum) throw new InvalidArgumentException(self::C['metadata_error']);
        $head = $source->read($offset, $minimum);
        if ($lossless) {
            $bits = $this->number(substr($head, self::C['one'], self::C['word']), true);
            if (ord($head[self::C['zero']]) !== self::C['vp8l_signature'] || ($bits >> self::C['vp8l_version_shift']) !== self::C['zero']) {
                throw new InvalidArgumentException(self::C['metadata_error']);
            }
            return ['width' => ($bits & self::C['vp8l_width_mask']) + self::C['one'],
                'height' => (($bits >> self::C['vp8l_height_shift']) & self::C['vp8l_width_mask']) + self::C['one'],
                'flags' => ($bits >> self::C['vp8l_alpha_shift'] & self::C['one']) ? self::C['vp8x_alpha'] : self::C['zero']];
        }
        if (substr($head, self::C['three'], self::C['three']) !== self::C['vp8_signature']) throw new InvalidArgumentException(self::C['metadata_error']);
        $dimensionOffset = self::C['three'] + self::C['three'];
        $width = unpack(self::C['le_short'], substr($head, $dimensionOffset, self::C['two']))[self::C['one']] & self::C['vp8_dimension_mask'];
        $height = unpack(self::C['le_short'], substr($head, $dimensionOffset + self::C['two'], self::C['two']))[self::C['one']] & self::C['vp8_dimension_mask'];
        return ['width' => $width, 'height' => $height];
    }

    /** A newly extended simple file retains its real dimensions and existing alpha/profile/metadata feature flags. */
    private function webpHeader(array $layout): string
    {
        $width = (int) $layout['width']; $height = (int) $layout['height'];
        if ($width < self::C['one'] || $height < self::C['one'] || $width > self::C['canvas_limit'] || $height > self::C['canvas_limit']) {
            throw new InvalidArgumentException(self::C['metadata_error']);
        }
        $data = chr($layout['flags']) . str_repeat("\0", self::C['three'])
            . substr(pack(self::C['le_word'], $width - self::C['one']), self::C['zero'], self::C['three'])
            . substr(pack(self::C['le_word'], $height - self::C['one']), self::C['zero'], self::C['three']);
        return $this->riffChunk('VP8X', $data);
    }

    /** The two container chunk constructors share all size, padding and checksum policy. */
    private function pngChunk(string $type, string $data): string
    { return pack(self::C['be_word'], strlen($data)) . $type . $data . hash(self::C['crc'], $type . $data, true); }
    private function riffChunk(string $type, string $data): string
    { return $type . pack(self::C['le_word'], strlen($data)) . $data . str_repeat("\0", strlen($data) % self::C['two']); }
    private function number(string $bytes, bool $little): int
    { return (int) unpack($little ? self::C['le_word'] : self::C['be_word'], $bytes)[self::C['one']]; }
    private function short(string $bytes): int
    { return (int) unpack(self::C['be_short'], $bytes)[self::C['one']]; }

    /** Malformed containers cannot request unbounded loop work or metadata allocations. */
    private function segment(int &$count): void
    {
        if (++$count > self::C['maximum_segments']) throw new InvalidArgumentException(self::C['metadata_error']);
    }
    private function metadataSize(int $bytes): void
    {
        if ($bytes > self::C['maximum_metadata_bytes']) throw new InvalidArgumentException(self::C['metadata_error']);
    }
}
