(function installBznResourceClient(globalScope) {
    'use strict';

    const DEFAULT_KIND = 'list';
    const DEFAULT_TYPE = 'resources';
    const DEFAULT_PAGE = 1;

    /** Resolves the folder that owns this script without knowing its deployment domain. */
    function scriptBaseUrl() {
        const script = document.currentScript;
        const fallbackUrl = new URL('./', globalScope.location.href);
        return script && script.src ? new URL('./', script.src) : fallbackUrl;
    }

    /** Domain-independent client for lists, originals, thumbnails and optimized resources. */
    class BZNResourceClient {
        constructor(options) {
            const settings = options || {};
            const baseUrl = settings.baseUrl ? new URL(settings.baseUrl, globalScope.location.href) : scriptBaseUrl();
            this.endpointUrl = new URL(settings.endpoint || 'api.php', baseUrl);
        }

        /** Builds a resource URL solely from the agreed type, kind and opaque id. */
        url(request) {
            const parameters = request || {};
            const url = new URL(this.endpointUrl.href);
            url.searchParams.set('type', parameters.type || DEFAULT_TYPE);
            url.searchParams.set('kind', parameters.kind || DEFAULT_KIND);
            if (parameters.id) url.searchParams.set('id', parameters.id);
            if (parameters.tags && parameters.tags.length) url.searchParams.set('tags', parameters.tags.join(','));
            if (parameters.page) url.searchParams.set('page', String(parameters.page));
            if (parameters.pageSize) url.searchParams.set('page_size', String(parameters.pageSize));
            return url.href;
        }

        /** Returns one logical page and normalizes its links against the configured resource server. */
        async list(request) {
            const parameters = Object.assign({}, request || {}, {
                kind: DEFAULT_KIND,
                page: (request && request.page) || DEFAULT_PAGE,
            });
            const response = await fetch(this.url(parameters), { mode: 'cors' });
            const payload = await this.readJson(response);
            payload.items = (payload.items || []).map((item) => this.normalizeItem(item));
            return payload;
        }

        /** Returns the browser Response for any binary resource variant. */
        async resource(request) {
            const response = await fetch(this.url(request || {}), { mode: 'cors' });
            if (!response.ok) {
                const payload = await this.readJson(response);
                throw new Error(payload.error || 'Не удалось получить ресурс.');
            }
            return response;
        }

        /** Returns a Blob while preserving the implementation boundary behind this client. */
        async blob(request) {
            return (await this.resource(request)).blob();
        }

        /** Makes API-provided paths portable when the caller lives on another domain. */
        normalizeItem(item) {
            const normalized = Object.assign({}, item, { urls: Object.assign({}, item.urls || {}) });
            Object.keys(normalized.urls).forEach((name) => {
                // Loop: every logical variant inherits the configured endpoint origin.
                normalized.urls[name] = new URL(normalized.urls[name], this.endpointUrl).href;
            });
            return normalized;
        }

        /** Decodes one JSON response and turns its public error into a caller-friendly exception. */
        async readJson(response) {
            const payload = await response.json();
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || 'Ошибка сервиса ресурсов.');
            }
            return payload;
        }
    }

    globalScope.BZNResourceClient = BZNResourceClient;
})(window);
