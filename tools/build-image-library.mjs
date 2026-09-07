// Build-time owner: scans FTP-managed images and generates browser-safe index/data scripts.
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const MODULE_FILE = fileURLToPath(import.meta.url);
const REPOSITORY_ROOT = path.resolve(import.meta.dirname, '..');
const DEFAULT_IMAGE_DIRECTORY = path.join(REPOSITORY_ROOT, 'images');
const TEXT_ENCODING = 'utf8';
const BASE64_ENCODING = 'base64';
const FILE_INDEX_NAME = 'files.js';
const DATA_DIRECTORY_NAME = 'data';
const FILES_GLOBAL_KEY = 'BZNLibraryImageFiles';
const DATA_GLOBAL_KEY = 'BZNLibraryImageData';
const DATA_INDEX_WIDTH = 3;
const DATA_SCRIPT_EXTENSION = '.js';
const MIME_BY_EXTENSION = Object.freeze({
    '.avif': 'image/avif', '.gif': 'image/gif', '.jpeg': 'image/jpeg', '.jpg': 'image/jpeg',
    '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp',
});

export class ImageLibraryBuilder {
    constructor(imageDirectory = DEFAULT_IMAGE_DIRECTORY) {
        this.imageDirectory = path.resolve(imageDirectory);
        this.dataDirectory = path.join(this.imageDirectory, DATA_DIRECTORY_NAME);
        this.indexFile = path.join(this.imageDirectory, FILE_INDEX_NAME);
    }

    // Function: every supported file physically present in the FTP directory enters one deterministic list.
    async discover() {
        const entries = await readdir(this.imageDirectory, { withFileTypes: true });
        return entries
            .filter((entry) => entry.isFile() && MIME_BY_EXTENSION[path.extname(entry.name).toLowerCase()])
            .map((entry) => entry.name)
            .sort((left, right) => left.localeCompare(right, 'ru', { numeric: true, sensitivity: 'base' }));
    }

    // Function: one classic script publishes names without requiring JSON CORS access.
    async writeIndex(names) {
        const source = `// Generated image-library index.\nwindow[${JSON.stringify(FILES_GLOBAL_KEY)}] = Object.freeze(${JSON.stringify(names, null, 4)});\n`;
        await writeFile(this.indexFile, source, TEXT_ENCODING);
    }

    // Function: one image becomes one lazy script used only when cross-origin Blob fetch is unavailable.
    async writeData(name, index) {
        const extension = path.extname(name).toLowerCase();
        const mime = MIME_BY_EXTENSION[extension];
        const bytes = await readFile(path.join(this.imageDirectory, name));
        const record = Object.freeze({ name, dataUrl: `data:${mime};base64,${bytes.toString(BASE64_ENCODING)}` });
        const outputName = `${String(index).padStart(DATA_INDEX_WIDTH, '0')}${DATA_SCRIPT_EXTENSION}`;
        const source = `// Generated image-library payload.\nwindow[${JSON.stringify(DATA_GLOBAL_KEY)}] = Object.freeze(${JSON.stringify(record)});\n`;
        await writeFile(path.join(this.dataDirectory, outputName), source, TEXT_ENCODING);
        return bytes.length;
    }

    // Function: one build transaction derives all generated output from the current directory contents.
    async build() {
        const names = await this.discover();
        await mkdir(this.dataDirectory, { recursive: true });
        await this.writeIndex(names);
        let sourceBytes = 0;
        for (let index = 0; index < names.length; index += 1) {
            // Loop: index position remains the stable data-script name consumed by the browser.
            sourceBytes += await this.writeData(names[index], index);
        }
        return Object.freeze({ count: names.length, sourceBytes, names: Object.freeze(names) });
    }
}

// Branch: direct CLI invocation uses the repository image folder or one explicit test/deployment folder.
if (path.resolve(process.argv[1] || '') === path.resolve(MODULE_FILE)) {
    const directory = process.argv[2] ? path.resolve(process.argv[2]) : DEFAULT_IMAGE_DIRECTORY;
    const result = await new ImageLibraryBuilder(directory).build();
    console.log(`Image library generated: ${result.count} files, ${result.sourceBytes} source bytes.`);
}
