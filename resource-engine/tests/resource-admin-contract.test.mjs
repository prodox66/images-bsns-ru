import assert from 'node:assert/strict';
import fs from 'node:fs';

const ADMIN_SOURCE = fs.readFileSync(new URL('../resource-admin.js', import.meta.url), 'utf8');
const CLIENT_SOURCE = fs.readFileSync(new URL('../resource-client.js', import.meta.url), 'utf8');
const CONFIG_SOURCE = fs.readFileSync(new URL('../config.example.php', import.meta.url), 'utf8');
const ADMIN_API_SOURCE = fs.readFileSync(new URL('../src/AdminApi.php', import.meta.url), 'utf8');

// Contract: one mountable controller owns all requested server-gallery operations.
assert.match(ADMIN_SOURCE, /class BZNResourceAdmin/);
assert.match(ADMIN_SOURCE, /async mount\(target\)/);
assert.match(ADMIN_SOURCE, /async open\(\)/);
assert.match(ADMIN_SOURCE, /UNAVAILABLE_RESOURCE_EDITOR/);
assert.match(ADMIN_SOURCE, /async function resolveResourceEditor\(resourceType, options\)/);
assert.match(ADMIN_SOURCE, /COMMON_RESOURCE_COLLECTION\s*=\s*'resources'/);
assert.match(ADMIN_SOURCE, /\[RESOURCE_EDITOR_TYPES\.PICTURES\]:\s*COMMON_RESOURCE_COLLECTION/);
assert.match(ADMIN_SOURCE, /\[RESOURCE_EDITOR_TYPES\.MASKS\]:\s*COMMON_RESOURCE_COLLECTION/);
assert.match(ADMIN_SOURCE, /\[RESOURCE_EDITOR_TYPES\.SHADOWS\]:\s*COMMON_RESOURCE_COLLECTION/);
assert.match(ADMIN_SOURCE, /SHADOWS:\s*'shadows'/);
assert.match(ADMIN_SOURCE, /globalScope\.BZNResourceEditor/);
assert.match(ADMIN_SOURCE, /perform\('upload'/);
assert.match(ADMIN_SOURCE, /perform\('tags'/);
assert.match(ADMIN_SOURCE, /perform\('thumbnails'/);
assert.match(ADMIN_SOURCE, /perform\('optimize_batch'/);
assert.match(ADMIN_SOURCE, /perform\('rebuild'/);

// Contract: failed HTTP contracts identify the action/status while PHP warnings stay inside JSON.
assert.match(ADMIN_SOURCE, /response\.text\(\)/);
assert.match(ADMIN_SOURCE, /requestAction\(url, options\)/);
assert.match(ADMIN_SOURCE, /responsePreview\(source\)/);
assert.match(ADMIN_SOURCE, /diagnostic=/);
assert.match(ADMIN_API_SOURCE, /set_error_handler/);
assert.match(ADMIN_API_SOURCE, /BZN_RESOURCE_ADMIN/);
assert.match(ADMIN_API_SOURCE, /'diagnostic'\s*=>/);

// Contract: gallery cards use only the separately stored thumbnail black box.
assert.match(ADMIN_SOURCE, /image\.src\s*=\s*item\.urls\.thumbnail/);
assert.doesNotMatch(ADMIN_SOURCE, /image\.src\s*=\s*item\.urls\.original/);

// Contract: domain choice belongs to constructor configuration, not resource operations.
assert.doesNotMatch(ADMIN_SOURCE, /https?:\/\//);
assert.doesNotMatch(CLIENT_SOURCE, /https?:\/\//);
assert.match(CONFIG_SOURCE, /'resources'\s*=>/);
assert.doesNotMatch(CONFIG_SOURCE, /'masks'\s*=>/);

console.log('Resource admin contract: OK');
