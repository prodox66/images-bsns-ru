// Standalone image library powered by the shared library-ui host.
(() => {
    'use strict';

    const SITE_CONFIG_KEY = 'BZNImageLibraryConfig';
    const OPEN_BUTTON_ID = 'openImageLibrary';
    const STATUS_ID = 'imageLibraryStatus';
    const RESOURCE_CLIENT_KEY = 'BZNResourceClient';
    const FIRST_PAGE = 1;
    const EMPTY_VALUE = '';

    class ImageLibrarySite {
        constructor(configuration) {
            this.configuration = configuration;
            this.files = Object.freeze([]);
            this.indexReady = false;
            this.indexPromise = null;
            this.resourceClientPromise = null;
            this.resourceClient = null;
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
                resourceEngine: config.resourceEngine || null,
            });
        }

        // Function: the API client is loaded once from this content host and keeps the executable endpoint in configuration.
        async ensureResourceClient() {
            const engine = this.configuration.resourceEngine;
            if (!engine?.clientScript || !engine?.endpoint) throw new Error('Каталог ресурсов не настроен.');
            if (this.resourceClient) return this.resourceClient;
            if (!this.resourceClientPromise) {
                this.resourceClientPromise = this.loadScript(engine.clientScript).then(() => {
                    const Constructor = window[RESOURCE_CLIENT_KEY];
                    if (typeof Constructor !== 'function') throw new Error('Клиент каталога недоступен.');
                    this.resourceClient = new Constructor({ endpoint: engine.endpoint });
                    return this.resourceClient;
                }).catch((error) => {
                    // Branch: a failed load may succeed on the next library opening or Refresh.
                    this.resourceClientPromise = null;
                    throw error;
                });
            }
            return this.resourceClientPromise;
        }

        // Function: one API record becomes the gallery's existing generic image contract.
        resourceItem(record) {
            const urls = record?.urls || {};
            const name = String(record?.name || EMPTY_VALUE);
            const url = String(urls.resource || urls.original || EMPTY_VALUE);
            if (!name || !url) return null;
            const extension = pathExtension(name);
            return Object.freeze({
                id: String(record.id || EMPTY_VALUE),
                type: String(record.type || this.configuration.resourceEngine.type),
                name,
                extension,
                mime: String(record.mime || this.configuration.mimeByExtension[extension] || this.configuration.data.defaultMime),
                preview: this.configuration.data.previewKind,
                thumbnail_url: String(urls.thumbnail || url),
                url,
            });
        }

        // Function: the live resource catalog supplies new uploads immediately in its server-owned order.
        async provideResourcePage(context = {}) {
            const client = await this.ensureResourceClient();
            const requestedPageSize = Number(context.pageSize);
            const pageSize = requestedPageSize > 0 ? requestedPageSize : await window[this.configuration.globals.libraryWindow].pageSize();
            const response = await client.list({
                type: this.configuration.resourceEngine.type,
                page: Math.max(FIRST_PAGE, Number(context.page) || FIRST_PAGE),
                pageSize,
            });
            return Object.freeze({ ...response, items: Object.freeze((response.items || []).map((record) => this.resourceItem(record)).filter(Boolean)) });
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
        async provideStaticPage(context = {}) {
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

        // Function: the live catalog owns normal ordering; the old generated scripts remain an outage fallback.
        async providePage(context = {}) {
            if (this.configuration.resourceEngine) {
                try {
                    return await this.provideResourcePage(context);
                } catch (error) {
                    // Branch: a temporary gateway failure must not disable the established static gallery.
                    console.warn('BZN image resource catalog fallback:', error);
                }
            }
            return this.provideStaticPage(context);
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
                if (this.configuration.resourceEngine) {
                    try {
                        const firstPage = await this.provideResourcePage({ page: FIRST_PAGE, pageSize: FIRST_PAGE });
                        this.statusNode.textContent = this.configuration.status.ready.replace('{count}', String(firstPage.total));
                        return;
                    } catch (error) {
                        // Branch: the generated FTP index is still available when the API gateway is down.
                        console.warn('BZN image resource catalog fallback:', error);
                    }
                }
                await this.ensureImageIndex();
            } catch (error) {
                this.statusNode.textContent = this.configuration.status.error;
                throw error;
            }
        }
    }

    // Function: the existing MIME table is keyed by extension without repeating the parsing rule.
    function pathExtension(name) {
        return String(name.split('.').pop() || EMPTY_VALUE).toLowerCase();
    }

    const site = new ImageLibrarySite(window[SITE_CONFIG_KEY]);
    window.BZNImageLibrarySite = site;
    void site.initialize();
})();
