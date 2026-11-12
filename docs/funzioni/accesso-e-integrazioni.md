# Accesso e integrazioni

Chi entra, con quali permessi, e i canali con cui l'applicazione parla con
l'esterno.

## Utenti e ruoli

Due tipi di account:

- **amministratore** (`user_type` 1): vede tutta l'area amministrativa
  dell'azienda attiva;
- **utente cliente** (`user_type` 2): entra nell'**area guest** e vede solo i
  documenti dei clienti a cui è collegato.

Gli utenti si gestiscono da **Impostazioni > Utenti**: creazione, modifica,
cambio password, collegamento ai clienti, eliminazione. Un amministratore può
anche **azzerare l'MFA** di un utente che ha perso il dispositivo.

Il primo amministratore si crea da riga di comando:

```console
php spark users:create
```

La pagina `sessions/setup` fa la stessa cosa dal browser, ma risponde solo
quando nel database non esiste ancora nessun utente. Il recupero password
passa dalla casella email di sistema: senza quella configurata, il link non
parte.

## Area guest e link pubblici

Due modi per far vedere i documenti a un cliente:

- **account guest**: l'utente è collegato a uno o più clienti e vede solo
  fatture, preventivi e pagamenti che li riguardano;
- **link pubblico**: fatture e preventivi hanno una chiave nell'URL
  (`guest/view/invoice/...`, `guest/view/quote/...`) che non richiede login.
  Il link può essere protetto da password, impostata sul documento; senza
  password chiunque abbia l'URL vede il documento.

Dal link pubblico il cliente scarica il PDF, e per i preventivi può approvare
o rifiutare. Aprire il documento lo segna come "visto" (se era ancora
"inviato").

## Sicurezza dell'account

**Impostazioni > Sicurezza account** (per l'utente collegato):

- **MFA TOTP**: si attiva con un'app di autenticazione, si conferma con un
  codice e produce i **codici di backup** da conservare. I codici si vedono una
  volta sola.
- **Passkey (WebAuthn)**: registrazione di una o più chiavi (Touch ID, Windows
  Hello, chiave hardware), utilizzabili per l'accesso e come secondo fattore.
  Ogni passkey si può eliminare singolarmente.

L'accesso con password resta sempre disponibile; MFA e passkey si aggiungono.

## Integrazione MCP

L'endpoint `/mcp` permette a un assistente AI di leggere e scrivere i dati
tramite il protocollo MCP (Streamable HTTP, autenticazione con bearer token).

- La chiave si genera da **Integrazione MCP**; è salvata cifrata e si può
  rigenerare. **Rigenerarla invalida subito i client già configurati.**
- L'integrazione lavora su **una sola azienda**, scelta nella stessa pagina
  (oppure sull'azienda predefinita). Non è un accesso "a tutto".
- Gli strumenti coprono anagrafiche, fatture, preventivi, pagamenti, riepiloghi
  e gli elenchi di supporto (aliquote, gruppi di numerazione, metodi di
  pagamento, voci di costo). Le operazioni di scrittura cambiano davvero i
  dati: la chiave va trattata come una password.

## Automazioni

Gli endpoint di manutenzione sono pubblici ma protetti da una **cron key**
(Impostazioni > Generali):

| Endpoint | Cosa fa |
|---|---|
| `GET /cron/recur/<key>` | genera le fatture ricorrenti scadute |
| `GET /cron/sdi_poll/<key>` | importa le ricevute SdI dalla casella PEC |
| `GET /cron/digest/<key>` | email settimanale con le scadenze |
| `GET /cron/backup/<key>` | dump del database in `writable/backups` |

Il runner lancia anche due comandi CLI: `php spark backup:verify` prova il
ripristino dell'ultimo dump e `php spark email:prune` elimina le righe di
tracciamento email più vecchie di un anno o di documenti eliminati.

`tools/cron-runner.sh` è il modo consigliato di schedularli: una sola riga di
crontab, con timeout per job, lock contro le sovrapposizioni e log ruotato. In
alternativa `crontab.example` elenca una voce per endpoint.

I cron girano **senza sessione**, quindi senza "azienda attiva": lavorano su
tutte le aziende. Il backup richiede `pg_dump` o `mysqldump` e `exec()`; i
dump sono dati fiscali in chiaro e non vanno lasciati sotto la document root.

## Email e coda di lavoro

- **Email e mittenti**: casella di sistema (notifiche, reset password, SdI di
  ripiego) e casella PEC per il canale SdI, scelte per azienda.
- **Tracciamento aperture**: le email di fatture e preventivi portano un pixel
  invisibile e la pagina del documento mostra gli invii con data, destinatario
  e prima apertura; nelle liste fatture e preventivi una nota sotto il numero
  dice se l'ultimo invio è stato aperto. Il dato è indicativo — chi blocca le
  immagini non carica il pixel, e alcuni client le scaricano in anticipo — e si
  spegne da **Email e mittenti**. L'IP è salvato in forma anonima, lo user
  agent non viene memorizzato; il cron settimanale elimina le righe più vecchie
  di un anno e quelle di documenti eliminati (`php spark email:prune`).
- **Template email**: testi e segnaposto per invio documenti, solleciti e
  notifiche. Si modificano da Impostazioni senza toccare il codice.
- **Coda**: con Redis attivo gli invii possono passare da una coda
  (`default`, `fattura`, `email`, `sdi`), gestita da un worker:

  ```console
  php spark queue:work          # coda default
  php spark queue:work email    # una coda specifica
  php spark queue:status        # job in attesa e falliti
  ```

## Da sapere

- Un utente cliente non è un amministratore con meno voci di menu: è un
  perimetro diverso, con le proprie pagine.
- La cron key e la chiave MCP non vanno nel repository né in un file
  servibile dal web; rigenerarle è la via per revocare un accesso.
- Se Redis non è raggiungibile il job viene eseguito subito, in modo sincrono;
  se Redis c'è ma il worker non gira, i job restano in coda. Il worker va
  tenuto in esecuzione (systemd, supervisor o equivalente).
