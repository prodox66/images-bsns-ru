# 36.15.1 — атомарное архивирование выбранных общих изображений

До правки: main `e1fef19bb531aece3288105c272bb97235a598f6`, local/GitHub/live clean. Существующий `ResourceEngine.delete` вызывается из AdminApi и WordPress для одной картинки и после rebuild удаляет временный файл. Новый вызов отделён от него: `id` вычисляется из имени, поэтому каждый выбранный оригинал должен пройти strict revision до переноса. Проверены actual host paths: runtime и recovery у всех 4 типов вне `www`, на одном устройстве с originals, без symlink. Графы aliases/selected ADD проверены перед изменением.

```text
resource-engine/src/ResourceEngine.php::archiveSelectedWithPublicAliases <- new protected host caller [later WP gate], CLI fixture
resource-engine/src/ResourceEngine.php::archiveSelection <- archiveSelectedWithPublicAliases [bounded typed id+revision list]
resource-engine/src/ResourceEngine.php::currentAliasTagRecord <- changeTags [host tag], archiveSelectedWithPublicAliases [same collection lock]
resource-engine/src/ResourceEngine.php::planArchiveVariants <- archiveSelectedWithPublicAliases
resource-engine/src/ResourceEngine.php::publicAliasProof <- writeTagUpdate, planArchiveVariants [exact runtime/public binding]
resource-engine/src/ResourceSelectedArchive.php::expect <- archiveSelectedWithPublicAliases [source revision], planArchiveVariants [runtime/public]
resource-engine/src/ResourceSelectedArchive.php::expectMissing <- planArchiveVariants [no absent alias promoted]
resource-engine/src/ResourceSelectedArchive.php::move <- archiveSelectedWithPublicAliases [all candidates already proven]
resource-engine/src/ResourceSelectedArchive.php::writeJournal <- move [exclusive restore.json before moves]
resource-engine/src/ResourceSelectedArchive.php::moveFile <- move [same-device inode]
resource-engine/src/ResourceSelectedArchive.php::rollback <- archiveSelectedWithPublicAliases [failed moves or publish]
resource-engine/src/ResourceEngine.php::publishTagUpdate <- writeTagUpdate, archiveSelectedWithPublicAliases [single catalog/metadata transaction]
```

После правки: максимум 30 в одном lock; любая устаревшая/отсутствующая версия до moves останавливает группу. Только свой оригинал и exact runtime/public counterparts, no optimizer or FTP ingest. Recovery is outside web root for hosted settings, unique private folder, original bytes/hardlink topology, selected legacy metadata; ответ только id/name. После staged catalog failure возвращаются только ещё вакантные пути через no-clobber link; новый файл не заменяется, оставшийся recovery сохраняется. Direct existing delete path unchanged.

Actual isolated PHP8.2/GD archive10, hosted tag36 PASS. Replacement same-name/same-mtime stale409 не переносит ни одного файла, corrupt catalog link восстанавливает оба исходника/aliases/metadata и сохраняет target. Успех проверяет каждую сохранённую копию и соседа. No live asset writes; native prepub and postpub health separately. WP/UI and VPS are separate checkpoints.

36.15.1.1: `planArchiveVariants` передаёт detached-copy digest из существующего `publicAliasProof`. `ResourceSelectedArchive.matchesDigest` использует `ImageTagStore.unchangedMatches` при `expect` и `moveFile`. `archiveProofs` передаёт digest уже перемещённых копий в последний `publishTagUpdate.verifyUnchangedTagTargets`. Hardlink-семейства сохраняют старый inode/stat fast path. Actual archive12: early same-stat drift отказывает до переносов, late drift после move/в начале catalog install возвращает 409 и сохраняет новые байты копии на прежнем свободном месте, оба каталога и metadata точны. Independent actual VFS3 подтверждает оба барьера и exact normal success. На 20393bdd метод опубликован без WP/UI caller; первичная ошибка не открывала новую live destructive surface.

36.15.1.2: `rollback -> restoreFamily -> restoreLink/ownsLiveLink` возвращает исходник первым, держит recovery links до проверки семейства. Если прежний путь занял другой PNG, старый source/variants/aliases остаются приватными; другие selected families восстанавливаются отдельно. `archiveSelectedWithPublicAliases -> retireArchiveReplacementMetadata -> publishTagUpdate` убирает только старую sidecar запись подтверждённой новой версии после проверки exact prior sidecar и текущей ревизии. Actual physical archive12+9 и legacy tag36; direct original/resource дают новый PNG, public/runtime old отсутствуют, старое семейство exact в recovery. Independent VFS дополнительно race после первого restoreLink; no real live writes or WP caller.

Permanent catalog failure: retirement commits the exact confirmed legacy entry separately under the same lock. Derived JSON/JS repair starts with that clean sidecar as its own baseline. A repeat failure leaves the request failed and the private derived pair needing normal rebuild, but cannot reattach archived tags. Actual `SharedGallery.scan` uses physical paths and sidecar; actual Engine.list refreshes per-record tag fields from the current source/sidecar, and resource checks actual variants. No installed classic catalog.js browser consumer found. Final physical archive12+10+10 PASS, including held catalog.js failure and actual list/new bytes. Prepub remained on the same exact live pair while this new failure branch was tested separately.
