# Function call graph

`runtime-config.js::resolveUrl <- runtime-config.js::configuration initialization`

`image-gallery.js::ImageLibrarySite.installLibraryRuntimeConfig <- ImageLibrarySite.initialize`

`image-gallery.js::ImageLibrarySite.loadScript <- ImageLibrarySite.initialize, ImageLibrarySite.loadImageIndex`

`image-gallery.js::ImageLibrarySite.loadImageIndex <- ImageLibrarySite.ensureImageIndex`

`image-gallery.js::ImageLibrarySite.ensureImageIndex <- ImageLibrarySite.initialize, ImageLibrarySite.providePage`

`image-gallery.js::ImageLibrarySite.item <- ImageLibrarySite.providePage`

`image-gallery.js::ImageLibrarySite.providePage <- ImageLibrarySite.open, external BZNResourceLibrary provider callback`

`image-gallery.js::ImageLibrarySite.open <- index.html::#openImageLibrary click`

`image-gallery.js::ImageLibrarySite.initialize <- image-gallery.js::module bootstrap`

`tools/build-image-library.mjs::ImageLibraryBuilder.discover <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.writeIndex <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.writeData <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.build <- direct CLI, tests`

`tools/build-image-library-legacy.js::LegacyImageLibraryBuilder.discover <- LegacyImageLibraryBuilder.build`

`tools/build-image-library-legacy.js::LegacyImageLibraryBuilder.writeIndex <- LegacyImageLibraryBuilder.build`

`tools/build-image-library-legacy.js::LegacyImageLibraryBuilder.writeData <- LegacyImageLibraryBuilder.build`

`tools/build-image-library-legacy.js::LegacyImageLibraryBuilder.build <- direct legacy Node CLI, tests`

`tools/reindex-on-trigger.js::ImageLibraryTrigger.triggerFingerprint <- ImageLibraryTrigger.run`

`tools/reindex-on-trigger.js::ImageLibraryTrigger.storedFingerprint <- ImageLibraryTrigger.run`

`tools/reindex-on-trigger.js::ImageLibraryTrigger.acquireLock <- ImageLibraryTrigger.run`

`tools/reindex-on-trigger.js::ImageLibraryTrigger.releaseLock <- ImageLibraryTrigger.run`

`tools/reindex-on-trigger.js::ImageLibraryTrigger.run <- direct CRON CLI, tests`

`tools/build-image-library-legacy.js::LegacyImageLibraryBuilder.build <- ImageLibraryTrigger.run`

## Resource Engine v2

`resource-engine/src/ResourceEngine.php::rebuild <- PublicApi list first run, AdminApi rebuild/upload/tags/delete/optimize, tests`

`resource-engine/src/ResourceEngine.php::list <- PublicApi list, AdminApi list, tests`

`resource-engine/src/ResourceEngine.php::resource <- PublicApi binary variants, tests`

`resource-engine/src/ResourceEngine.php::setTags <- AdminApi tags, tests`

`resource-engine/src/ResourceEngine.php::upload <- AdminApi upload`

`resource-engine/src/ResourceCollection.php::validatedUploadName <- ResourceEngine::storeUpload, resource-engine contract test`

`resource-engine/src/ResourceEngine.php::buildThumbnailBatch <- AdminApi thumbnails`

`resource-engine/src/ResourceEngine.php::optimize, ResourceEngine::optimizeBatch <- AdminApi optimize/optimize_batch, tests`

`resource-engine/src/ResourceEngine.php::delete <- AdminApi delete, tests`

`resource-engine/src/ImageProcessor.php::thumbnail <- ResourceEngine resource/buildDerivativeBatch`

`resource-engine/src/ImageProcessor.php::optimize <- ResourceEngine optimize/buildDerivativeBatch`

`resource-engine/src/AdminAuthenticator.php::authorized <- AdminApi status/protected actions; local session or deployment authorization_callback`

`resource-engine/src/PublicApi.php::run <- resource-engine/api.php`

`resource-engine/src/AdminApi.php::run <- resource-engine/admin-api.php`

`resource-engine/resource-client.js::BZNResourceClient.list, resource, blob <- external UI consumers`

`resource-engine/resource-admin.js::BZNResourceAdmin.mount, open <- standalone admin.php, embedded host, resolved resource editor`

`resource-engine/resource-admin.js::BZNResourceAdmin.fetchJson <- status, login, loadGallery, perform`

`resource-engine/resource-admin.js::BZNResourceAdmin.requestAction, responsePreview <- BZNResourceAdmin.fetchJson`

`resource-engine/resource-admin.js::resolveResourceEditor <- external BZNResourceEditor.resolve(type); pictures/images/masks/shadows -> common resources constant, unknown -> UNAVAILABLE`
