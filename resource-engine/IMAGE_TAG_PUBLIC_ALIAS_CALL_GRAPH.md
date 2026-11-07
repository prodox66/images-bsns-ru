# 36.9 — existing public aliases in the selected tag transaction

Before: main `6992f85f` local/GitHub/live, clean. The portable admin still uses `setTags`; the strict portable route is currently consumed by tests. WordPress owns its public working/preview aliases in `ui/lib/SharedImageStorage.php`, under the selected source's category. WordPress does not call an alias-aware writer yet.

```text
resource-engine/src/ResourceEngine.php::ResourceEngine.setTags <- resource-engine/src/AdminApi.php::AdminApi.handle [legacy setter]
resource-engine/src/ResourceEngine.php::ResourceEngine.updateTags <- resource-engine/tests/resource-engine-tags.test.php [runtime callers not found]
resource-engine/src/ResourceEngine.php::ResourceEngine.changeTags <- ResourceEngine.setTags/updateTags [same collection lock]
resource-engine/src/ResourceEngine.php::ResourceEngine.writeTagUpdate <- ResourceEngine.changeTags [current opaque id]
resource-engine/src/ResourceEngine.php::ResourceEngine.currentTagVariants <- ResourceEngine.writeTagUpdate [existing fresh runtime variants]
resource-engine/src/ResourceEngine.php::ResourceEngine.publishTagUpdate <- ResourceEngine.writeTagUpdate [same recovery, metadata + catalog pair]
resource-engine/src/ImageTagStore.php::ImageTagStore.prepare <- ResourceEngine.writeTagUpdate, ResourceEngine.prepareReplacementTags, library-api-bsns-ru/src/PersonalImageTagManifest.php::BznPersonalImageTagManifest.writeSelected, tests
resource-engine/src/ImageTagStore.php::ImageTagTransaction.stage <- ImageTagStore.prepare, ResourceEngine.publishTagUpdate, BznPersonalImageTagManifest.writeSelected, tests
resource-engine/src/ImageTagStore.php::ImageTagTransaction.install/commit/rollback <- ResourceEngine.publishTagUpdate/prepareReplacementTags, BznPersonalImageTagManifest.writeSelected, ImageTagStore.prepare [failure], tests
ui/lib/SharedImageStorage.php::BznSharedImageStorage.publish/publishThumbnail <- ui/lib/SharedGallery.php [current WebP; hardlink or copy]
```

Tiny Linux proof on the production host, outside live storage: replacing the runtime directory entry left its public hardlink on the old inode with old bytes, and changed that old inode's ctime/link count. A same-inode pair therefore needs an exact-byte identity guard that tolerates the transaction's own first unlink, not an unconditional skip of the revision check. External changes and stale copies stay outside the transaction.

After:

```text
resource-engine/src/ResourceEngine.php::ResourceEngine.updateTagsWithPublicAliases <- tests; planned WordPress typed owner [no current HTTP caller found]
resource-engine/src/ResourceEngine.php::ResourceEngine.requestTagUpdate <- ResourceEngine.updateTags/updateTagsWithPublicAliases
resource-engine/src/ResourceEngine.php::ResourceEngine.changeTags <- ResourceEngine.setTags/requestTagUpdate
resource-engine/src/ResourceEngine.php::ResourceEngine.currentAliasTagRecord <- ResourceEngine.changeTags [only host alias path, inside collection lock, physical ID without prepublication]
resource-engine/src/ResourceEngine.php::ResourceEngine.writeTagUpdate <- ResourceEngine.changeTags [strict host callback, separate portable legacy branch]
resource-engine/src/ResourceEngine.php::ResourceEngine.publicAliasProof <- ResourceEngine.writeTagUpdate [calls trusted host resolver with exact collection+name, collects current counterpart revision and absence]
resource-engine/src/ResourceEngine.php::ResourceEngine.publicAliasPath <- ResourceEngine.publicAliasProof [exact category, direct working path or preview subdirectory outside originals]
resource-engine/src/ImageTagStore.php::ImageTagStore.bindCurrentAlias <- ResourceEngine.publicAliasProof [same inode or bounded exact digest, fresh revisions]
resource-engine/src/ImageTagStore.php::ImageTagStore.prepare <- ResourceEngine.writeTagUpdate/prepareReplacementTags, BznPersonalImageTagManifest.writeSelected, tests [optional variant proof and selected no-op observation]
resource-engine/src/ImageTagStore.php::ImageTagStore.assertVariantBytes <- ImageTagStore.prepare [copy proof digest or staged counterpart digest]
resource-engine/src/ImageTagStore.php::ImageTagStore.unchangedProof <- ImageTagStore.prepare [opted-in no-op]
resource-engine/src/ImageTagStore.php::ImageTagStore.unchangedMatches <- ResourceEngine.verifyUnchangedTagTargets
resource-engine/src/ImageTagStore.php::ImageTagStore.boundedDigest <- ImageTagStore.bindCurrentAlias/unchangedMatches [size plus one byte]
resource-engine/src/ResourceEngine.php::ResourceEngine.publishTagUpdate <- ResourceEngine.writeTagUpdate [adds host no-op/missing guard, existing catalog recovery]
resource-engine/src/ResourceEngine.php::ResourceEngine.verifyUnchangedTagTargets <- ResourceEngine.publishTagUpdate [after install, before commit]
resource-engine/src/ImageTagStore.php::ImageTagTransaction.pendingRevisionMatches <- ImageTagTransaction.install [after size/digest, before rename]
resource-engine/src/ImageTagStore.php::ImageTagTransaction.matches <- ImageTagTransaction.stage/install/rollback/commit [size and digest]
```

For an existing hardlink, the final identity check accepts changed ctime only when the same stable inode/mode/owner/size/mtime remains and its link-count decrease equals this transaction's already replaced siblings. The digest is read first; a fresh identity comparison before the move still rejects an equal-byte external inode. Recovery preserves exact prior bytes/mode/mtime, not necessarily the old inode topology. The atomic new files may be separate even on the same filesystem; ordinary publication is the existing owner of hard-link/copy layout. The later WordPress stage must recheck current variant provenance under the same lock and must not create missing aliases during tag mutation.

Actual PHP/GD fixture 29 assertions: hardlink and copy, exact selected bytes and mtimes, no-op identity, stale/link/foreign admission, missing alias, cold index without rejected prepublication, late catalog recovery, and real equal-byte swaps at digest/no-op boundaries. Independent copies keep the verified digest through staging; same-inode aliases match the canonical counterpart's staged read. For opted-in no-ops only, exact digest from the already read bytes is compared with a bounded read, followed by the fresh revision. Two actual Linux fixtures verify 409 and exact external bytes/index on an in-place edit with the same observed inode, size, mtime and ctime: once after copy proof and once during publication of a no-op. Adjacent Store39, Engine53, legacy engine PASS3 and personal API36 with the candidate Store. Independent old→WIP→after revision-order proof used virtual I/O and real source; actual filesystem assertions are distinct. Three prepublication common gallery reads in the existing app tab each settled 60/60 of 172, exact names/order, no failures; no production tag mutation or WordPress action enabled.
