# Public image categories

| Collection | Source directory | Purpose |
|---|---|---|
| resources | images/ | Existing pictures, unchanged opaque IDs and URLs |
| textures | textures/ | Background and fill textures |
| overlays | overlays/ | Brush strokes, dividers, frames and effect overlays |

`config.local.php` owns deployment overrides; this deployment loads `config.example.php` with recursive merge. Each collection retains its own catalog, metadata, lock, thumbnails, optimized copies and recoverable trash. Actual media and generated data stay outside Git. Existing files are not classified or moved by this structural change. The Canvas editor owns the category tabs and captured target. Masks and displacement maps have separate existing providers.

resource-engine/config.example.php::collections <- bootstrap.php -> EngineConfiguration -> ResourceCollection
ResourceEngine::list/upload/rename/delete/optimizeBatch <- public API and WordPress capability/nonce gateway; collection/type is resolved from an allowed logical category.

Checked: private native PHP mutations in all three collections, existing public image-host browser3 and contract tests. Publication and final frontend evidence are recorded in the sprint.
