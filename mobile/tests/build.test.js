import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

const run = promisify(execFile);
const build = fileURLToPath(new URL('../scripts/build.mjs', import.meta.url));

test('Pages build stays in demo mode even with server environment variables', async () => {
    const directory = await mkdtemp(`${tmpdir()}/agenda-pages-`);
    try {
        for (const name of ['css', 'fonts', 'icons', 'js', 'android', 'tests']) {
            await mkdir(`${directory}/${name}`);
        }
        for (const name of ['index.html', 'manifest.webmanifest', 'sw.js', '.env']) {
            await writeFile(`${directory}/${name}`, 'fixture');
        }
        await run(process.execPath, [build, '--demo'], {
            cwd: directory,
            env: { ...process.env, MOBILE_MODE: 'server', MOBILE_API_URL: 'https://api.example.test/api/v1', MOBILE_FORMS_ALLOWED_HOSTS: 'forms.example.test' },
        });
        const config = await readFile(`${directory}/dist/js/config.js`, 'utf8');
        assert.match(config, /"mode":"demo","apiUrl":""/);
        assert.match(config, /"formsHosts":\[\]/);
        const files = await readdir(`${directory}/dist`);
        assert.ok(!files.includes('.env'));
        assert.ok(!files.includes('android'));
        assert.ok(!files.includes('tests'));
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('server builds accept plain HTTP only for a server on the same computer', async () => {
    const directory = await mkdtemp(`${tmpdir()}/agenda-server-`);
    try {
        for (const name of ['css', 'fonts', 'icons', 'js']) await mkdir(`${directory}/${name}`);
        for (const name of ['index.html', 'manifest.webmanifest', 'sw.js']) await writeFile(`${directory}/${name}`, 'fixture');
        const build = (url) => run(process.execPath, [fileURLToPath(new URL('../scripts/build.mjs', import.meta.url)), '--server'], {
            cwd: directory,
            env: { ...process.env, MOBILE_API_URL: url },
        });

        await build('http://127.0.0.1:8000/api/v1');
        assert.match(await readFile(`${directory}/dist/js/config.js`, 'utf8'), /"mode":"server","apiUrl":"http:\/\/127\.0\.0\.1:8000\/api\/v1"/);
        await assert.rejects(build('http://agenda.example.org/api/v1'), /require MOBILE_API_URL=https/);
        await assert.rejects(build('https://usuario:senha@agenda.example.org/api/v1'), /without credentials/);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});
