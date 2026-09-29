import { defineConfig, devices } from '@playwright/test';

// End-to-end tests of the main journeys, run in a real browser against a throwaway copy of the platform (e2e/serve.sh).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  // php artisan serve compiles views on first use, so the first visit to a page can be slow.
  timeout: 90_000,
  expect: { timeout: 15_000 },
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8123',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'bash e2e/serve.sh',
    url: 'http://127.0.0.1:8123/up',
    reuseExistingServer: false,
    timeout: 120_000,
  },
});
