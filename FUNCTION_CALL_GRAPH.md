# Function call graph

`runtime-config.js::resolveUrl <- runtime-config.js::configuration initialization`

`image-gallery.js::ImageLibrarySite.installLibraryRuntimeConfig <- ImageLibrarySite.initialize`

`image-gallery.js::ImageLibrarySite.loadScript <- ImageLibrarySite.initialize`

`image-gallery.js::ImageLibrarySite.item <- ImageLibrarySite.providePage`

`image-gallery.js::ImageLibrarySite.providePage <- ImageLibrarySite.open, external BZNResourceLibrary provider callback`

`image-gallery.js::ImageLibrarySite.open <- index.html::#openImageLibrary click`

`image-gallery.js::ImageLibrarySite.initialize <- image-gallery.js::module bootstrap`

`tools/build-image-library.mjs::ImageLibraryBuilder.discover <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.writeIndex <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.writeData <- ImageLibraryBuilder.build`

`tools/build-image-library.mjs::ImageLibraryBuilder.build <- direct CLI, tests`
