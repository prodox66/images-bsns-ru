import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const CLIENT_SOURCE = fs.readFileSync(new URL('../resource-client.js', import.meta.url), 'utf8');
const ENDPOINT = 'https://images.example/resource-engine/api.php';
const RESOURCE_ID = '1234567890abcdef1234567890abcdef';
const calls = [];
const payload = Object.freeze({
    ok: true,
    items: Object.freeze([Object.freeze({
        id: RESOURCE_ID,
        type: 'resources',
        urls: Object.freeze({ thumbnail: `/resource-engine/api.php?type=resources&kind=thumbnail&id=${RESOURCE_ID}` }),
    })]),
});

// Function fixture: one portable client runs outside the resource server origin.
async function fetchFixture(url, options) {
    calls.push(Object.freeze({ url: String(url), options }));
    return Object.freeze({ ok: true, json: async () => structuredClone(payload) });
}

const window = { location: { href: 'https://design.example/editor/' } };
const sandbox = vm.createContext({
    URL,
    window,
    document: { currentScript: { src: 'https://images.example/resource-engine/resource-client.js' } },
    fetch: fetchFixture,
    structuredClone,
});
vm.runInContext(CLIENT_SOURCE, sandbox, { filename: 'resource-client.js' });

const client = new window.BZNResourceClient({ endpoint: ENDPOINT });
const listUrl = new URL(client.url({ type: 'resources', kind: 'list', tags: ['mask', 'brush'], page: 2, pageSize: 30 }));
assert.equal(listUrl.origin + listUrl.pathname, ENDPOINT);
assert.equal(listUrl.searchParams.get('type'), 'resources');
assert.equal(listUrl.searchParams.get('tags'), 'mask,brush');
assert.equal(listUrl.searchParams.get('page'), '2');

const result = await client.list({ type: 'resources' });
assert.equal(calls.length, 1);
assert.equal(result.items[0].urls.thumbnail.startsWith('https://images.example/resource-engine/'), true);
assert.equal(result.items[0].urls.thumbnail.includes(RESOURCE_ID), true);

console.log('Resource client contract: OK');
