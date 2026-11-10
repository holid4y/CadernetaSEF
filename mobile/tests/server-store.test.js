import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, cp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { pathToFileURL } from 'node:url';

test('server store authenticates, accepts authoritative data, rejects failed writes, and expires sessions', async () => {
    const directory = await mkdtemp(`${tmpdir()}/agenda-mobile-`);
    const previous = Object.fromEntries(
        ['fetch', 'location', 'localStorage'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]),
    );
    try {
        await cp(new URL('../js/', import.meta.url), directory, { recursive: true });
        await writeFile(`${directory}/package.json`, '{"type":"module"}');
        await writeFile(`${directory}/config.js`, "export const config = { mode: 'server', apiUrl: 'https://api.example.test/api/v1' };\n");
        Object.defineProperty(globalThis, 'localStorage', {
            configurable: true,
            value: {
                getItem() { assert.fail('Server mode must not read local data'); },
                setItem() { assert.fail('Server mode must not persist platform data'); },
            },
        });
        globalThis.location = { hash: '' };
        const user = { id: 9, role: 'admin', active: true };
        const state = { users: [user], clients: [], appointments: [], services: [], counters: {}, meta: {} };
        const queue = [
            Response.json({ token: 'token', user }),
            Response.json({ state }),
            Response.json({ message: 'Conflito.' }, { status: 422 }),
            Response.json({ state: { ...state, clients: [{ id: 12 }] }, result: { id: 12 } }),
            Response.json({ message: 'Sessão expirada.' }, { status: 401 }),
        ];
        let question = null;
        globalThis.fetch = async (url, options) => {
            if (question) return question(url, options);
            if (queue.length < 5) assert.equal(options.headers.Authorization, 'Bearer token');
            return queue.shift();
        };
        const { store } = await import(pathToFileURL(`${directory}/store.js`));
        assert.equal(store.user, null);
        await store.login('admin@example.test', 'secret');
        assert.equal(store.user.id, 9);
        await assert.rejects(store.action('scheduleAppointment', {}), /Conflito/);
        assert.equal(store.state.appointments.length, 0);
        assert.deepEqual(await store.action('saveClient', {}), { id: 12 });
        assert.equal(store.state.clients[0].id, 12);
        store.switchUser(1);
        assert.equal(store.user.id, 9);
        await assert.rejects(store.action('saveClient', {}));
        assert.equal(store.user, null);
        assert.equal(store.state.clients.length, 0);
        assert.equal(globalThis.location.hash, '/login');

        const asked = [];
        const answers = [Response.json({ awaiting_password: true }), Response.json({ awaiting_password: false }), Response.json({ message: 'Muitas tentativas.' }, { status: 429 })];
        question = async (url, options) => {
            asked.push([url, options.method, options.body]);
            if (!answers.length) throw new TypeError('offline');
            return answers.shift();
        };
        assert.equal(await store.awaitingPassword('maria@example.test'), true);
        assert.deepEqual(asked[0], ['https://api.example.test/api/v1/account-status', 'POST', '{"email":"maria@example.test"}']);
        assert.equal(await store.awaitingPassword('joana@example.test'), false);
        assert.equal(await store.awaitingPassword('maria@example.test'), false, 'a refused question shows no message');
        assert.equal(await store.awaitingPassword('maria@example.test'), false, 'no signal shows no message');
    } finally {
        for (const [key, descriptor] of Object.entries(previous)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
        await rm(directory, { recursive: true, force: true });
    }
});
