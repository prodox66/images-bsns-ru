// Deployment owner: builds the same static image index on legacy Node.js installations used by shared hosting.
'use strict';

const fs = require('fs');
const path = require('path');

const REPOSITORY_ROOT = path.resolve(__dirname, '..');
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

class LegacyImageLibraryBuilder {
    constructor(imageDirectory) {
        this.imageDirectory = path.resolve(imageDirectory || DEFAULT_IMAGE_DIRECTORY);
        this.dataDirectory = path.join(this.imageDirectory, DATA_DIRECTORY_NAME);
        this.indexFile = path.join(this.imageDirectory, FILE_INDEX_NAME);
    }

    // Function: supported physical image files enter one deterministic list; generated JS files are excluded.
    discover() {
        return fs.readdirSync(this.imageDirectory)
            .filter((name) => {
                const sourcePath = path.join(this.imageDirectory, name);
                return fs.statSync(sourcePath).isFile() && Boolean(MIME_BY_EXTENSION[path.extname(name).toLowerCase()]);
            })
            .sort((left, right) => left.localeCompare(right, 'ru', { numeric: true, sensitivity: 'base' }));
    }

    // Function: one classic script publishes filenames for cross-origin gallery rendering without CORS or PHP.
    writeIndex(names) {
        const source = `// Generated image-library index.\nwindow[${JSON.stringify(FILES_GLOBAL_KEY)}] = Object.freeze(${JSON.stringify(names, null, 4)});\n`;
        fs.writeFileSync(this.indexFile, source, TEXT_ENCODING);
    }

    // Function: one source image becomes the lazy classic-script payload selected by the shared Lightbox/gallery.
    writeData(name, index) {
        const extension = path.extname(name).toLowerCase();
        const bytes = fs.readFileSync(path.join(this.imageDirectory, name));
        const record = Object.freeze({ name, dataUrl: `data:${MIME_BY_EXTENSION[extension]};base64,${bytes.toString(BASE64_ENCODING)}` });
        const outputName = `${String(index).padStart(DATA_INDEX_WIDTH, '0')}${DATA_SCRIPT_EXTENSION}`;
        const source = `// Generated image-library payload.\nwindow[${JSON.stringify(DATA_GLOBAL_KEY)}] = Object.freeze(${JSON.stringify(record)});\n`;
        fs.writeFileSync(path.join(this.dataDirectory, outputName), source, TEXT_ENCODING);
        return bytes.length;
    }

    // Function: one build transaction keeps filenames and numbered payloads in the same stable order.
    build() {
        const names = this.discover();
        if (!fs.existsSync(this.dataDirectory)) fs.mkdirSync(this.dataDirectory);
        this.writeIndex(names);
        let sourceBytes = 0;
        names.forEach((name, index) => {
            // Loop: the generated data number is the exact position published in files.js.
            sourceBytes += this.writeData(name, index);
        });
        return Object.freeze({ count: names.length, sourceBytes, names: Object.freeze(names) });
    }
}

// Branch: direct shared-hosting invocation accepts an optional physical image directory.
if (require.main === module) {
    const directory = process.argv[2] ? path.resolve(process.argv[2]) : DEFAULT_IMAGE_DIRECTORY;
    const result = new LegacyImageLibraryBuilder(directory).build();
    console.log(`Image library generated: ${result.count} files, ${result.sourceBytes} source bytes.`);
}

module.exports = Object.freeze({ LegacyImageLibraryBuilder });
