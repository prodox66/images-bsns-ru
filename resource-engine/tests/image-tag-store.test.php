<?php
declare(strict_types=1);

use Bzn\ResourceEngine\ImageTags;
use Bzn\ResourceEngine\ImageTagStore;
use Bzn\ResourceEngine\ImageTagTransaction;

require_once __DIR__ . '/../src/ImageTagStore.php';

/** Real bounded filesystem operations verify transaction ownership instead of mocking rename/hash/rollback. */
final class ImageTagStoreContract
{
    private const C = ['zero' => 0, 'one' => 1, 'width' => 3, 'height' => 2, 'quality' => 82, 'red' => 210,
        'green' => 34, 'blue' => 87, 'alpha' => 53, 'directory_mode' => 0700, 'source_mode' => 0644,
        'permission_mask' => 0777, 'old_seconds' => 3600, 'random_bytes' => 8, 'conflict' => 409,
        'prefix' => 'bzn-image-tag-store-', 'empty' => ''];
    private string $root;
    private ImageTags $codec;
    private ImageTagStore $store;
    private int $checks = self::C['zero'];

    public function __construct()
    {
        $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::C['prefix'] . bin2hex(random_bytes(self::C['random_bytes']));
        if (!mkdir($this->root, self::C['directory_mode'])) throw new RuntimeException('Unable to create isolated test directory.');
        $this->codec = new ImageTags(); $this->store = new ImageTagStore();
    }

    private function check(bool $value, string $message): void
    { if (!$value) throw new RuntimeException($message); $this->checks++; }
    private function rejects(callable $operation, string $message, ?int $code = null): void
    {
        try { $operation(); } catch (Throwable $error) {
            $this->check($code === null || $error->getCode() === $code, $message . ' (wrong failure code)'); return;
        }
        throw new RuntimeException($message);
    }

    /** Each scenario has its own selected files and private recovery directory. */
    private function paths(string $name): array
    {
        $base = $this->root . DIRECTORY_SEPARATOR . $name;
        mkdir($base, self::C['directory_mode']); mkdir($base . '/files', self::C['directory_mode']); mkdir($base . '/recovery', self::C['directory_mode']);
        return [$base . '/files', $base . '/recovery'];
    }

    /** Six-pixel files suffice for metadata/variant transaction tests; no production assets are read. */
    private function image(string $path, string $format): string
    {
        $image = imagecreatetruecolor(self::C['width'], self::C['height']);
        imagealphablending($image, false); imagesavealpha($image, true);
        $color = imagecolorallocatealpha($image, self::C['red'], self::C['green'], self::C['blue'], self::C['alpha']);
        imagefill($image, self::C['zero'], self::C['zero'], $color);
        if ($format === 'png') imagepng($image, $path);
        elseif ($format === 'jpeg') imagejpeg($image, $path, self::C['quality']);
        else imagewebp($image, $path, self::C['quality']);
        imagedestroy($image); chmod($path, self::C['source_mode']); touch($path, time() - self::C['old_seconds']);
        return file_get_contents($path);
    }

