const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  retries: process.env.CI ? 2 : 0,
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://127.0.0.1:8888',
    trace: 'retain-on-failure'
  },
  reporter: process.env.CI ? [['html', { open: 'never' }], ['list']] : 'list'
});
