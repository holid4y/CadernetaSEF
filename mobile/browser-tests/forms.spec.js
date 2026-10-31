import { test, expect } from '@playwright/test';
import { buildSeed } from '../js/seed.js';

const reference = 'b30acc5c-b024-4eb0-955a-8e76f9c3a610';

async function signIn(page, { viewer = false, deleted = false } = {}) {
    const state = buildSeed();
    const user = state.users.find((user) => user.role === (viewer ? 'viewer' : 'admin'));
    Object.assign(state.appointments[0], {
        status: 'completed',
        integrationId: reference,
        formsEnabled: true,
        formCreateUrl: `https://forms.example.test/new?appointment_id=${reference}`,
        forms: [
            { title: 'Ficha ' + 'do atendimento '.repeat(12), status: deleted ? 'deleted' : 'completed', url: 'https://forms.example.test/one' },
            { title: '<script>example</script>', status: 'pending', url: 'https://untrusted.example.test/one' },
        ],
    });
    await page.route('**/js/config.js', (route) => route.fulfill({
        contentType: 'text/javascript',
        body: 'export const config = Object.freeze({mode:"server",apiUrl:"https://api.example.test/api/v1",formsHosts:["forms.example.test"]});',
    }));
    await page.route('https://api.example.test/api/v1/**', async (route) => {
        const login = new URL(route.request().url()).pathname.endsWith('/login');
        await route.fulfill({ json: login ? { token: 'test-only-session', user } : { state } });
    });
    await page.goto('/');
    await page.locator('#email').fill(user.email);
    await page.locator('#password').fill('test-only-password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();
    await page.evaluate((id) => { location.hash = `/atendimentos/${id}`; }, state.appointments[0].id);
    await expect(page.locator('#form-reference')).toHaveValue(reference);
}

test('completed appointments expose only trusted form links and fit narrow and rotated screens', async ({ page }) => {
    await page.setViewportSize({ width: 280, height: 740 });
    await signIn(page);
    await expect(page.locator('[data-form-action]')).toHaveText('Ver formulário feito');
    await expect(page.locator('[data-form-action]')).toHaveAttribute('href', 'https://forms.example.test/one');
    await expect(page.locator('.card').getByRole('link', { name: 'Preencher formulário', exact: true })).toBeVisible();
    await expect(page.locator('a[href*="untrusted.example.test"]')).toHaveCount(0);
    await expect(page.getByText('<script>example</script>', { exact: true })).toBeVisible();
    for (const viewport of [{ width: 280, height: 740 }, { width: 740, height: 280 }]) {
        await page.setViewportSize(viewport);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
});

test('viewers cannot create forms and deleted forms do not produce external links', async ({ page }) => {
    await signIn(page, { viewer: true, deleted: true });
    await expect(page.getByRole('link', { name: 'Ver formulário feito', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Preencher formulário', exact: true })).toHaveCount(0);
    await expect(page.getByText('Removido na plataforma de formulários', { exact: true })).toBeVisible();
});
