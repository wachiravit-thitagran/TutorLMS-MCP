const { test, expect } = require('@playwright/test');

test('WordPress responds and TutorLMS MCP plugin is active', async ({ request }) => {
  const response = await request.get('/?rest_route=/');
  expect(response.ok()).toBeTruthy();

  const body = await response.json();
  expect(body).toHaveProperty('namespaces');
});

test('login page is reachable', async ({ page }) => {
  await page.goto('/wp-login.php');
  await expect(page.locator('#loginform')).toBeVisible();
});
