import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { ImageLibraryBuilder } from '../tools/build-image-library.mjs';

const require = createRequire(import.meta.url);
const { LegacyImageLibraryBuilder } = require('../tools/build-image-library-legacy.js');
const { ImageLibraryTrigger } = require('../tools/reindex-on-trigger.js');

const FIXTURE_COUNT = 34;
const INDEX_GLOBAL_KEY = 'BZNLibraryImageFiles';
const DATA_GLOBAL_KEY = 'BZNLibraryImageData';
const DATA_INDEX_WIDTH = 3;
const TEXT_ENCODING = 'utf8';
const REPOSITORY_ROOT = path.resolve(import.meta.dirname, '..');
const SVG_TEMPLATE = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="48"><rect width="64" height="48" fill="#{color}"/></svg>';

// Function: deterministic SVG fixtures exercise filenames, index order and exact binary payloads without entering Git.
async function createFixtures(directory) {
    for (let index = 0; index < FIXTURE_COUNT; index += 1) {
        // Loop: each source has distinct bytes and a space in its filename.
        const color = index.toString(16).padStart(6, '0');
        const source = SVG_TEMPLATE.replace('{color}', color);
        await writeFile(path.join(directory, `Image ${String(index + 1).padStart(2, '0')}.svg`), source, TEXT_ENCODING);
    }
}

// Function: generated classic scripts are evaluated in an isolated browser-like global.
function evaluateGlobal(source, key, filename) {
    const sandbox = { window: {}, Object };
    vm.runInNewContext(source, sandbox, { filename });
    return sandbox.window[key];
}

const fixtureDirectory = await mkdtemp(path.join(os.tmpdir(), 'bzn-images-contract-'));
await createFixtures(fixtureDirectory);
const result = await new ImageLibraryBuilder(fixtureDirectory).build();
const indexSource = await readFile(path.join(fixtureDirectory, 'files.js'), TEXT_ENCODING);
const indexedNames = Array.from(evaluateGlobal(indexSource, INDEX_GLOBAL_KEY, 'files.js') || {});

assert.equal(result.count, FIXTURE_COUNT);
assert.deepEqual(indexedNames, result.names);
assert.equal(new Set(indexedNames).size, FIXTURE_COUNT);

for (let index = 0; index < indexedNames.length; index += 1) {
    // Loop: each lazy payload must reproduce its indexed source bytes exactly.
    const name = indexedNames[index];
    const dataName = `${String(index).padStart(DATA_INDEX_WIDTH, '0')}.js`;
    const [sourceBytes, dataSource] = await Promise.all([
        readFile(path.join(fixtureDirectory, name)),
        readFile(path.join(fixtureDirectory, 'data', dataName), TEXT_ENCODING),
    ]);
    const record = evaluateGlobal(dataSource, DATA_GLOBAL_KEY, dataName);
    const payload = String(record?.dataUrl || '').split(',').at(-1) || '';
    assert.equal(record?.name, name);
    assert.equal(Buffer.from(payload, 'base64').equals(sourceBytes), true);
}

// Compatibility: shared hosting's CommonJS builder must reproduce the modern builder's exact public contract.
const legacyResult = new LegacyImageLibraryBuilder(fixtureDirectory).build();
const legacyIndexSource = await readFile(path.join(fixtureDirectory, 'files.js'), TEXT_ENCODING);
const legacyIndexedNames = Array.from(evaluateGlobal(legacyIndexSource, INDEX_GLOBAL_KEY, 'legacy-files.js') || {});
assert.deepEqual(legacyResult.names, result.names);
assert.deepEqual(legacyIndexedNames, indexedNames);

// Trigger contract: missing and unchanged markers remain cheap; one changed marker performs exactly one build.
const trigger = new ImageLibraryTrigger({ imageDirectory: fixtureDirectory });
assert.equal(trigger.run().reason, 'trigger-missing');
await writeFile(trigger.triggerFile, 'first upload', TEXT_ENCODING);
const firstTriggeredBuild = trigger.run();
assert.equal(firstTriggeredBuild.triggered, true);
assert.equal(firstTriggeredBuild.build.count, FIXTURE_COUNT);
assert.equal(trigger.run().reason, 'trigger-unchanged');
await writeFile(trigger.triggerFile, 'second upload with changed size', TEXT_ENCODING);
assert.equal(trigger.run().triggered, true);
assert.equal(trigger.run().reason, 'trigger-unchanged');

const [runtimeSource, gallerySource, ignoreSource] = await Promise.all([
    readFile(path.join(REPOSITORY_ROOT, 'runtime-config.js'), TEXT_ENCODING),
    readFile(path.join(REPOSITORY_ROOT, 'image-gallery.js'), TEXT_ENCODING),
    readFile(path.join(REPOSITORY_ROOT, '.gitignore'), TEXT_ENCODING),
]);
assert.match(runtimeSource, /defaultTab: 'Images'/);
assert.doesNotMatch(runtimeSource, /pageSize:\s*30/, 'site config must inherit common gallery geometry');
assert.match(gallerySource, /configuration\.globals\.libraryWindow\]\.pageSize\(\)/, 'missing provider context must resolve the shared primitive page size');
assert.match(runtimeSource, /https:\/\/library-ui\.bsns\.ru\//);
assert.match(runtimeSource, /content: Object\.freeze\(\{ directory: 'images\/', index: 'files\.js', dataDirectory: 'data\/'/);
assert.match(gallerySource, /class ImageLibrarySite/);
assert.match(gallerySource, /async initialize\(\)[\s\S]+await this\.loadScript\(this\.configuration\.interface\.lightboxScript\);[\s\S]+await this\.ensureImageIndex\(\)/,
    'Lightbox must load before the optional content index');
assert.match(gallerySource, /async ensureImageIndex\(\)[\s\S]+this\.files = Object\.freeze\(\[\]\)/, 'missing index must preserve an empty usable provider');
assert.match(ignoreSource, /images\/\*/);

console.log(`Images contract passed: ${FIXTURE_COUNT} generated fixtures, exact index/data and ignored content.`);
