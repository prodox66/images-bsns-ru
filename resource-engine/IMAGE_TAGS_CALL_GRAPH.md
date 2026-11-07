# Embedded image tags: parser boundary 36

Перед первой правкой07.11.2026: существующие ResourceEngine::setTags/rebuildUnlocked сохраняют/читают sidecar metadata.json, а опубликованный UI common list использует собственный SharedGallery::scan. Personal API manifest и Yandex provider имеют отдельные владельцы. Новый portable parser пока не подключён к этим callers. Прямые внешние вызовы не найдены; не считать planned wiring выполненной реализацией36.

```text
resource-engine/src/ImageTags.php::ImageTags.__construct <- resource-engine/tests/image-tags.test.php::ImageTagsContract.__construct, resource-engine/tests/image-tags.test.php::ImageTagsContract.sizePolicy [external production callers не найдены]
resource-engine/src/ImageTags.php::ImageTags.normalize <- resource-engine/src/ImageTags.php::ImageTags.readSource, resource-engine/src/ImageTags.php::ImageTags.embed, resource-engine/tests/image-tags.test.php::ImageTagsContract.numericTags, resource-engine/tests/image-tags.test.php::ImageTagsContract.malformed
resource-engine/src/ImageTags.php::ImageTags.read <- resource-engine/tests/image-tags.test.php::ImageTagsContract.roundTrip, resource-engine/tests/image-tags.test.php::ImageTagsContract.numericTags, resource-engine/tests/image-tags.test.php::ImageTagsContract.pngVariants, resource-engine/tests/image-tags.test.php::ImageTagsContract.malformed, resource-engine/tests/image-tags.test.php::ImageTagsContract.sizePolicy [external production callers не найдены]
resource-engine/src/ImageTags.php::ImageTags.readFile <- resource-engine/tests/image-tags.test.php::ImageTagsContract.fileReader [external production callers не найдены]
resource-engine/src/ImageTags.php::ImageTags.embed <- resource-engine/tests/image-tags.test.php::ImageTagsContract.roundTrip, resource-engine/tests/image-tags.test.php::ImageTagsContract.foreignFixture, resource-engine/tests/image-tags.test.php::ImageTagsContract.fileReader, resource-engine/tests/image-tags.test.php::ImageTagsContract.numericTags, resource-engine/tests/image-tags.test.php::ImageTagsContract.pngVariants, resource-engine/tests/image-tags.test.php::ImageTagsContract.malformed, resource-engine/tests/image-tags.test.php::ImageTagsContract.sizePolicy [external production callers не найдены]
resource-engine/src/ImageTags.php::ImageTags.readSource <- resource-engine/src/ImageTags.php::ImageTags.read, resource-engine/src/ImageTags.php::ImageTags.readFile
resource-engine/src/ImageTags.php::ImageTags.inspect <- resource-engine/src/ImageTags.php::ImageTags.readSource, resource-engine/src/ImageTags.php::ImageTags.embed
resource-engine/src/ImageTags.php::ImageTags.png <- resource-engine/src/ImageTags.php::ImageTags.inspect [signature dispatch]
resource-engine/src/ImageTags.php::ImageTags.jpeg <- resource-engine/src/ImageTags.php::ImageTags.inspect [signature dispatch]
resource-engine/src/ImageTags.php::ImageTags.webp <- resource-engine/src/ImageTags.php::ImageTags.inspect [signature dispatch]
resource-engine/src/ImageTags.php::ImageTags.pngText <- resource-engine/src/ImageTags.php::ImageTags.png [keyword ownership before text-variant parsing]
resource-engine/src/ImageTags.php::ImageTags.inflate <- resource-engine/src/ImageTags.php::ImageTags.pngText [bounded zTXt/iTXt]
resource-engine/src/ImageTags.php::ImageTags.webpDimensions <- resource-engine/src/ImageTags.php::ImageTags.webp
resource-engine/src/ImageTags.php::ImageTags.webpHeader <- resource-engine/src/ImageTags.php::ImageTags.embed [simple→extended only]
resource-engine/src/ImageTags.php::ImageTags.pngChunk <- resource-engine/src/ImageTags.php::ImageTags.embed
resource-engine/src/ImageTags.php::ImageTags.riffChunk <- resource-engine/src/ImageTags.php::ImageTags.embed, resource-engine/src/ImageTags.php::ImageTags.webpHeader
resource-engine/src/ImageTags.php::ImageTags.number <- resource-engine/src/ImageTags.php::ImageTags.png, resource-engine/src/ImageTags.php::ImageTags.webp, resource-engine/src/ImageTags.php::ImageTags.webpDimensions
resource-engine/src/ImageTags.php::ImageTags.short <- resource-engine/src/ImageTags.php::ImageTags.jpeg
resource-engine/src/ImageTags.php::ImageTags.segment <- resource-engine/src/ImageTags.php::ImageTags.png, resource-engine/src/ImageTags.php::ImageTags.jpeg, resource-engine/src/ImageTags.php::ImageTags.webp [loops and JPEG fill-byte budget]
resource-engine/src/ImageTags.php::ImageTags.metadataSize <- resource-engine/src/ImageTags.php::ImageTags.pngText, resource-engine/src/ImageTags.php::ImageTags.inflate, resource-engine/src/ImageTags.php::ImageTags.jpeg, resource-engine/src/ImageTags.php::ImageTags.webp
resource-engine/src/ImageTags.php::ImageTagSource.__construct <- resource-engine/src/ImageTags.php::ImageTags.read, resource-engine/src/ImageTags.php::ImageTags.readFile, resource-engine/src/ImageTags.php::ImageTags.embed [same instance maximumBytes]
resource-engine/src/ImageTags.php::ImageTagSource.size <- resource-engine/src/ImageTags.php::ImageTags.inspect, resource-engine/src/ImageTags.php::ImageTags.png, resource-engine/src/ImageTags.php::ImageTags.jpeg, resource-engine/src/ImageTags.php::ImageTags.webp
resource-engine/src/ImageTags.php::ImageTagSource.read <- resource-engine/src/ImageTags.php::ImageTags.inspect, resource-engine/src/ImageTags.php::ImageTags.png, resource-engine/src/ImageTags.php::ImageTags.jpeg, resource-engine/src/ImageTags.php::ImageTags.webp, resource-engine/src/ImageTags.php::ImageTags.webpDimensions [headers and owned metadata only for file source]
resource-engine/src/ImageTags.php::ImageTagSource.close <- resource-engine/src/ImageTags.php::ImageTags.readSource, resource-engine/src/ImageTags.php::ImageTagSource.__destruct, resource-engine/src/ImageTags.php::ImageTagSource.__construct [own descriptor/error boundary]
resource-engine/src/ImageTags.php::ImageTagSource.__destruct <- PHP object lifetime callback
```

