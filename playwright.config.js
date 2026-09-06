const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/Browser',
    timeout: 900_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['line']],
    use: {
        baseURL: process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8014',
        colorScheme: 'dark',
        reducedMotion: 'reduce',
        screenshot: process.env.BROWSER_SCREENSHOTS === '1' ? 'only-on-failure' : 'off',
        trace: 'retain-on-failure',
        launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
            : {},
    },
});
