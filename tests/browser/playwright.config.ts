import path from 'node:path';

import { defineConfig } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8080';

/**
 * Il JavaScript dell'applicazione non passa dai test PHPUnit: tema, ricerca
 * nelle liste e scorciatoie vivono nel browser. Qui si avvia l'applicazione
 * con `php spark serve` e si guida Chrome come farebbe una persona.
 *
 * In CI il server lo avvia Playwright; in locale, se ne hai già uno su 8080,
 * viene riusato (reuseExistingServer).
 */
export default defineConfig({
    testDir: './tests',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        colorScheme: 'light',
        locale: 'it-IT',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
            command: 'php spark serve --port 8080',
            cwd: path.resolve(__dirname, '../..'),
            url: `${baseURL}/sessions/login`,
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
});
