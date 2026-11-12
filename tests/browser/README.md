# Test nel browser

I test PHPUnit non eseguono il JavaScript: tema chiaro/scuro, ricerca nelle
liste e scorciatoie vivono nel browser. Qui Playwright guida Chrome come
farebbe una persona, sull'applicazione avviata da `php spark serve`.

## Cosa coprono

- accesso con email e password, fino alla Bacheca;
- cambio tema dal menu utente, con la scelta che sopravvive al ricaricamento;
- ricerca in elenco (Anagrafiche) che sostituisce i risultati senza cambiare
  pagina;
- `Ctrl+K` / `Cmd+K` che porta il focus nella ricerca globale.

## In locale

Servono un database di prova con l'utente `admin@example.com` e la password
`e2e-password` (oppure `E2E_EMAIL` / `E2E_PASSWORD`), e Redis in ascolto:
sessioni e cache passano da lì, quindi senza Redis il login non conserva la
sessione. Il server parte da solo su `http://localhost:8080`; se ne hai già uno
su quella porta viene riusato.

```sh
cd tests/browser
npm install
npx playwright install chromium
npx playwright test
```

Il primo `npx playwright install` scarica il browser (circa 150 MB).

## In CI

Il job `browser` del workflow prepara PostgreSQL e Redis, applica le migrazioni
e il seed, imposta la password dell'utente di prova e lancia gli stessi test.
Playwright avvia il server, quindi non serve una macchina già in ascolto.
