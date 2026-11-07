# 36.4 — file tags in the existing resource engine

Before edits: images main01a2651, UI optional reader91dc8cb8 published, local/GitHub/live clean. History checked: 07c2b85 addressed replacement retains exact uploaded bytes and private recovery, 94d6283 rename preserves sidecar/variants, 5fccf99 keeps source files and bounded WebP generation. The new reader must be released with existing setTags and replacement compatibility.

```text
resource-engine/src/ResourceEngine.php::configuration <- PublicApi.run, AdminApi.run/status/list, ui/lib/SharedGallery.php::BznSharedGallery.engine/scan/page/resource/descriptor
resource-engine/src/ResourceCollection.php::normalizeTags <- ResourceCollection.__construct [defaults], ResourceEngine.list [query], ResourceEngine.setTags/upload/rebuildUnlocked [legacy], ui/lib/SharedGallery.php::BznSharedGallery.scan [light/old backend], resource-engine/tests/resource-engine.test.php
resource-engine/src/ResourceEngine.php::list <- resource-engine/src/PublicApi.php::PublicApi.run [GET list], resource-engine/src/AdminApi.php::AdminApi.list [authorized GET], resource-engine/tests/resource-engine.test.php
resource-engine/src/ResourceEngine.php::resource <- resource-engine/src/PublicApi.php::PublicApi.run [binary], resource-engine/tests/resource-engine.test.php
resource-engine/src/ResourceEngine.php::loadCatalog <- ResourceEngine.list/record/setTags/optimize/replace/rename/delete/buildDerivativeBatch [existing catalog reads; cold fallback rebuild]
resource-engine/src/ResourceEngine.php::rebuild <- ResourceEngine.loadCatalog [cold], AdminApi.mutate [rebuild], resource-engine/tests/resource-engine.test.php
resource-engine/src/ResourceEngine.php::rebuildUnlocked <- ResourceEngine.rebuild/setTags/optimize/upload/publishReplacement/rename/delete/buildDerivativeBatch [all existing mutation lock callbacks], resource-engine/tests/resource-engine.test.php::publishReplacement [fixture Reflection]
resource-engine/src/ResourceEngine.php::withLock <- ResourceEngine.rebuild/setTags/optimize/upload/replace/rename/delete/buildDerivativeBatch [collection lock; no nested unlocked rebuild]
resource-engine/src/ResourceEngine.php::loadMetadata <- ResourceEngine.setTags/upload/publishReplacement/rename/rebuildUnlocked [old sidecar read]
resource-engine/src/ResourceEngine.php::setTags <- resource-engine/src/AdminApi.php::AdminApi.mutate [existing auth+CSRF], resource-engine/tests/resource-engine.test.php
resource-engine/src/ResourceEngine.php::replace <- ui/image-gallery-admin.php::BznImageGalleryAdminEndpoint.mutate [WP capability+nonce], resource-engine/tests/resource-engine.test.php [denial]
resource-engine/src/ResourceEngine.php::publishReplacement <- ResourceEngine.replace [locked], resource-engine/tests/resource-engine.test.php [Reflection fixture, exact uploaded bytes]
resource-engine/src/ImageProcessor.php::thumbnail <- ResourceEngine.resource/buildDerivativeBatch, ui/lib/SharedGallery.php::BznSharedGallery.descriptor [binary/prepareUploads]
resource-engine/src/ImageProcessor.php::optimize <- ResourceEngine.optimize/buildDerivativeBatch, ui/lib/SharedGallery.php::BznSharedGallery.descriptor [binary/prepareUploads]
resource-engine/src/ImageProcessor.php::decode <- ImageProcessor.thumbnail/optimize [existing GD source read]
resource-engine/src/ImageProcessor.php::writeWebp <- ImageProcessor.thumbnail/optimize [staged sibling, atomic rename]
resource-engine/src/HttpResponder.php::resource <- PublicApi.run [standalone binary], ui/lib/SharedGallery.php::BznSharedGallery.run [optional descriptor version; old headers preserved when absent]
resource-engine/src/ResourceUrlBuilder.php::decorateList <- PublicApi.run, AdminApi.list, ui/lib/SharedGallery.php::BznSharedGallery.run [legacy UI already versions its own URLs]
```

The portable AdminApi remains an existing consumer even though the UI uses its own WP endpoint. The UI loads ResourceEngine.php and ImageProcessor.php through a trusted host class list; the portable bootstrap and direct CLI contracts also load them. Keep dependencies local to their owning classes.

Read boundary: tagState(collection,name,legacyEntry) reads source metadata once before considering legacy fields; embedded[] is authoritative, invalid context is unknown, codec-unsupported stays null/readonly in the strict DTO. Default UI/portable list uses tagFieldsForLegacyList: a valid unsupported legacy set gets full readonly state=legacy, while a damaged context remains unknown. AND filtering uses complete sets before pagination, and hot binary lookup stays on the existing catalog path.

