<?php
declare(strict_types=1);

use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\HttpResponder;
use Bzn\ResourceEngine\ImageProcessor;
use Bzn\ResourceEngine\ImageTags;
use Bzn\ResourceEngine\ImageTagStore;
use Bzn\ResourceEngine\ResourceCollection;
use Bzn\ResourceEngine\ResourceEngine;
use Bzn\ResourceEngine\ResourceUrlBuilder;

if (PHP_SAPI !== 'cli') return;
foreach (['ResourceCollection', 'EngineConfiguration', 'ImageProcessor', 'ResourceEngine', 'ResourceUrlBuilder', 'HttpResponder'] as $class) {
    // Loop: CLI contracts run the actual owning source in the selected module checkout.
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

/** Uses only a fresh small fixture tree; real uploads and production assets are outside this contract. */
final class EngineTagContract
{
    private const C = ['type' => 'images', 'image' => 'source.png', 'vector' => 'shape.svg', 'mpf' => 'readonly.jpg',
        'room' => 'no-room.png', 'default' => 'collection-default', 'prefix' => 'bzn-engine-tags-', 'respond' => 'respond',
        'bytes' => 8, 'mode' => 0700, 'width' => 4, 'height' => 3, 'zero' => 0, 'ok' => 200, 'unchanged' => 304,
        'conflict' => 409, 'variants' => ['original', 'thumbnail', 'optimized'], 'piping' => [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        'color' => [24, 48, 96, 50], 'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="3"><rect width="4" height="3"/></svg>'];
    private string $root;
    private string $source;
    private string $id;
    private ResourceCollection $collection;
    private ResourceEngine $engine;
    private ImageProcessor $processor;
    private ImageTags $codec;
    private int $checks = self::C['zero'];

    /** Runs the real responder in a small child process because its successful HTTP boundary exits. */
    public static function respond(): never
    {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $path = realpath($input['descriptor']['path']);
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::C['prefix'];
        if (!is_string($path) || !str_starts_with($path, $prefix)) throw new RuntimeException('Not a tag contract fixture.');
        $_SERVER = $input['headers'];
        register_shutdown_function(static function (): void { fwrite(STDERR, (string) (http_response_code() ?: self::C['ok'])); });
        (new HttpResponder())->resource($input['descriptor']);
    }

    public function __construct()
    {
        $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::C['prefix'] . bin2hex(random_bytes(self::C['bytes']));
        $files = $this->root . '/files';
        mkdir($files, self::C['mode'], true);
        $configuration = new EngineConfiguration(['runtime_directory' => $this->root . '/runtime', 'collections' => [self::C['type'] => [
            'source_directory' => $files, 'tags' => [self::C['default']],
            'allowed_mime_by_extension' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'],
        ]]]);
        $this->collection = $configuration->collection(self::C['type']);
        $this->processor = new ImageProcessor();
        $this->codec = new ImageTags();
        $this->engine = new ResourceEngine($configuration, $this->processor);
        $this->source = $files . '/' . self::C['image'];
        $this->id = $this->collection->resourceId(self::C['image']);
        file_put_contents($this->source, $this->png());
        file_put_contents($files . '/' . self::C['vector'], self::C['svg']);
        $this->engine->rebuild(self::C['type']);
        $this->setSidecar([$this->id => ['tags' => ['old-sidecar'], 'keep' => 'selected'],
            $this->collection->resourceId(self::C['vector']) => ['tags' => ['shape'], 'keep' => 'unrelated']]);
    }

    private function check(bool $condition, string $message): void
    { if (!$condition) throw new RuntimeException($message); $this->checks++; }

    /** Tiny alpha fixture also contains an unrelated PNG text chunk that a tag change must preserve. */
    private function png(): string
    {
        $image = imagecreatetruecolor(self::C['width'], self::C['height']);
        imagealphablending($image, false); imagesavealpha($image, true);
        imagefill($image, self::C['zero'], self::C['zero'], imagecolorallocatealpha($image, ...self::C['color']));
        ob_start(); imagepng($image); $raw = (string) ob_get_clean(); imagedestroy($image);
        $data = 'tEXt' . "Fixture\0unrelated-metadata";
        $iendBytes = 12;
        return substr($raw, self::C['zero'], -$iendBytes) . pack('N', strlen($data) - strlen('tEXt'))
            . $data . pack('N', crc32($data)) . substr($raw, -$iendBytes);
    }

    private function sidecar(): array
    { return json_decode((string) file_get_contents($this->collection->metadataFile()), true, flags: JSON_THROW_ON_ERROR); }

    private function setSidecar(array $metadata): void
    { file_put_contents($this->collection->metadataFile(), json_encode($metadata, JSON_THROW_ON_ERROR)); }

    private function row(): array
    { return array_column($this->engine->list(self::C['type'])['items'], null, 'id')[$this->id]; }

    private function exact(array $tags): void
    { $this->engine->updateTags(self::C['type'], $this->id, $tags, 'replace', $this->row()['tag_revision']); }

    private function binary(string $variant): array
    { return $this->engine->resource(self::C['type'], $this->id, $variant); }

    private function urls(): array
    {
        $page = (new ResourceUrlBuilder('/fixture/api.php'))->decorateList($this->engine->list(self::C['type']));
        return array_column($page['items'], null, 'id')[$this->id]['urls'];
    }

    /** Every failure assertion retains the selected source bytes instead of masking a partial mutation. */
    private function reject(callable $operation, ?int $code = null): Throwable
    {
        try { $operation(); }
        catch (RuntimeException|InvalidArgumentException $error) {
            $this->check($code === null || $error->getCode() === $code, 'Unexpected rejection code.');
            return $error;
        }
        throw new LogicException('An unsafe operation was accepted.');
    }

    /** Existing admin action preserves defaults; strict actions retire only their own fallback and remain exact. */
    private function writes(): void
    {
        $unrelated = $this->sidecar()[$this->collection->resourceId(self::C['vector'])];
        $this->binary('thumbnail'); $this->engine->optimize(self::C['type'], $this->id);
        $this->engine->setTags(self::C['type'], $this->id, ['blue']);
        $this->check($this->row()['tags'] === ['blue', self::C['default']], 'Existing setTags lost default membership.');
        $this->check($this->sidecar()[$this->id] === ['keep' => 'selected', 'tags_embedded' => true]
            && $this->sidecar()[$this->collection->resourceId(self::C['vector'])] === $unrelated, 'Tag write changed unrelated sidecar data.');
        $this->engine->setTags(self::C['type'], $this->id, []);
        $this->check($this->codec->readFile($this->source) === [self::C['default']], 'Existing admin clear removed its defaults.');
        $before = $this->row()['tag_revision'];
        $this->exact([]);
        foreach (self::C['variants'] as $variant) {
            // Loop: existing variant metadata changes with the selected source, without requiring a new image.
            $this->check($this->codec->readFile($this->binary($variant)['path']) === [], 'Strict clear did not reach the current variant.');
        }
        $this->check($this->row()['tags'] === [] && $this->row()['tag_state'] === 'embedded', 'Strict clear resurrected fallback tags.');
        $this->reject(fn () => $this->engine->updateTags(self::C['type'], $this->id, ['stale'], 'replace', $before), self::C['conflict']);
        foreach ([['0', 'first'], ['123', 'second']] as $delta) {
            // Loop: each ADD sees the set actually committed by the prior locked operation.
            $this->engine->updateTags(self::C['type'], $this->id, $delta, 'add', null);
        }
        $this->check($this->row()['tags'] === ['0', '123', 'first', 'second'], 'Fresh ADD lost tags or changed numeric strings.');
        $this->check($this->engine->list(self::C['type'], ['0', 'second'])['total'] === 1, 'Numeric AND filter did not read the committed set.');
    }

    private function request(array $descriptor, array $headers): array
    {
        $process = proc_open([PHP_BINARY, __FILE__, self::C['respond']], self::C['piping'], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to start responder fixture.');
        fwrite($pipes[0], json_encode(['descriptor' => $descriptor, 'headers' => $headers], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $status = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== self::C['zero'] || !ctype_digit($status)) throw new RuntimeException('Responder fixture failed: ' . $status);
        return [(int) $status, $body];
    }

    /** Same-size same-mtime edits must not validate an older original or a ready WebP. */
    private function cacheAndRollback(): void
    {
        $this->exact(['old']);
        $before = []; $oldUrls = $this->urls();
        foreach (self::C['variants'] as $variant) $before[$variant] = $this->binary($variant);
        $this->exact(['new']);
        $afterUrls = $this->urls();
        $etag = new ReflectionMethod(HttpResponder::class, 'entityTag');
        $responder = new HttpResponder();
        $originals = [];
        foreach (self::C['variants'] as $variant) {
            // Loop: actual conditional response must agree with the selected file's own version.
            $after = $this->binary($variant); $prior = $before[$variant];
            $bytes = (string) file_get_contents($after['path']); $originals[$after['path']] = $bytes;
            parse_str((string) parse_url($afterUrls[$variant], PHP_URL_QUERY), $query);
            $this->check($this->codec->readFile($after['path']) === ['new'] && $after['bytes'] === $prior['bytes'] && $after['modified'] === $prior['modified']
                && $afterUrls[$variant] !== $oldUrls[$variant] && $query['v'] === $after['revision'], 'URL changed the wrong delivery identity or mtime.');
            $stale = $etag->invoke($responder, $prior); $current = $etag->invoke($responder, $after);
            $date = gmdate('D, d M Y H:i:s', $prior['modified']) . ' GMT';
            $this->check($this->request($after, ['HTTP_IF_NONE_MATCH' => $stale, 'HTTP_IF_MODIFIED_SINCE' => $date]) === [self::C['ok'], $bytes],
                'Old ETag returned 304 for new metadata bytes.');
            $this->check($this->request($after, ['HTTP_IF_NONE_MATCH' => $current]) === [self::C['unchanged'], ''], 'Current version lost 304 support.');
            $this->check($this->request($after, ['HTTP_IF_MODIFIED_SINCE' => $date]) === [self::C['ok'], $bytes], 'An mtime alone hid changed metadata.');
        }
        $plain = $this->binary('original'); unset($plain['revision']);
        $legacyTag = '"' . hash('sha256', $plain['modified'] . ':' . $plain['bytes'] . ':' . $plain['name']) . '"';
        $this->check($this->request($plain, ['HTTP_IF_NONE_MATCH' => $legacyTag]) === [self::C['unchanged'], ''], 'Legacy descriptor ETag changed.');
        $sidecar = $this->collection->metadataFile(); $catalog = $this->collection->catalogFile();
        $originals[$sidecar] = file_get_contents($sidecar); $originals[$catalog] = file_get_contents($catalog);
        $script = $this->collection->catalogScriptFile(); $saved = $this->root . '/saved-script';
        rename($script, $saved); mkdir($script);
        try { $this->reject(fn () => $this->exact(['must-rollback']), self::C['conflict']); }
        finally { rmdir($script); rename($saved, $script); }
        foreach ($originals as $path => $bytes) {
            // Loop: catalog staging failure cannot leave selected files or the sidecar half updated.
            $this->check(file_get_contents($path) === $bytes, 'Failed catalog staging changed an owned file.');
        }
    }

    /** The legacy setter stays available on known read-only files without modifying any image bytes. */
    private function readOnly(): void
    {
        $directory = $this->collection->sourceDirectory();
        $image = imagecreatefromstring($this->png());
        ob_start(); imagejpeg($image); $jpeg = (string) ob_get_clean(); imagedestroy($image);
        $start = 2; $mpf = "MPF\0fixture";
        $jpeg = substr($jpeg, self::C['zero'], $start) . "\xff\xe2" . pack('n', strlen($mpf) + $start) . $mpf . substr($jpeg, $start);
        $png = $this->png();
        file_put_contents($directory . '/' . self::C['mpf'], $jpeg);
        file_put_contents($directory . '/' . self::C['room'], $png);
        $store = new ReflectionProperty($this->engine, 'tagStore');
        $store->setValue($this->engine, new ImageTagStore(strlen($png)));
        try {
            $this->engine->rebuild(self::C['type']);
            foreach ([self::C['mpf'] => $jpeg, self::C['room'] => $png, self::C['vector'] => self::C['svg']] as $name => $raw) {
                // Loop: codec-unsupported, MPF and no-room sources keep their older sidecar-only admin action.
                $id = $this->collection->resourceId($name);
                $this->engine->setTags(self::C['type'], $id, ['legacy-only']);
                $row = array_column($this->engine->list(self::C['type'])['items'], null, 'id')[$id];
                $this->check($row['tags'] === [self::C['default'], 'legacy-only'] && !$row['tag_editable'], 'Known read-only legacy tags were lost.');
                $this->reject(fn () => $this->engine->updateTags(self::C['type'], $id, ['strict'], 'replace', $row['tag_revision']));
                $this->check(file_get_contents($directory . '/' . $name) === $raw, 'Read-only source bytes were rewritten.');
            }
        } finally { $store->setValue($this->engine, new ImageTagStore()); }
    }

    /** Tests actual publication after the same MIME/name/size validator, with only our CLI-owned staging file. */
    private function replace(string $name, string $raw): array
    {
        $staged = $this->root . '/incoming';
        file_put_contents($staged, $raw);
        $validate = new ReflectionMethod(ResourceEngine::class, 'validatedUpload');
        $validate->invoke($this->engine, $this->collection, ['error' => UPLOAD_ERR_OK, 'size' => strlen($raw), 'name' => $name, 'tmp_name' => $staged]);
        $publish = new ReflectionMethod(ResourceEngine::class, 'publishReplacement');
        return $this->collection->withMutationLock(fn () => $publish->invoke($this->engine, $this->collection, $this->id, $this->row(), $staged, $name));
    }

    private function replacements(): void
    {
        $raw = $this->png();
        foreach ([['retained'], []] as $tags) {
            // Loop: a carried set, including [], changes only the staged upload's own metadata block.
            $this->exact($tags);
            $previous = file_get_contents($this->source);
            $result = $this->replace(self::C['image'], $raw);
            $this->check($result['id'] === $this->id && file_get_contents($this->source) === $this->codec->embed($raw, $tags)
                && str_contains(file_get_contents($this->source), "Fixture\0unrelated-metadata"), 'Replacement changed bytes outside its prepared tag block.');
            $this->check($this->row()['tags'] === $tags && $this->codec->readFile($this->binary('thumbnail')['path']) === $tags,
                'Carried tags did not reach the file, catalog and new derivative.');
            $saved = glob($this->collection->trashDirectory() . '/' . $this->id . '-*-' . self::C['image']);
            $this->check(count(array_filter($saved, static fn (string $path): bool => file_get_contents($path) === $previous)) > 0, 'Prior embedded source was not recovered exactly.');
        }
        $incoming = $this->codec->embed($raw, ['incoming']);
        $this->replace(self::C['image'], $incoming);
        $this->check(file_get_contents($this->source) === $incoming && $this->row()['tags'] === ['incoming'], 'Incoming embedded metadata lost authority or exact bytes.');
        $directory = $this->collection->sourceDirectory();
        foreach (['source.svg' => self::C['svg'], 'source.jpg' => file_get_contents($directory . '/' . self::C['mpf'])] as $name => $unsafe) {
            // Loop: MIME-valid unsupported/MPF uploads reach the tag admission refusal before the source can move.
            $this->refusedReplacement($name, $unsafe, $incoming);
        }
        $store = new ReflectionProperty($this->engine, 'tagStore');
        $store->setValue($this->engine, new ImageTagStore(strlen($raw)));
        try { $this->refusedReplacement(self::C['image'], $raw, $incoming); }
        finally { $store->setValue($this->engine, new ImageTagStore()); }
        $this->staleDerivative();
    }

    /** A format admitted by upload validation can still be refused before replacing authoritative tags. */
    private function refusedReplacement(string $name, string $incoming, string $previous): void
    {
        $catalog = $this->collection->catalogFile();
        $before = file_get_contents($catalog);
        $error = $this->reject(fn () => $this->replace($name, $incoming));
        $this->check($error instanceof InvalidArgumentException && $error->getMessage() === 'Replacement cannot preserve the image tags.'
            && file_get_contents($this->source) === $previous && file_get_contents($catalog) === $before
            && file_get_contents($this->root . '/incoming') === $incoming,
            'Replacement refused at the wrong boundary or changed an owned file.');
    }

    /** A prepared derivative cannot be published after its captured source revision changes. */
    private function staleDerivative(): void
    {
        $revision = $this->row()['tag_revision'];
        $this->exact(['source-changed']);
        $destination = $this->root . '/stale.webp';
        $image = imagecreatefromstring($this->png());
        $write = new ReflectionMethod(ImageProcessor::class, 'writeWebp');
        $quality = $this->collection->thumbnailQuality();
        try {
            $this->reject(fn () => $this->collection->withMutationLock(
                fn () => $write->invoke($this->processor, $image, $destination, $quality, ['stale'], $this->source, $revision)));
            $this->check(!file_exists($destination), 'A stale prepared derivative became visible.');
        } finally { imagedestroy($image); }
    }

    public function run(): void
    {
        try {
            $this->writes(); $this->cacheAndRollback(); $this->readOnly(); $this->replacements();
            echo 'PASS resource-engine-tags: ' . $this->checks . ' actual assertions; old/strict writes, selected URL/HTTP, rollback, readonly, raw replacement and cold derivative.' . PHP_EOL;
        } finally {
            // Cleanup is confined to the exact unique fixture root, without following directory symlinks.
            if (realpath($this->root) === $this->root) {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
                }
                rmdir($this->root);
            }
        }
    }
}

if (($argv[1] ?? '') === 'respond') EngineTagContract::respond();
(new EngineTagContract())->run();
