import { test, expect } from '@playwright/test';
import { buildSeed } from '../js/seed.js';

const API = 'https://api.example.test/api/v1';

async function signIn(page, { viewer = false, connected = false } = {}) {
    const state = buildSeed();
    const user = state.users.find((u) => u.role === (viewer ? 'viewer' : 'admin'));
    const start = new Date();
    start.setHours(14, 0, 0, 0);
    state.google = { available: !viewer, connected, email: connected ? 'ana.google@gmail.com' : null, needsReconnect: false, calendarId: null, calendarName: null, pulledAt: null, webUrl: 'https://calendar.google.com/calendar/r' };
    state.googleEvents = [{ id: 41, title: '<b>Joana</b> - IRPF', location: 'Sala 2', start: start.getTime(), end: start.getTime() + 3_600_000, ownerId: user.id }];
    const sent = [];

    await page.addInitScript(() => {
        window.opened = [];
        window.open = (url) => {
            window.opened.push(url);
            return null;
        };
    });
    await page.route('**/js/config.js', (route) => route.fulfill({
        contentType: 'text/javascript',
        body: `export const config = Object.freeze({mode:"server",apiUrl:"${API}",formsHosts:[]});`,
    }));
    await page.route(`${API}/**`, async (route) => {
        const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
        const body = route.request().postDataJSON?.() ?? null;
        sent.push({ path, body });
        const answers = {
            '/login': { token: 'test-only-session', user },
            '/google/connect': { url: 'https://api.example.test/google/conectar/' + 'a'.repeat(64) },
            '/mobile/commands': { state: { ...state, googleEvents: [] }, result: { ...state.appointments[0], id: 999 } },
        };
        await route.fulfill({ json: answers[path] ?? { state } });
    });

    await page.goto('/');
    await page.locator('#email').fill(user.email);
    await page.locator('#password').fill('test-only-password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();

    return sent;
}

test('an event from Google waits in the agenda and becomes an appointment', async ({ page }) => {
    const sent = await signIn(page);
    await page.evaluate(() => { location.hash = '/agenda'; });

    const row = page.locator('a.row.is-google');
    await expect(row).toContainText('<b>Joana</b> - IRPF');
    await expect(row.locator('b')).toHaveCount(0);
    await row.click();

    await expect(page.getByText('Do Google Agenda', { exact: false }).first()).toBeVisible();
    await page.locator('[data-client]').first().click();
    await page.locator('[data-category="fiscal"]').click();
    await page.locator('[data-service="irpf"]').click();
    await page.locator('#next').click();
    await expect(page.locator('#start')).toHaveValue('14:00');
    await expect(page.locator('#end')).toHaveValue('15:00');
    await page.locator('#when [type="submit"]').click();

    await expect.poll(() => sent.find((request) => request.path === '/mobile/commands')?.body?.args?.[0]?.googleEventId).toBe(41);
});

test('connecting opens Google in the browser with a link from the server', async ({ page }) => {
    await signIn(page);
    await page.locator('#profile').click();
    await page.getByRole('button', { name: /Google Agenda/ }).click();
    await page.locator('[data-google-connect]').click();

    await expect.poll(() => page.evaluate(() => window.opened)).toEqual(['https://api.example.test/google/conectar/' + 'a'.repeat(64)]);
    await expect(page.locator('[data-google-fallback]')).toBeVisible();
});

test('viewers see events from Google but can not open them or connect', async ({ page }) => {
    await signIn(page, { viewer: true });
    await page.locator('#profile').click();
    await expect(page.getByRole('button', { name: /Google Agenda/ })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await page.evaluate(() => { location.hash = '/agenda'; });

    await expect(page.locator('.row.is-google')).toContainText('IRPF');
    await expect(page.locator('a.row.is-google')).toHaveCount(0);
});
