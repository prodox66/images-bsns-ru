// Central runtime configuration for the standalone image content host.
(() => {
    'use strict';

    const CONFIGURATION_KEY = 'BZNImageLibraryConfig';
    const SITE_SETTINGS = Object.freeze({
        siteRootUrl: new URL('./', document.baseURI).href,
        interfaceRootUrl: 'https://library-ui.bsns.ru/',
        library: Object.freeze({ id: 'images', callerId: 'imageLibrary', contentType: 'Images', defaultTab: 'Images' }),
        content: Object.freeze({ directory: 'images/', index: 'files.js', dataDirectory: 'data/' }),
        globals: Object.freeze({ files: 'BZNLibraryImageFiles', resourceLibrary: 'BZNResourceLibrary', libraryRuntimeConfig: 'BZNLibraryRuntimeConfig', libraryWindow: 'BZNNewUILibraryWindow' }),
        data: Object.freeze({ indexWidth: 3, scriptExtension: '.js', previewKind: 'image', defaultMime: 'application/octet-stream' }),
        mimeByExtension: Object.freeze({ avif: 'image/avif', gif: 'image/gif', jpeg: 'image/jpeg', jpg: 'image/jpeg', png: 'image/png', svg: 'image/svg+xml', webp: 'image/webp' }),
        labels: Object.freeze({ title: 'Библиотека изображений', use: 'Использовать' }),
        status: Object.freeze({
            loading: 'Загрузка интерфейса…',
            ready: 'Готово: {count} изображений.',
            empty: 'Индекс изображений пока не создан. Загрузите файлы и выполните генератор.',
            error: 'Не удалось загрузить интерфейс библиотеки.',
        }),
        interfacePaths: Object.freeze({
            resourceLibraryScript: 'assets/resource-library-v2.js',
            resourceLibraryStylesheet: 'assets/resource-library.css',
            libraryWindowScript: 'NewUI/Library_Window.js',
            libraryWindowConfiguration: 'NewUI/windows/library_window.json',
            libraryWindowFileBridge: 'NewUI/windows/library_window.data.js',
            lightboxScript: 'assets/lightbox.js',
            lightboxStylesheet: 'assets/lightbox.css',
        }),
    });

    // Function: one resolver owns every resource URL relative to its designated host.
    function resolveUrl(relativePath, rootUrl) {
        return new URL(relativePath, rootUrl).href;
    }

    const imageDirectoryUrl = resolveUrl(SITE_SETTINGS.content.directory, SITE_SETTINGS.siteRootUrl);
    window[CONFIGURATION_KEY] = window[CONFIGURATION_KEY] || Object.freeze({
        ...SITE_SETTINGS,
        imageDirectoryUrl,
        imageIndexUrl: resolveUrl(SITE_SETTINGS.content.index, imageDirectoryUrl),
        imageDataDirectoryUrl: resolveUrl(SITE_SETTINGS.content.dataDirectory, imageDirectoryUrl),
        interface: Object.freeze(Object.fromEntries(
            Object.entries(SITE_SETTINGS.interfacePaths).map(([key, relativePath]) => [key, resolveUrl(relativePath, SITE_SETTINGS.interfaceRootUrl)]),
        )),
    });
})();