Replacement decision: historical sidecar/raw and incoming embedded retain exact bytes. A previously embedded set (including []) is carried in its own metadata block in a supported untagged temporary upload before the existing exact publication. Unsupported/MPF/no-room/malformed incoming is refused before moving the selected original if that set cannot be carried. No sidecar-only migration. Existing setTags retains effective defaults and known untagged readonly legacy; strict updateTags uses exact REPLACE/clear. New bulk HTTP/UI controls are outside this checkpoint.

Cache gate identified before edits: same-size metadata changes preserve source/variant mtime, so the former name/size/mtime ETag can return a false 304. Version each selected file from local stat identity, including ready variants, with no full digest on a read. Preserve old descriptors without that field and the old If-Modified-Since-only behavior. Cold derivative creation owns the same collection lock through publication.

## After the 36.4 implementation

```text
resource-engine/src/ResourceEngine.php::tagState <- ResourceEngine.tagFieldsForLegacyList/writeTagUpdate/prepareReplacementTags, ui/lib/SharedGallery.php::BznSharedGallery.tagRecord [strict optional callback]
resource-engine/src/ResourceEngine.php::tagStore <- ResourceEngine.tagState/writeTagUpdate/prepareReplacementTags
resource-engine/src/ResourceEngine.php::legacyTagValues <- ResourceEngine.tagState/tagFieldsForLegacyList
resource-engine/src/ResourceEngine.php::tagMetadata <- ResourceEngine.list/rebuildUnlocked/writeTagUpdate [one snapshot per owner]
resource-engine/src/ResourceEngine.php::tagEntry <- ResourceEngine.list/compileCatalog/writeTagUpdate/publishReplacement [missing [] versus explicit null]
resource-engine/src/ResourceEngine.php::tagFieldsForLegacyList <- ResourceEngine.list/compileCatalog, ui/lib/SharedGallery.php::BznSharedGallery.tagRecord [default optional callback]
resource-engine/src/ResourceEngine.php::filterTagRecords <- ResourceEngine.list [complete AND before slice]
resource-engine/src/ResourceEngine.php::deliveryRevisions <- ResourceEngine.list [visible records only]
resource-engine/src/ResourceEngine.php::setTags <- AdminApi.mutate [authorized legacy action], resource-engine.test.php, EngineTagContract.writes/readOnly
resource-engine/src/ResourceEngine.php::updateTags <- EngineTagContract.exact/writes/readOnly [new HTTP/UI callers not wired]
resource-engine/src/ResourceEngine.php::changeTags <- ResourceEngine.setTags/updateTags
resource-engine/src/ResourceEngine.php::writeTagUpdate <- ResourceEngine.changeTags [withLock callback]
resource-engine/src/ResourceEngine.php::currentTagVariants <- ResourceEngine.writeTagUpdate [only current existing files]
resource-engine/src/ResourceEngine.php::publishTagUpdate <- ResourceEngine.writeTagUpdate [stage sidecar, install selected files, compile and stage both catalogs, commit or recover]
resource-engine/src/ResourceEngine.php::compileCatalog <- ResourceEngine.rebuildUnlocked/publishTagUpdate
resource-engine/src/ResourceEngine.php::catalogArtifacts <- ResourceEngine.rebuildUnlocked/publishTagUpdate
resource-engine/src/ResourceEngine.php::jsonText <- ResourceEngine.writeJson/catalogArtifacts/publishTagUpdate
resource-engine/src/ResourceEngine.php::prepareReplacementTags <- ResourceEngine.publishReplacement [before moving original]
resource-engine/src/ResourceEngine.php::publishReplacement <- ResourceEngine.replace [existing mutation lock], resource-engine.test.php, EngineTagContract.replace [fixture Reflection]
resource-engine/src/ResourceEngine.php::withLock <- ResourceEngine.rebuild/changeTags/optimize/upload/replace/rename/delete/buildDerivativeBatch
resource-engine/src/ResourceEngine.php::loadMetadata <- ResourceEngine.upload/publishReplacement/rename [unchanged historical metadata owner]
resource-engine/src/ResourceEngine.php::sourceFile <- ResourceEngine.tagState/deliveryRevisions/resource/writeTagUpdate/optimize/publishReplacement/rename/delete/buildDerivativeBatch
resource-engine/src/ResourceCollection.php::normalizeTags <- ResourceCollection.__construct, ResourceEngine.legacyTagValues/list/writeTagUpdate/upload, ui/lib/SharedGallery.php::BznSharedGallery.scan [light or absent methods], TagReaderBridgeContract.oldBackend
resource-engine/src/ResourceCollection.php::withMutationLock <- ResourceEngine.withLock, ImageProcessor.derive [cold only; reentrant same collection], EngineTagContract.replace
resource-engine/src/ImageProcessor.php::derive <- ImageProcessor.thumbnail/optimize [same old dimensions and quality]
resource-engine/src/ImageProcessor.php::decode <- ImageProcessor.derive
resource-engine/src/ImageProcessor.php::boundedImage <- ImageProcessor.derive
resource-engine/src/ImageProcessor.php::writeWebp <- ImageProcessor.derive [encoded temporary + embed + source revision recheck + rename], EngineTagContract.staleDerivative [Reflection, refused stale publication]
resource-engine/src/ImageProcessor.php::writeWebpTags <- ImageProcessor.writeWebp [embedded[] included]
resource-engine/src/ImageProcessor.php::tags <- ImageProcessor.decode/writeWebpTags
resource-engine/src/ImageTagStore.php::ImageTagStore.snapshot <- ResourceEngine.tagState/prepareReplacementTags, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagStore.prepare <- ResourceEngine.writeTagUpdate/prepareReplacementTags, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.__construct <- ImageTagStore.prepare, ResourceEngine.writeTagUpdate [legacy-only], existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.revision <- ResourceEngine.resource/deliveryRevisions, ImageProcessor.derive/writeWebp, ImageTagStore.snapshot/prepare, ImageTagTransaction.stage/install/commit/rollback, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.stage <- ImageTagStore.prepare, ResourceEngine.publishTagUpdate, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.install <- ResourceEngine.publishTagUpdate/prepareReplacementTags, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.commit <- ResourceEngine.publishTagUpdate/prepareReplacementTags, existing image-tag-store.test.php
resource-engine/src/ImageTagStore.php::ImageTagTransaction.rollback <- ResourceEngine.publishTagUpdate/prepareReplacementTags, ImageTagStore.prepare, ImageTagTransaction.install, existing image-tag-store.test.php
resource-engine/src/ImageTags.php::ImageTags.read <- ImageProcessor.decode, ImageTagStore.prepare, existing image-tags.test.php
resource-engine/src/ImageTags.php::ImageTags.embed <- ImageProcessor.writeWebpTags, ImageTagStore.prepare, EngineTagContract.replacements, existing image-tags/image-tag-store tests
resource-engine/src/HttpResponder.php::entityTag <- HttpResponder.resource, EngineTagContract.cacheAndRollback [Reflection]
resource-engine/src/ResourceUrlBuilder.php::urls <- ResourceUrlBuilder.decorateList [optional selected-file revisions]
resource-engine/src/ResourceUrlBuilder.php::url <- ResourceUrlBuilder.urls
```

