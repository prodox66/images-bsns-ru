# 34. Addressed common-image replacement

Before edits: main386feb24, clean locally and on the deployed site. Previous
ResourceEngine rename94d6283 and upload validation inspected. Existing public GET
and native upload remain unchanged. The UI's WordPress admin/nonce gate owns the
new caller; no public mutation route is added.

resource-engine/src/ResourceEngine.php::replace <- ui/image-gallery-admin.php::BznImageGalleryAdminEndpoint.mutate [new admin-only explicit id]
resource-engine/src/ResourceEngine.php::validatedUpload <- storeUpload, replace [one MIME/name/size validator, HTTP upload ownership]
resource-engine/src/ResourceEngine.php::publishReplacement <- replace [one collection lock; exact catalog id; previous bytes retained]
resource-engine/src/ResourceEngine.php::storeUpload <- upload [ordinary new-file behavior retained]
resource-engine/src/ResourceEngine.php::rebuildUnlocked <- publishReplacement, existing mutation owners [catalog and metadata rollback if publication fails]

Other callers of the new methods were not found. Contract tests also exercise
the private staged-publication boundary with isolated temporary directories.
