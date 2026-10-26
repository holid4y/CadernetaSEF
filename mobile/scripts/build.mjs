import { cp, mkdir, rm, writeFile } from 'node:fs/promises';

const flag = ['--demo', '--server'].find((option) => process.argv.includes(option));
const mode = flag ? flag.slice(2) : process.env.MOBILE_MODE ?? 'demo';
const apiUrl = mode === 'demo' ? '' : (process.env.MOBILE_API_URL ?? '').replace(/\/$/, '');
const formsHosts = mode === 'demo' ? [] : (process.env.MOBILE_FORMS_ALLOWED_HOSTS ?? '').split(',').map((host) => host.trim().toLowerCase()).filter(Boolean);
if (formsHosts.some((host) => !/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(host))) {
    throw new Error('MOBILE_FORMS_ALLOWED_HOSTS must contain domain names without paths or wildcards');
}
if (!['demo', 'server'].includes(mode)) throw new Error('MOBILE_MODE must be demo or server');
// Plain HTTP only reaches a server on this same computer, for development.
const local = /^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(apiUrl);
if (mode === 'server' && ((!apiUrl.startsWith('https://') && !local) || new URL(apiUrl).username || new URL(apiUrl).password)) {
    throw new Error('Server builds require MOBILE_API_URL=https://your-server/api/v1 (without credentials)');
}

await rm('dist', { recursive: true, force: true });
await mkdir('dist');
for (const file of ['index.html', 'manifest.webmanifest', 'sw.js', 'css', 'fonts', 'icons', 'js']) {
    await cp(file, `dist/${file}`, { recursive: true });
}

await writeFile('dist/js/config.js', `export const config = Object.freeze(${JSON.stringify({ mode, apiUrl, formsHosts })});\n`);
console.log(`Built mobile app (${mode})`);
