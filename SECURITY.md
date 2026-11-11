# Sicurezza

## Versioni supportate

Il progetto è in sviluppo (serie 0.x): le correzioni di sicurezza escono sulla
versione più recente pubblicata e sul ramo `main`. Le versioni precedenti non
ricevono aggiornamenti mirati.

| Versione | Supportata |
|----------|------------|
| 0.1.x    | Sì         |
| < 0.1    | No         |

## Segnalare una vulnerabilità

Non aprire una issue pubblica: una segnalazione pubblica mette la falla a
disposizione di chiunque prima che ci sia una correzione.

Usa invece il canale privato di GitHub:

<https://github.com/iacos93/invoiceplus/security/advisories/new>

Nella segnalazione servono:

- la versione o il commit in cui il problema si presenta;
- i passi per riprodurlo, con il ruolo e l'azienda dell'utente usato, se conta;
- l'effetto: cosa può fare chi sfrutta la falla che non dovrebbe poter fare;
- eventuale prova di concetto, senza dati di clienti veri.

Risposta attesa entro pochi giorni lavorativi. Se la segnalazione è accettata,
la correzione viene preparata in privato e pubblicata con un avviso di sicurezza
che cita chi l'ha segnalata, se lo desidera.

## Ambito

Rientrano nel perimetro, fra gli altri:

- accesso non autorizzato a dati di un'altra azienda o di un altro utente
  (il progetto è multi-azienda: ogni lettura e scrittura deve rispettare il
  perimetro dell'azienda attiva);
- esposizione di documenti attraverso il portale pubblico o i link con chiave
  URL, comprese le chiavi prevedibili o riutilizzabili;
- esecuzione di codice o inclusione di file tramite upload, template di PDF o
  importazioni;
- injection SQL, XSS memorizzato o riflesso, CSRF su rotte che scrivono;
- esposizione di credenziali, chiavi o token nei log, nelle esportazioni o nei
  file generati.

Non rientrano: configurazioni volutamente aperte (per esempio un ambiente di
prova senza autenticazione), vulnerabilità delle dipendenze già note e senza
correzione disponibile, e problemi che richiedono l'accesso fisico al server.

## Segreti nel repository

`.env` e `writable/` non sono versionati. Le chiavi di cifratura e i dati di
produzione non devono mai finire in un commit, in una fixture di test o in un
allegato di una issue. Se succede, va considerata compromessa e sostituita.
