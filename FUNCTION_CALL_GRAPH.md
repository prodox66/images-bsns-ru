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
