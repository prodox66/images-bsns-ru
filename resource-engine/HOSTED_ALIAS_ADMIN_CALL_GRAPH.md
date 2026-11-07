# 36.11 — hosted portable administration shares the public alias owner

Before: images `78a1e0d`, UI `a5f58aba`, local/GitHub/live clean. The live effective resources/textures/overlays in both configurations resolve to the same physical originals and lock files. Standalone password login is configured, but both exact public `api.php` and `admin-api.php?action=status` returned nginx HTTP 403 in a read-only host probe. No login or write was attempted. The protected source-level legacy path remains possible in a host that exposes it.

```text
resource-engine/src/AdminApi.php::AdminApi.run <- resource-engine/admin-api.php [existing authenticator, existing CSRF]
resource-engine/src/AdminApi.php::AdminApi.mutate <- AdminApi.run [authorized POST only]
resource-engine/src/ResourceEngine.php::ResourceEngine.setTags <- AdminApi.mutate [legacy defaults/known readonly fallback]
resource-engine/src/ResourceEngine.php::ResourceEngine.rename/delete <- AdminApi.mutate, UI image-gallery-admin.php [own collection lock]
resource-engine/src/ResourceEngine.php::ResourceEngine.updateTagsWithPublicAliases <- engine fixture; WP action not yet enabled
ui/lib/SharedImageStorage.php::BznSharedImageStorage.aliasPaths <- BznSharedImageStorage.publishVariant/removeWorking, BznSharedGallery.publicFileUrls
ui/lib/SharedImageStorage.php::BznSharedImageStorage.removeWorking <- BznSharedGallery.removeWorking [exact names inside same addressed lock]
```

The legacy contract is unchanged on independent portable roots. A verified hosted category can use the already published UI name/retirement policy without trusting a browser path or reacquiring the same file lock through a different collection object.

After:

```text
resource-engine/src/AdminApi.php::AdminApi.setSelectedTags <- AdminApi.mutate [existing legacy tags action]
resource-engine/src/AdminApi.php::AdminApi.changeSource <- AdminApi.mutate [existing rename/delete actions]
resource-engine/src/AdminApi.php::AdminApi.hostedAliases <- AdminApi.setSelectedTags/changeSource [configured collection only]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.discover <- AdminApi.hostedAliases, isolated test/read-only host proof
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.matches <- HostedPublicAliases.discover [validated isolated PHP settings return, exact canonical source/engine/type]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.assertSameLock <- HostedPublicAliases.discover [reject split override]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.assertCurrent <- HostedPublicAliases.paths/locked/retire [fresh source alias resolution]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.paths <- ResourceEngine.publicAliasProof [trusted callback under the exact collection lock]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.locked <- AdminApi.setSelectedTags/changeSource [same engine collection]
resource-engine/src/HostedPublicAliases.php::HostedPublicAliases.retire <- AdminApi.changeSource [old selected name, only successful rename/delete]
resource-engine/src/HostedPublicAliases.php::HostedPublicCollectionView.sourceDirectory/resourceId <- BznSharedImageStorage.aliasPaths/workingName
resource-engine/src/HostedPublicAliases.php::HostedPublicCollectionView.withMutationLock <- BznSharedImageStorage.removeWorking [delegates to exact original collection object]
resource-engine/src/ResourceEngine.php::ResourceEngine.setTags <- AdminApi.setSelectedTags, legacy tests [optional public aliases; old defaults/readonly]
```

Malformed existing policy, wrong engine/type in the same image root, stale source alias or split runtime cannot fall back to an alias-unaware mutation. A missing policy or independently verified different image root keeps the standalone route. Existing auth and JSON contracts are unchanged. Hosted tags keep the legacy effective defaults and known readonly sidecar behavior while existing writable aliases join the transaction. Source retirement keeps a byte/no-name-change operation intact, and does not create new aliases.

Actual filesystem fixture copies only the checked code/config into a guarded unique test tree; the config include has a separate scope and validated write targets. The test exercises actual selected tags/readonly/moves, independent and explicit different roots, malformed policy and reselected source. Read-only production bootstrap verification recognized all three intended collections and canonical alias parents without listing private names or writing files. The original fixture incident and the UI scope isolation are recorded as 36.10.1 in `ui/[SPRINTS]/26-11-07-09-22.md`.