Live bounded extension inventory: jpeg11/jpg45/png103/webp419 in immediate common originals. Other declared formats will remain rejected for writes until their safe parser/tests exist. No source assets, optimizer, Canvas, browser state or active runtime callers change in this isolated parser stage.

Primary format references checked07.11.2026: [PNG iTXt/ancillary preservation](https://www.w3.org/TR/png-3/), [WebP extended container/unknown chunks](https://developers.google.com/speed/webp/docs/riff_container). Other chunks and their order remain byte-identical. Simple WebP receives a valid VP8X header before adding an unknown chunk; existing VP8X/EXIF/XMP/BZNM are preserved.

## Проверенная первая граница

Actual PHP8.2/GD suite135 assertions: real six-pixel PNG/JPEG/WebP lossy+lossless, exact RGBA/dimensions/MIME and opaque BZN source/mask/EXIF/XMP/private bytes. Numeric-looking tags remain string through normalize→embed→read/replace/clear. Original JPEG APP0/JFIF/Exif/ICC/COM prefix stays in place; MPF cross-image-offset writes explicitly refused. PNG own keyword is recognized across iTXt(compressed/language/translated),tEXt,zTXt; duplicate/malformed/CRC/bomb cases reject instead of hiding an old set. Canonical writer always emits one uncompressed iTXt.

The one shared production container maximum is ImageTagSource::MAXIMUM_BYTES=134217728; instances can only narrow it. read/readFile/embed use the same instance limit. embed preflights old size minus removed own spans plus new metadata plus optional VP8X before assembling large ranges. Actual sizePolicy cases use tiny images at/exceeding narrowed limits, including exact output acceptance/readability and old-span replacement. No128MiB allocation was required.

Test-only seek stream:2097152-byte source/mask chunk skipped;57356 bytes actually read. No production instrumentation, codec image decoding, server assets, catalog migration or HTTP wiring. Four WIP blockers were found and corrected before any consumer was attached: PHP numeric-key coercion, JPEG insert-before-JFIF, compressed own iTXt mistaken for absent, and missing output-size preflight. Independent reviewer scoped repeats and final bounded135-assertion memory suite closed those four in this isolated source/memory boundary. Server/UI/cloud integration, unsupported GIF/SVG/AVIF and runtime publication/native acceptance remain separate.