    /** Source, an already-existing working variant and just its legacy field form one recoverable write. */
    private function sourceVariantAndLegacy(): void
    {
        [$files, $recovery] = $this->paths('source-variant');
        $source = $files . '/source.png'; $variant = $files . '/working.webp'; $sidecar = $files . '/metadata.json';
        $sourceBytes = $this->image($source, 'png'); $variantBytes = $this->image($variant, 'webp');
        $sourceTime = filemtime($source); $sourceMode = fileperms($source) & self::C['permission_mask'];
        $before = '{"selected":{"tags":["old",123],"keep":"untouched"},"other":{"tags":["other"]}}';
        file_put_contents($sidecar, $before);
        $snapshot = $this->store->snapshot($source, 'image/png', ['old', 123]);
        $this->check($snapshot['tag_state'] === 'legacy' && $snapshot['tags'] === ['old', '123'], 'Legacy scalar tags disappeared or remained numeric.');
        $change = $this->store->prepare($source, 'image/png', ['new'], 'add', ['old', 123], $snapshot['tag_revision'], $recovery, [$variant]);
        $metadata = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        unset($metadata['selected']['tags']); $metadata['selected']['tags_embedded'] = true;
        $change['transaction']->stage($sidecar, $before, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->check(file_get_contents($source) === $sourceBytes && file_get_contents($variant) === $variantBytes, 'Preparing metadata changed a live source or variant.');
        $change['transaction']->install();
        $wanted = ['123', 'new', 'old'];
        $this->check($this->codec->readFile($source) === $wanted && $this->codec->readFile($variant) === $wanted, 'ADD did not preserve legacy tags in source and working variant.');
        $this->check(file_get_contents($source) === $this->codec->embed($sourceBytes, $wanted), 'Source rewrite differed from the verified lossless codec output.');
        $this->check(file_get_contents($variant) === $this->codec->embed($variantBytes, $wanted), 'Working variant was re-encoded or received a different tag set.');
        clearstatcache(true, $source);
        $this->check(filemtime($source) === $sourceTime && (fileperms($source) & self::C['permission_mask']) === $sourceMode, 'Metadata write changed source mtime or permissions.');
        $saved = json_decode(file_get_contents($sidecar), true, flags: JSON_THROW_ON_ERROR);
        $this->check(!isset($saved['selected']['tags']) && $saved['selected']['tags_embedded'] === true
            && $saved['selected']['keep'] === 'untouched' && $saved['other'] === $metadata['other'], 'Sidecar retirement changed another field or record.');
        $change['transaction']->commit();
        $committed = file_get_contents($source); $change['transaction']->rollback();
        $this->check(file_get_contents($source) === $committed, 'An already committed transaction was rolled back.');
        $this->check($this->entries($recovery) === [], 'Successful operation retained recovery buffers.');
        $this->check($this->store->snapshot($source, 'image/png', ['resurrect'])['tags'] === $wanted, 'Embedded tags lost authority over a stale legacy list.');
        $revision = ImageTagTransaction::revision($source);
        $clear = $this->store->prepare($source, 'image/png', [], 'replace', ['resurrect'], $revision, $recovery, [$variant]);
        $clear['transaction']->install(); $clear['transaction']->commit();
        $empty = $this->store->snapshot($source, 'image/png', ['resurrect']);
        $this->check($empty['tag_state'] === 'embedded' && $empty['tags'] === [], 'Explicit empty tags resurrected legacy/default tags.');
        $add = $this->store->prepare($source, 'image/png', ['after-clear'], 'add', ['resurrect'], $revision, $recovery, [$variant]);
        $add['transaction']->install(); $add['transaction']->commit();
        $this->check($this->codec->readFile($source) === ['after-clear'], 'ADD after clear revived an old legacy set.');
    }

    /** Two serialized ADD operations from one old UI revision reread and union the actual current file set. */
    private function serializedAddAndStaleReplace(): void
    {
        [$files, $recovery] = $this->paths('serialized-add'); $source = $files . '/source.png';
        $bytes = $this->image($source, 'png'); file_put_contents($source, $this->codec->embed($bytes, ['base']));
        $revision = ImageTagTransaction::revision($source);
        foreach (['alpha', 'beta'] as $tag) {
            // Loop: this models the caller's existing mutation lock; no inter-process/CAS claim is made here.
            $change = $this->store->prepare($source, 'image/png', [$tag], 'add', [], $revision, $recovery);
            $change['transaction']->install(); $change['transaction']->commit();
        }
        $this->check($this->codec->readFile($source) === ['alpha', 'base', 'beta'], 'A second ADD overwrote a previously committed tag.');
        $before = file_get_contents($source);
        $this->rejects(fn() => $this->store->prepare($source, 'image/png', ['lost'], 'replace', [], $revision, $recovery), 'Stale REPLACE was accepted.', self::C['conflict']);
        $this->check(file_get_contents($source) === $before && $this->entries($recovery) === [], 'Rejected stale request changed bytes or left recovery data.');
    }

    /** Failure on a later file restores the first exact result and preserves the externally changed later file. */
    private function installConflict(): void
    {
        [$files, $recovery] = $this->paths('install-conflict'); $first = $files . '/first'; $second = $files . '/second';
        file_put_contents($first, 'before-first'); file_put_contents($second, 'before-second');
        $transaction = new ImageTagTransaction($recovery);
        $transaction->stage($first, 'before-first', 'after-first'); $transaction->stage($second, 'before-second', 'after-second');
        file_put_contents($second, 'external-newer');
        $this->rejects(fn() => $transaction->install(), 'Concurrent second file change was overwritten.', self::C['conflict']);
        $this->check(file_get_contents($first) === 'before-first' && file_get_contents($second) === 'external-newer', 'Install failure lost the first original or external second version.');
        $this->check($this->entries($recovery) === [], 'Completed rollback retained unused recovery buffers.');
    }

    /** Rollback after an external same-size overwrite keeps that newer file and its recoverable old bytes. */
    private function rollbackConflict(): void
    {
        [$files, $recovery] = $this->paths('rollback-conflict'); $first = $files . '/first'; $second = $files . '/second';
        file_put_contents($first, 'before-A'); file_put_contents($second, 'before-B');
        $transaction = new ImageTagTransaction($recovery);
        $transaction->stage($first, 'before-A', 'after--A'); $transaction->stage($second, 'before-B', 'after--B'); $transaction->install();
        file_put_contents($first, 'newer--A');
        $this->rejects(fn() => $transaction->commit(), 'Commit accepted a same-size external version.', self::C['conflict']);
        $this->rejects(fn() => $transaction->rollback(), 'Rollback overwrote an external same-size version.', self::C['conflict']);
        $this->check(file_get_contents($first) === 'newer--A' && file_get_contents($second) === 'before-B', 'Rollback failed to distinguish its own and external results.');
        unset($transaction);
        $recoveries = $this->entries($recovery);
        $this->check(count($recoveries) === self::C['one'] && file_get_contents($recovery . '/' . $recoveries[self::C['zero']]) === 'before-A', 'Conflicting newer file lost its exact original recovery bytes.');
    }

    /** Unsupported/error/empty states stay distinct; symlinks and size-limit failures leave originals unchanged. */
    private function rejectedWrites(): void
    {
        [$files, $recovery] = $this->paths('rejected'); $source = $files . '/source.png'; $bytes = $this->image($source, 'png');
        $gif = $files . '/unsupported.gif'; file_put_contents($gif, 'GIF89a');
        $unsupported = $this->store->snapshot($gif, 'image/gif', ['legacy']);
        $this->check($unsupported['tag_state'] === 'unsupported' && $unsupported['tags'] === null, 'Unsupported was reported as known-empty.');
        $this->rejects(fn() => $this->store->prepare($gif, 'image/gif', [], 'replace', [], null, $recovery), 'Unsupported file was rewritten.');
        $bad = $this->codec->embed($bytes, ['broken']); $position = strpos($bad, '"tags"'); $bad[$position] = 'X'; file_put_contents($source, $bad);
        $error = $this->store->snapshot($source, 'image/png', ['legacy']);
        $this->check($error['tag_state'] === 'error' && $error['tags'] === null, 'Corrupt own metadata fell back to legacy/known-empty.');
        $this->rejects(fn() => $this->store->prepare($source, 'image/png', [], 'replace', [], null, $recovery), 'Corrupt own metadata was silently overwritten.');
        $this->check(file_get_contents($source) === $bad, 'Rejected malformed metadata changed its source.');
        file_put_contents($source, $bytes); $small = new ImageTagStore(strlen($bytes));
        $this->check($small->snapshot($source, 'image/png')['tag_editable'] === false, 'A source with no room for even an empty tag record was advertised as editable.');
        $this->rejects(fn() => $small->prepare($source, 'image/png', [], 'replace', [], null, $recovery), 'Metadata output bypassed the narrowed write budget.');
        $this->check(file_get_contents($source) === $bytes && $this->entries($recovery) === [], 'Size rejection changed source or retained buffers.');
        $link = $files . '/link.png'; symlink($source, $link);
        $transaction = new ImageTagTransaction($recovery);
        $this->rejects(fn() => $transaction->stage($link, $bytes, 'unsafe'), 'Symlink target was writable through metadata staging.', self::C['conflict']);
        $this->check(file_get_contents($source) === $bytes, 'Symlink rejection changed its target.');
        $transaction->rollback();
        $pending = new ImageTagTransaction($recovery); $pending->stage($source, $bytes, $this->codec->embed($bytes, ['pending']));
        $this->rejects(fn() => $pending->commit(), 'Pending buffers were mistaken for a committed edit.', self::C['conflict']);
        $pending->rollback(); $pending->rollback();
        $this->check(file_get_contents($source) === $bytes && $this->entries($recovery) === [], 'Pending cancellation was not idempotent or lost the original.');
    }

    /** Actual codec admission keeps readable MPF containers read-only without a second JPEG parser in the store. */
    private function jpegAdmission(): void
    {
        [$files, $recovery] = $this->paths('jpeg-admission'); $source = $files . '/source.jpg';
        $bytes = $this->image($source, 'jpeg');
        $ordinary = $this->store->snapshot($source, 'image/jpeg');
        $this->check($ordinary['tag_state'] === 'absent' && $ordinary['tags'] === [] && $ordinary['tag_editable'] === true,
            'Ordinary JPEG lost read/write admission.');
        $lengthOffset = 4; $shortBytes = 2; $first = 1;
        $insert = $lengthOffset + unpack('n', substr($bytes, $lengthOffset, $shortBytes))[$first];
        $data = "MPF\0opaque-cross-image-offsets";
        $segment = "\xff\xe2" . pack('n', strlen($data) + $shortBytes) . $data;
        $mpf = substr($bytes, self::C['zero'], $insert) . $segment . substr($bytes, $insert);
        file_put_contents($source, $mpf);
        $state = $this->store->snapshot($source, 'image/jpeg');
        $this->check($state['tag_state'] === 'absent' && $state['tags'] === [] && $state['tag_editable'] === false,
            'MPF snapshot advertised write access which the codec refuses.');
        $this->check($this->codec->readFile($source) === null, 'Read-only MPF lost its existing nullable tags read contract.');
        $this->rejects(fn() => $this->store->prepare($source, 'image/jpeg', ['unsafe'], 'replace', [], null, $recovery), 'MPF metadata write was accepted.');
        $this->check(file_get_contents($source) === $mpf && $this->entries($recovery) === [], 'Refused MPF write changed bytes or retained buffers.');
    }

    /** Directory reads are bounded to fixture-owned leaves; no production folder is enumerated. */
    private function entries(string $directory): array
    { return array_values(array_filter(scandir($directory), static fn(string $name): bool => $name !== '.' && $name !== '..')); }

    /** Every cleanup path is checked inside the unique test root before deleting only that fixture tree. */
    private function cleanup(string $directory): void
    {
        $resolved = realpath($directory);
        if (!is_string($resolved) || $resolved !== $this->root && !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refused cleanup outside the test workspace.');
        }
        foreach ($this->entries($directory) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && !is_link($path)) $this->cleanup($path); else unlink($path);
        }
        rmdir($directory);
    }

    public function run(): void
    {
        try {
            $this->sourceVariantAndLegacy(); $this->serializedAddAndStaleReplace(); $this->installConflict(); $this->rollbackConflict(); $this->rejectedWrites(); $this->jpegAdmission();
            echo 'PASS image-tag-store: ' . $this->checks . ' assertions; real isolated files, source/variant/legacy, ADD/clear, stale/conflict/recovery/permission/mtime/size/symlink guards.' . PHP_EOL;
        } finally { $this->cleanup($this->root); }
    }
}

(new ImageTagStoreContract())->run();
