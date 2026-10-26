import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

async function login(page) {
    await page.goto('/');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();
}

async function navigate(page, path) {
    await page.evaluate((path) => {
        location.hash = path;
    }, path);
    await expect.poll(() => page.evaluate(() => location.hash)).toBe(`#${path}`);
}

test('exports an actual calendar file and generates a Google Calendar link', async ({ page }) => {
    await login(page);
    await navigate(page, '/atendimentos/1');
    await expect(page.getByRole('link', { name: 'Google Agenda', exact: true })).toHaveAttribute(
        'href',
        /^https:\/\/calendar.google.com\/calendar\/render\?/,
    );
    const download = page.waitForEvent('download');
    await page.locator('#export-calendar').click();
    const result = await download;
    expect(await readFile(await result.path(), 'utf8')).toContain('BEGIN:VCALENDAR');
});

test('invalid client contacts show guidance and never produce a WhatsApp or email link', async ({ page }) => {
    await login(page);
    await page.evaluate(async () => {
        const { store } = await import('./js/store.js');
        store.commit((state) => {
            state.clients[0].email = 'bad?bcc=evil';
            state.clients[0].phone = '1234';
        });
    });
    await navigate(page, '/atendidos/1');
    await expect(page.locator('a[href^="mailto:"]')).toHaveCount(0);
    await expect(page.locator('a[href^="https://wa.me/"]')).toHaveCount(0);
    await expect(page.getByText('Cadastre um e-mail válido para escrever ao atendido.')).toBeVisible();
});

test('native calendar export writes a cache file and opens the system share sheet', async ({ page }) => {
    await page.addInitScript(() => {
        window.exportCalls = [];
        window.Capacitor = {
            isNativePlatform: () => true,
            Plugins: {
                Filesystem: {
                    writeFile: async (options) => {
                        window.exportCalls.push(options);
                        return { uri: 'file:///cache/atendimento.ics' };
                    },
                },
                Share: { share: async (options) => { window.exportCalls.push(options); } },
            },
        };
    });
    await login(page);
    await navigate(page, '/atendimentos/1');
    await page.locator('#export-calendar').click();
    await expect.poll(() => page.evaluate(() => window.exportCalls.length)).toBe(2);
    const calls = await page.evaluate(() => window.exportCalls);
    expect(calls[0].directory).toBe('CACHE');
    expect(calls[0].data).toMatch(/^BEGIN:VCALENDAR/);
    expect(calls[1].files).toEqual(['file:///cache/atendimento.ics']);
});
