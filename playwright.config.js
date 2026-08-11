// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,
  // Auth endpoints share an IP-based rate limit (5 logins/60s, lockout after
  // 10 failed/15min) - run serially so the suite doesn't lock itself out.
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    // Trailing slash matters: combined with relative (no leading "/") paths in
    // tests, this resolves "main/website/x.php" -> ".../menuwebsite/main/website/x.php".
    // A leading "/" in a test path would instead resolve from the origin root
    // and silently drop the /menuwebsite prefix.
    baseURL: 'http://localhost/menuwebsite/',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
