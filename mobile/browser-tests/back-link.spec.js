import { test, expect } from '@playwright/test';

test('a decoded client route cannot inject markup through the back button', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();

    const id = '"><img id="audit-injected" src="invalid-audit-image" onerror="window.auditInjected=true">';
    await page.evaluate((value) => {
        location.hash = `/atendidos/${encodeURIComponent(value)}/editar`;
    }, id);

    await expect(page.getByRole('heading', { name: 'Atendido', exact: true })).toBeVisible();
    await expect(page.locator('[data-back]')).toHaveAttribute('data-back', `/atendidos/${id}`);
    await expect(page.locator('#audit-injected')).toHaveCount(0);
    expect(await page.evaluate(() => Boolean(window.auditInjected))).toBe(false);
});