```text
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.respond <- CLI child mode [actual HttpResponder.resource, bounded fixture descriptor]
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.__construct <- CLI test entry
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.run <- CLI test entry [unique root cleanup in finally]
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.writes <- EngineTagContract.run
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.cacheAndRollback <- EngineTagContract.run
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.readOnly <- EngineTagContract.run
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.replacements <- EngineTagContract.run
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.png <- EngineTagContract.__construct/readOnly/replacements
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.sidecar <- EngineTagContract.writes
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.setSidecar <- EngineTagContract.__construct
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.row <- EngineTagContract.exact/writes/replace/replacements/staleDerivative
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.exact <- EngineTagContract.writes/cacheAndRollback/replacements/staleDerivative
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.binary <- EngineTagContract.writes/cacheAndRollback/replacements
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.urls <- EngineTagContract.cacheAndRollback
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.request <- EngineTagContract.cacheAndRollback [actual responder child]
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.reject <- EngineTagContract.writes/cacheAndRollback/readOnly/refusedReplacement/staleDerivative
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.replace <- EngineTagContract.replacements/refusedReplacement [actual validator then existing publication, no HTTP upload ownership emulation]
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.refusedReplacement <- EngineTagContract.replacements
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.staleDerivative <- EngineTagContract.replacements
resource-engine/tests/resource-engine-tags.test.php::EngineTagContract.check <- EngineTagContract.reject/writes/cacheAndRollback/readOnly/replacements/refusedReplacement/staleDerivative
```

Verified on PHP8.2 with tiny isolated files: owner53 assertions plus the existing addressed replacement contract PASS3. The historical legacy fixture still compares exact incoming bytes. Tested known-empty and incoming embedded, source/variants same-size same-mtime delivery, current304/stale200/IMS-only200/legacy304, rollback on second catalog staging failure, existing admin default membership and MPF/no-room/SVG sidecar compatibility, and strict readonly rejection. Actual validator accepts the tiny unsupported/MPF/no-room inputs; replacement then refuses tag admission before source/catalog/staged bytes change. A stale derivative revision cannot publish. Only the actual Engine fixture writes were made; live HTTP upload/actor rights and browser tag actions are not claimed.

Independent stable-pair read-only review verified actual Engine + SharedGallery legacy unsupported AND total1, strict503, and explicit null. Native prepublication health used live UI a54a5be6 and old images01a2651, UTC03:56:06–03:57:06, three completed read cycles1/3/4 with 60/172 and exact order, no project edits. Cycle2 was an intermediate observation and is not counted. Receipt: private `image-tags36-20261107/engine36-4-prepub-gallery.json`.

Live portable bootstrap points to its own images/textures/overlays roots. The future WP mutation uses the design library's separate public working/thumbnail hard links. Their existing aliases must join the guarded selected publication before enabling a WP tag action; this release does not add that endpoint or modify those aliases.
