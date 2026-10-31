import { test, expect } from '@playwright/test';

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
