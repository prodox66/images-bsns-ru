# 36.14.2 — ADD для точной выбранной версии оригинала

До правки: `ee289743525c4dfe4f20ee995672114d217d83a6`, main/GitHub/live clean. Открытый список хранит id, вычисленный из `(type, name)`. Новый оригинал под тем же именем получает такой же id, но другую ревизию. Старый ADD намеренно перечитывает текущие теги без binding; выбранный массовый режим требует отдельного `add_selected` с 64-hex ревизией. Нет изменений в прежних HTTP actions, каталоге чтения и генерации превью.

```text
resource-engine/src/ResourceEngine.php::requestTagUpdate <- ResourceEngine.updateTags, ResourceEngine.updateTagsWithPublicAliases [host/API]
resource-engine/src/ResourceEngine.php::changeTags <- ResourceEngine.requestTagUpdate, ResourceEngine.setTags [legacy replace]
resource-engine/src/ResourceEngine.php::writeTagUpdate <- ResourceEngine.changeTags [selected collection lock; same physical ID resolution]
resource-engine/src/ImageTagStore.php::ImageTagStore.usesExpectedRevision <- ImageTagStore.prepare, ResourceEngine.requestTagUpdate/writeTagUpdate
resource-engine/src/ImageTagStore.php::ImageTagStore.prepare <- ResourceEngine.writeTagUpdate/prepareReplacementTags, library-api-bsns-ru/src/PersonalImageTagManifest.php::writeSelected [existing owner lock]
resource-engine/src/ImageTagStore.php::ImageTagTransaction.stage <- ImageTagStore.prepare [exact source bytes/old revision], ResourceEngine.publishTagUpdate [metadata/catalog]
```

После правки: Engine проверяет выбранную ревизию сразу под lock. Store проверяет её ещё раз перед чтением/подготовкой точного исходника. `add_selected` использует тот же свежий union, а не REPLACE. Замена под прежним именем и даже тем же mtime возвращает 409 без записи, как и любое устаревание выбранной ревизии. Legacy ADD с предыдущей ревизией сохраняет прежний union. Защита не делает утверждений об управляемом удалённом хранилище или неотслеживаемой побайтовой записи в тот же inode с теми же stat.

Candidate: actual PHP8.2/GD isolated PublicAliasTagContract 35 и ImageTagStore 39 PASS, прежние aliases/rollback и новый same-name different-pixel probe, PHP lint/diff clean. Граница предвыкладки: три завершённых browser-цикла старого live и новый код проверены отдельно. WordPress и VPS API подключают операцию отдельным шагом; новый браузерный bulk пока удержан.

36.14.2.1: для `add_selected` проверяется фактический результат `ImageTags.normalize` до любого stage. `['   ']` отвергнут с прежними bytes/revision/catalog; legacy ADD и REPLACE `[]` неизменны. Actual hosted contract 36 PASS и независимый VFS guard. Перед публикацией отдельные три чистых цикла на ранее опубликованном `adf24048`, не перепутаны с postpub.
