import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, cp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { pathToFileURL } from 'node:url';

test('server commands carry what the device saw, so the server can refuse stale changes', async () => {
    const directory = await mkdtemp(`${tmpdir()}/agenda-commands-`);
    const previous = Object.fromEntries(
        ['fetch', 'location', 'localStorage'].map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]),
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
        const state = {
            users: [user],
            clients: [{ id: 3, name: 'Joana', updatedAt: 1_700_000_000_000 }],
            services: [{ slug: 'irpf', documents: ['CPF', 'Recibo'] }],
            appointments: [{ id: 5, status: 'confirmed', updatedAt: 1_700_000_100_000, documents: [{ id: 7, name: 'CPF', receivedAt: null }] }],
            counters: {},
            meta: {},
        };
        const sent = [];
        globalThis.fetch = async (url, options) => {
            if (url.endsWith('/login')) return Response.json({ token: 'token', user });
            if (url.endsWith('/mobile/commands')) sent.push(JSON.parse(options.body));
            return Response.json({ state, result: null });
        };

        const { store } = await import(pathToFileURL(`${directory}/store.js`));
        await store.login('admin@example.test', 'secret');
        assert.ok(store.loadedAt > 0);

        await store.action('changeStatus', 5, 'cancelled');
        await store.action('rescheduleAppointment', 5, { start: 1, end: 2, responsibleId: 9 });
        await store.action('saveClient', { name: 'Joana Lima' }, 3);
        await store.action('saveClient', { name: 'Nova' }, null);
        await store.action('toggleDocument', 5, 7);
        await store.action('removeServiceDocument', 'irpf', 1);
        await store.action('restoreAppointment', { id: 5, status: 'scheduled', notes: 'não precisa sair do aparelho' });

        assert.deepEqual(sent.map((command) => command.args), [
            [5, 'cancelled', 'confirmed'],
            [5, { start: 1, end: 2, responsibleId: 9, updatedAt: 1_700_000_100_000 }],
            [{ name: 'Joana Lima', updatedAt: 1_700_000_000_000 }, 3],
            [{ name: 'Nova', updatedAt: null }, null],
            [5, 7, true],
            ['irpf', 1, 'Recibo'],
            [{ id: 5, status: 'scheduled' }],
        ]);
    } finally {
        for (const [key, descriptor] of Object.entries(previous)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
        await rm(directory, { recursive: true, force: true });
    }
});
