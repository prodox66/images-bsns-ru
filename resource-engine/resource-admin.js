(function installBznResourceAdmin(globalScope) {
    'use strict';

    const COMMON_RESOURCE_COLLECTION = 'resources';
    const DEFAULT_COLLECTION = COMMON_RESOURCE_COLLECTION;
    const DEFAULT_PAGE = 1;
    const DEFAULT_PAGE_SIZE = 30;
    const EMPTY_TEXT = '';
    const RESPONSE_PREVIEW_LIMIT = 240;
    const RESOURCE_EDITOR_TYPES = Object.freeze({
        PICTURES: 'pictures',
        IMAGES: 'images',
        MASKS: 'masks',
        SHADOWS: 'shadows',
    });
    const RESOURCE_COLLECTION_BY_EDITOR_TYPE = Object.freeze({
        [RESOURCE_EDITOR_TYPES.PICTURES]: COMMON_RESOURCE_COLLECTION,
        [RESOURCE_EDITOR_TYPES.IMAGES]: COMMON_RESOURCE_COLLECTION,
        [RESOURCE_EDITOR_TYPES.MASKS]: COMMON_RESOURCE_COLLECTION,
        [RESOURCE_EDITOR_TYPES.SHADOWS]: COMMON_RESOURCE_COLLECTION,
    });
    const UNAVAILABLE_RESOURCE_EDITOR = Object.freeze({
        available: false,
        type: 'unavailable',
        mount: async () => false,
        open: async () => false,
    });
    const TEXT = Object.freeze({
        title: 'Серверная галерея',
        loginTitle: 'Вход администратора',
        password: 'Пароль',
        login: 'Войти',
        upload: 'Загрузить',
        rebuild: 'Перестроить',
        thumbnails: 'Создать эскизы',
        optimizeBatch: 'Конвертировать в WebP',
        optimizeOne: 'WebP',
        optimized: 'WebP готов',
        vector: 'SVG',
        saveTags: 'Сохранить теги',
        remove: 'Удалить',
        previous: 'Назад',
        next: 'Далее',
        close: 'Закрыть',
        logout: 'Выйти',
        loading: 'Загрузка…',
        ready: 'Готово.',
        empty: 'В этой категории пока нет ресурсов.',
        tags: 'теги через запятую',
        uploadTags: 'общие теги загружаемых файлов',
        deleteQuestion: 'Удалить этот ресурс?',
        containerMissing: 'Не найден контейнер серверной галереи.',
        requestFailed: 'Ошибка админской галереи.',
    });

    /** Finds this module folder so the same widget can be mounted from any page or domain. */
    function moduleBaseUrl() {
        const ownerScript = Array.from(document.scripts)
            .find((script) => /resource-admin\.js(?:\?|$)/.test(script.src));
        return ownerScript ? new URL('./', ownerScript.src) : new URL('./', globalScope.location.href);
    }

    /** Owns upload, tags, thumbnails, WebP and catalog rebuilding behind one reusable interface. */
    class BZNResourceAdmin {
        constructor(options) {
            const settings = options || {};
            this.baseUrl = settings.baseUrl
                ? new URL(settings.baseUrl, globalScope.location.href)
                : moduleBaseUrl();
            this.adminEndpoint = new URL(settings.adminEndpoint || 'admin-api.php', this.baseUrl);
            this.resourceClient = new globalScope.BZNResourceClient({
                baseUrl: this.baseUrl,
                endpoint: settings.publicEndpoint || 'api.php',
            });
            this.collection = settings.collection || DEFAULT_COLLECTION;
            this.page = DEFAULT_PAGE;
            this.pageSize = Number(settings.pageSize) || DEFAULT_PAGE_SIZE;
            this.modal = settings.modal !== false;
            this.csrf = EMPTY_TEXT;
            this.collections = [];
            this.root = null;
            this.panel = null;
            this.busy = false;
            this.busyControlStates = [];
            this.available = true;
            this.type = this.collection;
        }

        /** Mounts the complete tool into a supplied host without depending on its surrounding markup. */
        async mount(target) {
            const container = typeof target === 'string' ? document.querySelector(target) : target;
            if (!container) throw new Error(TEXT.containerMissing);
            this.root = container;
            this.root.classList.add('bzn-resource-admin');
            if (this.modal) this.root.classList.add('bzn-resource-admin--modal');
            await this.bootstrap();
            return this;
        }

        /** Opens this same component as a modal for any external button handler. */
        async open() {
            if (!this.root) {
                const overlay = document.createElement('div');
                document.body.appendChild(overlay);
                await this.mount(overlay);
            }
            this.root.hidden = false;
            document.documentElement.classList.add('bzn-resource-admin-open');
            return this;
        }

        /** Closes only modal placement while retaining its current authenticated session. */
        close() {
            if (!this.root || !this.modal) return false;
            this.root.hidden = true;
            document.documentElement.classList.remove('bzn-resource-admin-open');
            return true;
        }

        /** Resolves authorization before rendering either the login boundary or the tool. */
        async bootstrap() {
            this.renderLoading();
            try {
                const status = await this.status();
                this.collections = status.collections || [];
                this.csrf = status.csrf || EMPTY_TEXT;
                if (!status.authorized) {
                    this.renderLogin(status.password_login === true);
                    return;
                }
                this.renderTool();
                await this.loadGallery();
            } catch (error) {
                this.renderFailure(error.message);
            }
        }

        /** Fetches the small authorization/bootstrap contract. */
        async status() {
            const url = new URL(this.adminEndpoint.href);
            url.searchParams.set('action', 'status');
            return this.fetchJson(url, { method: 'GET' });
        }

        /** Renders the optional standalone password adapter; embedded role callbacks bypass it. */
        renderLogin(passwordLoginAvailable) {
            this.root.replaceChildren();
            const panel = this.createPanel();
            const title = document.createElement('h1');
            title.textContent = TEXT.loginTitle;
            const form = document.createElement('form');
            form.className = 'bzn-resource-admin__login';
            const password = document.createElement('input');
            password.name = 'password';
            password.type = 'password';
            password.placeholder = TEXT.password;
            password.required = true;
            password.disabled = !passwordLoginAvailable;
            const submit = this.createButton(TEXT.login, { type: 'submit', disabled: !passwordLoginAvailable });
            const message = this.createMessage();
            form.append(password, submit, message);
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                message.textContent = TEXT.loading;
                try {
                    const data = new FormData(form);
                    data.set('action', 'login');
                    const response = await this.fetchJson(this.adminEndpoint, { method: 'POST', body: data });
                    this.collections = response.collections || [];
                    this.csrf = response.csrf || EMPTY_TEXT;
                    this.renderTool();
                    await this.loadGallery();
                } catch (error) {
                    message.textContent = error.message;
                }
            });
            panel.append(title, form);
            this.root.appendChild(panel);
        }

        /** Creates the single tool shell shared by standalone, modal and cabinet placements. */
        renderTool() {
            this.root.replaceChildren();
            this.panel = this.createPanel();
            this.panel.append(
                this.createHeader(),
                this.createUploadForm(),
                this.createToolbar(),
                this.createMessage('message'),
                this.createGrid(),
                this.createPagination()
            );
            this.root.appendChild(this.panel);
        }

        /** Creates the title, collection selector and shell actions. */
        createHeader() {
            const header = document.createElement('header');
            header.className = 'bzn-resource-admin__header';
            const title = document.createElement('h1');
            title.textContent = TEXT.title;
            const selector = document.createElement('select');
            selector.className = 'bzn-resource-admin__select';
            this.collections.forEach((collection) => {
                // Loop: every configured collection appears in the same reusable tool.
                const option = document.createElement('option');
                option.value = collection.id;
                option.textContent = collection.label;
                option.selected = collection.id === this.collection;
                selector.appendChild(option);
            });
            selector.addEventListener('change', async () => {
                this.collection = selector.value;
                this.page = DEFAULT_PAGE;
                await this.loadGallery();
            });
            header.append(title, selector);
            header.appendChild(this.createButton(TEXT.logout, { onClick: () => this.perform('logout', null, false) }));
            if (this.modal) header.appendChild(this.createButton(TEXT.close, { onClick: () => this.close() }));
            return header;
        }

        /** Creates one upload surface with optional shared tags. */
        createUploadForm() {
            const form = document.createElement('form');
            form.className = 'bzn-resource-admin__upload';
            const files = document.createElement('input');
            files.name = 'files[]';
            files.type = 'file';
            files.accept = 'image/png,image/jpeg,image/webp,image/gif,image/avif,image/svg+xml';
            files.multiple = true;
            files.required = true;
            const tags = document.createElement('input');
            tags.name = 'tags';
            tags.type = 'text';
            tags.placeholder = TEXT.uploadTags;
            form.append(files, tags, this.createButton(TEXT.upload, { type: 'submit' }));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                await this.perform('upload', new FormData(form));
                form.reset();
            });
            return form;
        }

        /** Creates bounded maintenance actions; none runs automatically. */
        createToolbar() {
            const toolbar = document.createElement('div');
            toolbar.className = 'bzn-resource-admin__tools';
            toolbar.append(
                this.createButton(TEXT.rebuild, { onClick: () => this.perform('rebuild') }),
                this.createButton(TEXT.thumbnails, { onClick: () => this.perform('thumbnails') }),
                this.createButton(TEXT.optimizeBatch, { onClick: () => this.perform('optimize_batch') })
            );
            return toolbar;
        }

        /** Loads one server-owned page and renders cached thumbnail endpoints only. */
        async loadGallery() {
            const message = this.role('message');
            message.textContent = TEXT.loading;
            try {
                const url = new URL(this.adminEndpoint.href);
                url.searchParams.set('action', 'list');
                url.searchParams.set('type', this.collection);
                url.searchParams.set('page', String(this.page));
                url.searchParams.set('page_size', String(this.pageSize));
                const response = await this.fetchJson(url, { method: 'GET' });
                response.items = (response.items || []).map((item) => this.resourceClient.normalizeItem(item));
                this.renderItems(response.items);
                this.renderPagination(response);
                message.textContent = response.total + ' · ' + response.page + ' / ' + response.pages;
            } catch (error) {
                message.textContent = error.message;
            }
        }

        /** Renders compact cards with tag editing and item-level conversion. */
        renderItems(items) {
            const grid = this.role('grid');
            grid.replaceChildren();
            if (!items.length) {
                const empty = document.createElement('p');
                empty.textContent = TEXT.empty;
                grid.appendChild(empty);
                return;
            }
            items.forEach((item) => {
                // Loop: one card owns only its opaque id, tags and black-box URLs.
                const card = document.createElement('article');
                card.className = 'bzn-resource-admin__card';
                const image = document.createElement('img');
                image.loading = 'lazy';
                image.decoding = 'async';
                image.src = item.urls.thumbnail;
                image.alt = item.name;
                const name = document.createElement('p');
                name.className = 'bzn-resource-admin__name';
                name.textContent = item.name;
                name.title = item.name;
                const tags = document.createElement('input');
                tags.type = 'text';
                tags.value = (item.tags || []).join(', ');
                tags.placeholder = TEXT.tags;
                const actions = document.createElement('div');
                actions.className = 'bzn-resource-admin__card-actions';
                const vectorResource = item.mime === 'image/svg+xml';
                const optimized = item.has_optimized === true;
                const optimizeLabel = vectorResource ? TEXT.vector : (optimized ? TEXT.optimized : TEXT.optimizeOne);
                actions.append(
                    this.createButton(TEXT.saveTags, {
                        onClick: () => this.perform('tags', this.itemData(item.id, { tags: tags.value })),
                    }),
                    this.createButton(optimizeLabel, {
                        disabled: vectorResource || optimized,
                        onClick: () => this.perform('optimize', this.itemData(item.id)),
                    }),
                    this.createButton(TEXT.remove, {
                        className: 'bzn-resource-admin__button bzn-resource-admin__button--danger',
                        onClick: () => {
                            if (globalScope.confirm(TEXT.deleteQuestion)) {
                                this.perform('delete', this.itemData(item.id));
                            }
                        },
                    })
                );
                card.append(image, name, tags, actions);
                grid.appendChild(card);
            });
        }

        /** Rebuilds navigation from server pagination instead of guessing catalog size. */
        renderPagination(response) {
            const pagination = this.role('pagination');
            pagination.replaceChildren();
            const previous = this.createButton(TEXT.previous, {
                disabled: response.page <= DEFAULT_PAGE,
                onClick: async () => {
                    this.page -= 1;
                    await this.loadGallery();
                },
            });
            const status = document.createElement('span');
            status.textContent = response.page + ' / ' + response.pages;
            const next = this.createButton(TEXT.next, {
                disabled: response.page >= response.pages,
                onClick: async () => {
                    this.page += 1;
                    await this.loadGallery();
                },
            });
            pagination.append(previous, status, next);
        }

        /** Executes one protected operation then refreshes the same collection. */
        async perform(action, formData, reload = true) {
            if (this.busy) return null;
            this.busy = true;
            this.captureBusyControls();
            const data = formData || new FormData();
            data.set('action', action);
            data.set('type', this.collection);
            const message = this.role('message');
            if (message) message.textContent = TEXT.loading;
            try {
                const response = await this.fetchJson(this.adminEndpoint, {
                    method: 'POST',
                    headers: { 'X-BZN-CSRF': this.csrf },
                    body: data,
                });
                if (action === 'logout') {
                    this.csrf = EMPTY_TEXT;
                    await this.bootstrap();
                    return response;
                }
                if (reload) await this.loadGallery();
                const resultMessage = this.role('message');
                if (resultMessage) {
                    const processed = Number.isFinite(response.processed) ? ' Обработано: ' + response.processed + '.' : EMPTY_TEXT;
                    const remaining = Number.isFinite(response.remaining) ? ' Осталось: ' + response.remaining + '.' : EMPTY_TEXT;
                    const errors = response.errors && Object.keys(response.errors).length
                        ? ' Ошибок: ' + Object.keys(response.errors).length + '.'
                        : EMPTY_TEXT;
                    resultMessage.textContent = TEXT.ready + processed + remaining + errors;
                }
                return response;
            } catch (error) {
                if (message) message.textContent = error.message;
                return null;
            } finally {
                this.restoreBusyControls();
                this.busy = false;
            }
        }

        /** Disables the current control set once so repeated clicks cannot queue conversion jobs. */
        captureBusyControls() {
            this.busyControlStates = this.panel
                ? Array.from(this.panel.querySelectorAll('button, input, select')).map((control) => ({
                    control,
                    disabled: control.disabled,
                }))
                : [];
            this.busyControlStates.forEach(({ control }) => { control.disabled = true; });
            if (this.panel) this.panel.setAttribute('aria-busy', 'true');
        }

        /** Restores exactly the disabled states that existed before the request. */
        restoreBusyControls() {
            this.busyControlStates.forEach(({ control, disabled }) => { control.disabled = disabled; });
            this.busyControlStates = [];
            if (this.panel) this.panel.removeAttribute('aria-busy');
        }

        /** Creates request data for one opaque resource id. */
        itemData(resourceId, fields) {
            const data = new FormData();
            data.set('id', resourceId);
            Object.entries(fields || {}).forEach(([name, value]) => data.set(name, value));
            return data;
        }

        /** Reads one API response under the current standalone or cabinet session. */
        async fetchJson(url, options) {
            const requestOptions = options || {};
            const action = this.requestAction(url, requestOptions);
            const response = await fetch(url, Object.assign({ credentials: 'include' }, requestOptions));
            const responseText = await response.text();
            let payload;
            try {
                payload = JSON.parse(responseText);
            } catch (error) {
                const contentType = response.headers.get('content-type') || 'unknown';
                const preview = this.responsePreview(responseText);
                const suffix = preview ? ' Ответ: ' + preview : EMPTY_TEXT;
                throw new Error('Некорректный ответ сервера [action=' + action + '; HTTP ' + response.status + '; ' + contentType + '].' + suffix);
            }
            if (!response.ok || payload.ok === false) {
                const diagnostic = payload.diagnostic ? '; diagnostic=' + payload.diagnostic : EMPTY_TEXT;
                throw new Error((payload.error || TEXT.requestFailed) + ' [action=' + action + '; HTTP ' + response.status + diagnostic + ']');
            }
            return payload;
        }

        /** Resolves a semantic action name without exposing request credentials. */
        requestAction(url, options) {
            const targetUrl = new URL(url, globalScope.location.href);
            const formAction = options.body && typeof options.body.get === 'function'
                ? options.body.get('action')
                : EMPTY_TEXT;
            return String(formAction || targetUrl.searchParams.get('action') || 'unknown');
        }

        /** Produces a bounded plain-text preview when a proxy corrupts the JSON contract. */
        responsePreview(source) {
            return String(source || EMPTY_TEXT)
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, RESPONSE_PREVIEW_LIMIT);
        }

        /** Creates one native control inside this isolated remote tool. */
        createButton(label, options) {
            const settings = options || {};
            const button = document.createElement('button');
            button.type = settings.type || 'button';
            button.className = settings.className || 'bzn-resource-admin__button';
            button.textContent = label;
            button.disabled = Boolean(settings.disabled);
            if (typeof settings.onClick === 'function') button.addEventListener('click', settings.onClick);
            return button;
        }

        /** Creates the isolated panel shared by every placement. */
        createPanel() {
            const panel = document.createElement('section');
            panel.className = 'bzn-resource-admin__panel';
            return panel;
        }

        /** Creates a message node optionally addressable within this exact instance. */
        createMessage(role) {
            const message = document.createElement('p');
            message.className = 'bzn-resource-admin__message';
            if (role) message.dataset.role = role;
            return message;
        }

        /** Creates the gallery host without injecting resource HTML. */
        createGrid() {
            const grid = document.createElement('section');
            grid.className = 'bzn-resource-admin__grid';
            grid.dataset.role = 'grid';
            return grid;
        }

        /** Creates the pagination host owned by the current component instance. */
        createPagination() {
            const pagination = document.createElement('nav');
            pagination.className = 'bzn-resource-admin__pagination';
            pagination.dataset.role = 'pagination';
            return pagination;
        }

        /** Resolves one role only inside this tool instance. */
        role(name) {
            return this.panel?.querySelector?.('[data-role="' + name + '"]') || null;
        }

        /** Shows an inert progress shell before authorization resolves. */
        renderLoading() {
            this.root.replaceChildren();
            const panel = this.createPanel();
            panel.textContent = TEXT.loading;
            this.root.appendChild(panel);
        }

        /** Shows one terminal bootstrap message without leaving a half-mounted tool. */
        renderFailure(message) {
            this.root.replaceChildren();
            const panel = this.createPanel();
            panel.classList.add('bzn-resource-admin__panel--error');
            panel.textContent = message;
            this.root.appendChild(panel);
        }
    }

    globalScope.BZNResourceAdmin = Object.freeze({
        Controller: BZNResourceAdmin,
        mount: async (target, options) => new BZNResourceAdmin(options).mount(target),
        open: async (options) => new BZNResourceAdmin(Object.assign({}, options || {}, { modal: true })).open(),
    });

    /** Resolves one configured resource type to the common editor or the stable unavailable constant. */
    async function resolveResourceEditor(resourceType, options) {
        const normalizedType = String(resourceType || EMPTY_TEXT).trim();
        const collectionType = RESOURCE_COLLECTION_BY_EDITOR_TYPE[normalizedType];
        if (!collectionType) return UNAVAILABLE_RESOURCE_EDITOR;
        const controller = new BZNResourceAdmin(Object.assign({}, options || {}, { collection: collectionType }));
        controller.type = normalizedType;
        try {
            const response = await controller.status();
            const supported = (response.collections || []).some((collection) => collection.id === collectionType);
            return supported ? controller : UNAVAILABLE_RESOURCE_EDITOR;
        } catch (error) {
            return UNAVAILABLE_RESOURCE_EDITOR;
        }
    }

    globalScope.BZNResourceEditor = Object.freeze({
        UNAVAILABLE: UNAVAILABLE_RESOURCE_EDITOR,
        types: RESOURCE_EDITOR_TYPES,
        resolve: resolveResourceEditor,
    });

    const standaloneRoot = document.getElementById('resourceAdminRoot');
    if (standaloneRoot) {
        const controller = new BZNResourceAdmin({ modal: standaloneRoot.dataset.mode !== 'page' });
        controller.mount(standaloneRoot);
    }
})(window);
