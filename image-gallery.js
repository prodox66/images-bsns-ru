// Standalone image library powered by the shared library-ui host.
(() => {
    'use strict';

    const SITE_CONFIG_KEY = 'BZNImageLibraryConfig';
    const OPEN_BUTTON_ID = 'openImageLibrary';
    const STATUS_ID = 'imageLibraryStatus';

    class ImageLibrarySite {
        constructor(configuration) {
            this.configuration = configuration;
            this.files = Object.freeze([]);
            this.indexReady = false;
            this.indexPromise = null;
            this.openButton = document.getElementById(OPEN_BUTTON_ID);
            this.statusNode = document.getElementById(STATUS_ID);
        }

        // Function: the shared UI reads the same central contract as the Canvas host.
        installLibraryRuntimeConfig() {
            const config = this.configuration;
            window[config.globals.libraryRuntimeConfig] = Object.freeze({
                baseUrls: Object.freeze({ interface: config.interfaceRootUrl, content: Object.freeze({ images: config.siteRootUrl }) }),
                interface: config.interface,
                content: Object.freeze({
                    images: config.siteRootUrl,
                    publicImagesDirectory: config.imageDirectoryUrl,
                    publicImageDataDirectory: config.imageDataDirectoryUrl,
                }),
            });
        }

        // Function: every dependency is a classic script so separate static origins need no CORS permission.
        loadScript(url) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.async = false;
                script.src = url;
                script.addEventListener('load', resolve, { once: true });
                script.addEventListener('error', reject, { once: true });
                document.head.appendChild(script);
            });
        }

        // Function: one retryable loader owns the generated FTP index without blocking the shared interface.
        loadImageIndex() {
            if (this.indexReady) return Promise.resolve(this.files);
            if (this.indexPromise) return this.indexPromise;
            this.indexPromise = this.loadScript(this.configuration.imageIndexUrl)
                .then(() => {
                    const indexedFiles = window[this.configuration.globals.files];
                    if (!Array.isArray(indexedFiles)) throw new Error(this.configuration.status.empty);
                    this.files = Object.freeze(indexedFiles.map((name) => String(name)));
                    this.indexReady = true;
                    return this.files;
                })
                .catch((error) => {
                    // Branch: Refresh must be able to retry after files.js appears on the static content host.
                    this.indexPromise = null;
                    throw error;
                });
            return this.indexPromise;
        }

        // Function: missing content becomes an empty compatible provider response, not a disabled application.
        async ensureImageIndex() {
            try {
                await this.loadImageIndex();
                this.statusNode.textContent = this.configuration.status.ready.replace('{count}', String(this.files.length));
                return true;
            } catch (error) {
                this.files = Object.freeze([]);
                this.statusNode.textContent = this.configuration.status.empty;
                return false;
            }
        }

        // Function: every indexed file becomes a generic immutable record.
        item(name, index) {
            const extension = String(name.split('.').pop() || '').toLowerCase();
            const url = new URL(encodeURIComponent(name), this.configuration.imageDirectoryUrl).href;
            const dataName = `${String(index).padStart(this.configuration.data.indexWidth, '0')}${this.configuration.data.scriptExtension}`;
            return Object.freeze({
                name,
                extension,
                mime: this.configuration.mimeByExtension[extension] || this.configuration.data.defaultMime,
                preview: this.configuration.data.previewKind,
                thumbnail_url: url,
                data_script_url: new URL(dataName, this.configuration.imageDataDirectoryUrl).href,
                url,
            });
        }

        // Function: the fixed page is a deterministic slice of the generated FTP-content index.
        async providePage(context = {}) {
            await this.ensureImageIndex();
            const requestedPageSize = Number(context.pageSize);
            const pageSize = requestedPageSize > 0 ? requestedPageSize : await window[this.configuration.globals.libraryWindow].pageSize();
            const pageCount = Math.max(1, Math.ceil(this.files.length / pageSize));
            const page = Math.max(1, Math.min(pageCount, Number(context.page) || 1));
            const offset = (page - 1) * pageSize;
            const names = this.files.slice(offset, offset + pageSize);
            return Object.freeze({
                ok: true,
                page,
                page_size: pageSize,
                pages: pageCount,
                total: this.files.length,
                items: Object.freeze(names.map((name, pageIndex) => this.item(name, offset + pageIndex))),
            });
        }

        // Function: the reusable window opens the Images tab selected by its JSON caller mapping.
        open() {
            return window[this.configuration.globals.resourceLibrary].open({
                library: this.configuration.library.id,
                callerId: this.configuration.library.callerId,
                contentType: this.configuration.library.contentType,
                provider: (context) => this.providePage(context),
                labels: this.configuration.labels,
            });
        }

        // Function: initialization makes the shared Window and Lightbox usable even before FTP content is indexed.
        async initialize() {
            try {
                this.statusNode.textContent = this.configuration.status.loading;
                this.installLibraryRuntimeConfig();
                await this.loadScript(this.configuration.interface.lightboxScript);
                await this.loadScript(this.configuration.interface.resourceLibraryScript);
                this.openButton.disabled = false;
                this.openButton.addEventListener('click', () => void this.open());
                await this.ensureImageIndex();
            } catch (error) {
                this.statusNode.textContent = this.configuration.status.error;
                throw error;
            }
        }
    }

    const site = new ImageLibrarySite(window[SITE_CONFIG_KEY]);
    window.BZNImageLibrarySite = site;
    void site.initialize();
})();
