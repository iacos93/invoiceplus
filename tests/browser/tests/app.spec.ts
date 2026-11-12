import { expect, test, type Page } from '@playwright/test';

/**
 * Un giro vero nel browser sulle cose che PHPUnit non tocca.
 *
 * Le credenziali arrivano dall'ambiente: in CI il workflow imposta la
 * password dell'utente di prova dopo il seed. In locale, senza variabili, si
 * usano i valori indicati qui sotto (vanno creati su un database di prova).
 */
const EMAIL = process.env.E2E_EMAIL ?? 'admin@example.com';
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password';

async function login(page: Page): Promise<void> {
    await page.goto('/sessions/login');
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('login e cambio tema persistente', async ({ page }) => {
    await login(page);

    const html = page.locator('html');
    await expect(html).toHaveAttribute('data-theme', 'light');

    // Il selettore sta nel menu utente, come lo trova una persona.
    await page.locator('.ip-user-button').click();
    await page.locator('#ip-theme-toggle').click();
    await expect(html).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => localStorage.getItem('ip-theme'))).toBe('dark');

    // La scelta deve sopravvivere al ricaricamento: theme_head.php la applica
    // prima del foglio di stile, quindi non deve lampeggiare in chiaro.
    await page.reload();
    await expect(html).toHaveAttribute('data-theme', 'dark');

    await page.locator('.ip-user-button').click();
    await page.locator('#ip-theme-toggle').click();
    await expect(html).toHaveAttribute('data-theme', 'light');
});

test('la ricerca in elenco aggiorna i risultati senza ricaricare la pagina', async ({ page }) => {
    await login(page);
    await page.goto('/clients');

    // Il test non conosce i nomi: in CI arrivano dal seed, in locale dalla
    // copia del database di lavoro. Conta il comportamento, non il dato.
    const righe = page.locator('#clients-table-wrap tbody tr:not(.empty-row)');
    await expect(righe.first()).toBeVisible();
    const iniziali = await righe.count();
    const urlPrima = page.url();

    const campo = page.locator('.ip-live-search input[name="filter_query"]');
    await campo.fill('zzz-nessun-cliente');

    // Il filtro passa dal server (data-action) e sostituisce solo il blocco
    // della tabella: una ricerca senza risultati mostra la riga vuota, mentre
    // l'URL e la barra laterale restano dove sono.
    await expect(page.locator('#clients-table-wrap .empty-row')).toBeVisible();
    await expect(page).toHaveURL(urlPrima);

    await campo.fill('');
    await expect(righe).toHaveCount(iniziali);
});

test('Ctrl+K porta il focus nella ricerca globale', async ({ page }) => {
    await login(page);

    await page.keyboard.press(process.platform === 'darwin' ? 'Meta+k' : 'Control+k');

    await expect(page.locator('#ip-global-search-input')).toBeFocused();
});

test("l'interruttore del tracciamento email si salva davvero", async ({ page }) => {
    await login(page);
    await page.goto('/email_senders');

    // La casella è dentro il form delle impostazioni, non in una modale: il
    // test la usa come farebbe una persona e ricarica per leggere il valore
    // dal database, non dalla pagina appena inviata.
    const casella = page.locator('input[name="settings[email_tracking_enabled]"][type="checkbox"]');
    await expect(casella).toBeVisible();
    await expect(casella).toBeChecked();

    const salva = page.locator('.ip-email-config-form button[type="submit"]');

    await casella.uncheck();
    await salva.click();
    // L'avviso è la prova che il POST è arrivato e il redirect è stato
    // servito: ricaricare subito, con la navigazione ancora in corso, chiudeva
    // la pagina sotto Playwright ("Not attached to an active page").
    await expect(page.locator('.ip-toast--success')).toContainText('Configurazione email salvata');
    await page.reload();
    await expect(casella).not.toBeChecked();

    // Rimettere tutto com'era: le impostazioni sono globali e gli altri test
    // non devono ereditare un tracciamento spento.
    await casella.check();
    await salva.click();
    await expect(page.locator('.ip-toast--success')).toContainText('Configurazione email salvata');
    await page.reload();
    await expect(casella).toBeChecked();
});

test('la scheda Email inviate compare nella pagina del documento', async ({ page }) => {
    await login(page);
    await page.goto('/invoices/status/all?year=0');

    const primoNumero = page.locator('td[data-cell="number"] a').first();
    await expect(primoNumero).toBeVisible();
    await primoNumero.click();

    const scheda = page.locator('#ip-email-tracks');
    await expect(scheda).toBeVisible();
    await expect(scheda.locator('.ip-preview__empty, .ip-preview__payments--tracks')).toBeVisible();
});
