# Funzioni

Guida alle funzioni dell'applicazione, divisa come la sidebar: **Lavoro**,
**Analisi** e **Gestione**. Non sostituisce il README (installazione e
requisiti) né `tests/README.md` (come girare i test): serve a rispondere a
"questa schermata cosa fa e cosa devo sapere prima di usarla".

**Stato: in sviluppo.** Le funzioni descritte esistono e sono coperte da test,
ma il progetto non è ancora dichiarato stabile per l'uso in produzione su dati
reali senza backup.

## Come è organizzata

- **Topbar**: ricerca globale (fatture, preventivi, clienti, prodotti),
  tema chiaro/scuro, menu utente.
- **Contesto** in cima alla sidebar: l'**azienda attiva** (solo se ce n'è più
  di una) e l'**anno di lavoro** (oppure "Tutti gli anni"). La scelta dell'anno
  vale per bacheca, fatture, preventivi, scadenzario, riconciliazione, F24,
  utile e imposte: non è un filtro della singola pagina, è il periodo in cui
  stai lavorando.
- **Sidebar**: le tre sezioni del menu. La freccia accanto a una voce apre le
  sottovoci (crea nuovo, ricorrenti, archivio, ...), mentre il nome porta alla
  pagina principale.
- **Multi-azienda**: i dati sono separati per azienda; il selettore nel
  contesto cambia l'azienda attiva e con essa i documenti visibili. L'anno
  scelto resta anche dopo il logout (viene ricordato in un cookie); l'azienda
  attiva invece vive nella sessione e a un nuovo accesso riparte dalla
  predefinita.

## Le aree

| Area | Cosa copre | Pagina |
|---|---|---|
| Anagrafiche | Clienti, fornitori, prodotti, attività e progetti, campi personalizzati | [anagrafiche.md](funzioni/anagrafiche.md) |
| Preventivi | Stati, creazione, invio, approvazione del cliente, conversione in fattura | [preventivi.md](funzioni/preventivi.md) |
| Fatture e DDT | Fatture, ricorrenti, note di credito, archivio, PDF/XML, invio, DDT | [fatture-e-ddt.md](funzioni/fatture-e-ddt.md) |
| Incassi e spese | Pagamenti, spese, import XML, riconciliazione bancaria, conti di saldo | [incassi-e-spese.md](funzioni/incassi-e-spese.md) |
| Fisco e analisi | F24, RiBa, scadenzario, calendario, utile e imposte, controllo di gestione, report | [fisco-e-analisi.md](funzioni/fisco-e-analisi.md) |
| Accesso e integrazioni | Utenti, area guest, MFA e passkey, endpoint MCP, automazioni, email | [accesso-e-integrazioni.md](funzioni/accesso-e-integrazioni.md) |
| Impostazioni | Aziende, numerazione, aliquote, parametri fiscali, import/export | [impostazioni.md](funzioni/impostazioni.md) |

## Da dove iniziare

L'ordine in cui le schermate si aspettano di essere configurate:

1. **Azienda** (Impostazioni > Aziende): ragione sociale, P.IVA/CF, indirizzo,
   regime fiscale, gruppi di numerazione predefiniti.
2. **Tabelle di base** (Impostazioni): aliquote IVA, unità di misura, famiglie
   prodotto, metodi di pagamento, categorie di spesa.
3. **Parametri fiscali** dell'anno, se usi Utile e imposte e Controllo di
   gestione.
4. **Conti di saldo** (banca/cassa), usati da riconciliazione e RiBa.
5. **Email e mittenti**: casella di sistema e template, per invii, solleciti,
   notifiche SdI e reset password; da qui si attiva anche il tracciamento delle
   aperture.
6. **Utenti e sicurezza**: gli account dell'area amministrativa e, se serve,
   gli account cliente per l'area guest.

Chi installa da zero trova il primo amministratore con `php spark users:create`
(vedi il README); la pagina `sessions/setup` risponde solo quando il database
non ha ancora nessun utente.

## Documentazione collegata

- [README](../README.md): requisiti, installazione, test, licenza.
- [Test](../tests/README.md): suite, database di prova e workflow di CI.
- [Schermate](../README.md#schermate): immagini di bacheca e documenti.
