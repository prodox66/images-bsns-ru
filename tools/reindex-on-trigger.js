// Cron owner: a lightweight trigger check starts the static image builder only after an FTP marker changes.
'use strict';

const fs = require('fs');
const path = require('path');
const { LegacyImageLibraryBuilder } = require('./build-image-library-legacy.js');

const REPOSITORY_ROOT = path.resolve(__dirname, '..');
const DEFAULT_IMAGE_DIRECTORY = path.join(REPOSITORY_ROOT, 'images');
const TRIGGER_FILE_NAME = 'REINDEX_LIBRARY.trigger';
const STATE_FILE_NAME = '.reindex-library.state';
const LOCK_FILE_NAME = '.reindex-library.lock';
const TEXT_ENCODING = 'utf8';
const LOCK_STALE_MILLISECONDS = 15 * 60 * 1000;
const RESULT_REASONS = Object.freeze({ MISSING: 'trigger-missing', UNCHANGED: 'trigger-unchanged', LOCKED: 'build-locked', BUILT: 'index-built' });

class ImageLibraryTrigger {
    constructor(options) {
        const settings = options || {};
        this.imageDirectory = path.resolve(settings.imageDirectory || DEFAULT_IMAGE_DIRECTORY);
        this.triggerFile = path.join(this.imageDirectory, settings.triggerFileName || TRIGGER_FILE_NAME);
        this.stateFile = path.join(this.imageDirectory, settings.stateFileName || STATE_FILE_NAME);
        this.lockFile = path.join(this.imageDirectory, settings.lockFileName || LOCK_FILE_NAME);
        this.builder = settings.builder || new LegacyImageLibraryBuilder(this.imageDirectory);
        this.lockDescriptor = null;
    }

    // Function: mtime plus size changes when the visible FTP trigger is edited or uploaded again.
    triggerFingerprint() {
        if (!fs.existsSync(this.triggerFile)) return '';
        const statistics = fs.statSync(this.triggerFile);
        return `${statistics.mtime.getTime()}:${statistics.size}`;
    }

    // Function: the ignored state file remembers the last successfully indexed trigger version.
    storedFingerprint() {
        return fs.existsSync(this.stateFile) ? String(fs.readFileSync(this.stateFile, TEXT_ENCODING)).trim() : '';
    }

    // Function: an atomic lock prevents overlapping CRON runs; a crashed stale lock recovers automatically.
    acquireLock() {
        if (fs.existsSync(this.lockFile)) {
            const lockAge = Date.now() - fs.statSync(this.lockFile).mtime.getTime();
            if (lockAge > LOCK_STALE_MILLISECONDS) fs.unlinkSync(this.lockFile);
        }
        try {
            this.lockDescriptor = fs.openSync(this.lockFile, 'wx');
            fs.writeFileSync(this.lockDescriptor, String(process.pid), TEXT_ENCODING);
            return true;
        } catch (error) {
            if (error && error.code === 'EEXIST') return false;
            throw error;
        }
    }

    // Function: every acquired lock is released after success or failure so the next CRON check can proceed.
    releaseLock() {
        if (this.lockDescriptor !== null) fs.closeSync(this.lockDescriptor);
        this.lockDescriptor = null;
        if (fs.existsSync(this.lockFile)) fs.unlinkSync(this.lockFile);
    }

    // Function: unchanged triggers exit before image scanning or Base64 generation.
    run() {
        const fingerprint = this.triggerFingerprint();
        if (!fingerprint) return Object.freeze({ triggered: false, reason: RESULT_REASONS.MISSING });
        if (fingerprint === this.storedFingerprint()) return Object.freeze({ triggered: false, reason: RESULT_REASONS.UNCHANGED });
        if (!this.acquireLock()) return Object.freeze({ triggered: false, reason: RESULT_REASONS.LOCKED });
        try {
            const build = this.builder.build();
            fs.writeFileSync(this.stateFile, `${fingerprint}\n`, TEXT_ENCODING);
            return Object.freeze({ triggered: true, reason: RESULT_REASONS.BUILT, build });
        } finally {
            this.releaseLock();
        }
    }
}

// Branch: CRON logs completed work only; unchanged checks remain silent and do not fill the hosting journal.
if (require.main === module) {
    const result = new ImageLibraryTrigger().run();
    if (result.triggered) console.log(`Image library reindexed: ${result.build.count} files, ${result.build.sourceBytes} source bytes.`);
}

module.exports = Object.freeze({ ImageLibraryTrigger });
