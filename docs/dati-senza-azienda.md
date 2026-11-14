# Dati senza azienda (righe orfane)

Da quando l'applicazione gestisce più aziende, ogni tabella per-azienda ha una
colonna `company_id`. I dati creati prima di quella separazione non ce l'hanno:
sono rimasti con `company_id NULL`. In questo documento sono chiamati **righe
orfane**.

Una riga orfana non è un errore di lettura: è un dato storico a cui manca
l'azienda. Il problema è che, finché resta NULL, appartiene a tutte.

## Perché sono visibili a tutte le aziende (comportamento storico)

Nella prima versione del multi-azienda il filtro includeva di proposito anche i
NULL:

- `company_scope_sql()` in `app/Helpers/ip_helpers.php` generava
  `AND (tabella.company_id = N OR tabella.company_id IS NULL)`;
- `IpBaseModel::scopeToCompany()` faceva lo stesso con il query builder, e
  `findScoped()` lo usa per aprire i record per id;
- i report e le query SQL scritte a mano ripetevano la condizione.

La scelta era deliberata: quando il multi-azienda è stato introdotto, togliere i
NULL dalle query avrebbe fatto sparire fatture, clienti e spese dalle liste.
Molti documenti di un'installazione che aggiornava non avevano ancora un'azienda
e sarebbero diventati invisibili, senza un errore che spiegasse perché.

Il rovescio della medaglia era che **una riga orfana compariva in ogni azienda**:

- una fattura senza azienda entrava nel fatturato sia della società operativa
  sia della holding;
- un cliente senza azienda compariva nella tendina di tutte e si poteva
  fatturare per sbaglio;
- un conto bancario o un fornitore orfano era selezionabile da chiunque.

Non è una fuga di dati fra utenti (i permessi restano quelli dell'account), ma
è una rottura dell'isolamento fra soggetti giuridici. Per questo va misurata e
sistemata, non ignorata.

## Quali NULL sono un problema e quali no

Non tutti i `company_id NULL` sono residui. Ci sono due gruppi, elencati in
`App\Services\CompanyOrphanReport`:

### Tabelle per-azienda (`LEGACY_TABLES`)

Un NULL qui è un dato da assegnare:

| Tabella | Perché è per-azienda |
| --- | --- |
| `ip_invoices`, `ip_quotes`, `ip_delivery_notes` | documenti emessi da un soggetto preciso |
| `ip_clients`, `ip_products`, `ip_suppliers` | anagrafiche che appartengono all'azienda che le usa |
| `ip_expenses`, `ip_f24` | costi e versamenti di una sola azienda |
| `ip_bank_accounts`, `ip_bank_transactions` | conti e movimenti intestati a un'azienda |
| `ip_email_senders` | caselle SMTP usate da una specifica azienda |
| `ip_invoice_groups` | serie di numerazione: dopo `EnsureCompanyGroups` ognuna ha le sue |
| `ip_imports` | storico delle importazioni fatte in una certa azienda |
| `ip_costing_accounts`, `ip_costing_budgets` | piano dei conti e budget del controllo di gestione |

L'elenco viene dallo schema reale (`listTables()` + `fieldExists('company_id')`)
e dalle migrazioni che hanno creato o popolato le tabelle. Rispetto alla
migrazione storica `BackfillCompanyId` sono state aggiunte le tabelle nate dopo:
`ip_costing_accounts`, `ip_costing_budgets`, `ip_imports`. Le eventuali tabelle
assenti in un'installazione vecchia vengono saltate, non provocano errori.

Restano fuori da entrambi gli elenchi:

- `ip_companies`, dove `company_id` è la chiave primaria e non uno scope;
- le tabelle figlie senza `company_id` (`ip_invoice_items`, `ip_payments`,
  `ip_sdi_transmissions`, ...): l'azienda si ricava dal documento padre.

### Tabelle con NULL legittimo (`GLOBAL_TABLES`)

| Tabella | Perché il NULL è voluto |
| --- | --- |
| `ip_fiscal_years` | riga condivisa come modello di partenza; `FiscalYearModel::scopeEffective()` mostra quella dell'azienda se esiste, altrimenti questa |
| `ip_fiscal_brackets` | scaglioni che accompagnano l'anno condiviso (`FiscalBracketModel::ofCompany(null)`) |

Assegnare queste righe all'azienda predefinita toglierebbe il modello alle
altre: il backfill **non le tocca** e i conteggi non le includono.

## Diagnosticare

### Dalla pagina Stato del sistema

Il controllo **Dati senza azienda** legge solo i conteggi. Se ci sono orfani
mostra un avviso con il numero di righe, il numero di tabelle coinvolte e il
comando da eseguire; se sono zero, il controllo è verde. Il backfill non parte
mai da una pagina: una scrittura su dati storici deve essere una scelta
esplicita.

### Dal terminale

```console
php spark companies:orphans
```

Senza opzioni è un **dry-run**: non scrive nulla, stampa una tabella con
`Senza azienda`, `Righe totali` ed esito per ogni tabella, più il totale. Esce
con `0` se non ci sono orfani e con `1` se ce ne sono, così può essere usato in
una checklist di aggiornamento o in cron.

Esempio su un'installazione a posto:

```
+---------------------+---------------+--------------+-------+
| Tabella             | Senza azienda | Righe totali | Esito |
+---------------------+---------------+--------------+-------+
| ip_bank_accounts    |             0 |            1 | ok    |
| ip_clients          |             0 |           79 | ok    |
| ip_invoices         |             0 |          100 | ok    |
...
Totale: 0 righe senza azienda su 591 righe esaminate in 15 tabelle.
NULL legittimo (condivisi, non contati): ip_fiscal_brackets, ip_fiscal_years.
```

L'output contiene solo nomi di tabella e conteggi: nessun nome di cliente, di
fornitore o importo.

## Riassegnare

```console
php spark companies:orphans --fix
```

Il comando assegna tutte le righe con `company_id NULL` delle tabelle legacy
all'azienda con `company_is_default`. Poi riconta e:

- esce con `0` se il totale è arrivato a zero;
- esce con `1` se restano righe (per esempio perché una scrittura è fallita).

È **idempotente**: rilanciarlo aggiorna zero righe. Se manca un'azienda
predefinita non scrive nulla e spiega il motivo; le righe restano NULL e
continuano a comportarsi come oggi.

La stessa operazione viene eseguita dalla migrazione
`BackfillOrphanCompanyData`: chi aggiorna l'applicazione non deve ricordarsi il
comando. La migrazione salta le tabelle assenti e non fallisce se non c'è
un'azienda predefinita.

### La visibilità è ora ristretta (eccezione NULL rimossa)

Dopo il backfill (0 orfani su tutte le tabelle legacy) l'eccezione
`OR company_id IS NULL` è stata tolta da `company_scope_sql()`,
`scope_builder_to_company()`, `scopeToCompany()`, dalla ricerca globale, dai
report e dai punti che la ripetevano a mano. Il perimetro è ora uguaglianza.

Restano volutamente invariati i due parametri fiscali condivisi, che vivono in
`FiscalYearModel::scopeEffective()` e `FiscalBracketModel::ofCompany()`: lì il
NULL significa "modello comune a tutte le aziende", non "dato da assegnare".

Cosa comporta:

1. una nuova riga con `company_id NULL` **non compare** più nelle liste. È il
   comportamento voluto: un errore di scrittura non deve restare invisibile a
   nessuno;
2. per accorgersene c'è `php spark companies:orphans` e il controllo "Dati
   senza azienda" nella pagina Stato del sistema: il conteggio va tenuto a
   zero;
3. un'installazione che non ha mai fatto il backfill deve eseguirlo **prima** di
   aggiornare il codice: la migrazione `BackfillOrphanCompanyData` gira con
   l'aggiornamento, quindi in pratica il caso è coperto.

## Sequenza consigliata

1. **Backup verificato** del database (e, se possibile, prova di ripristino con
   `php spark backup:verify`). Il backfill è irreversibile: dopo non si
   distingue una riga riassegnata da una che apparteneva già all'azienda
   predefinita.
2. **Diagnosi**: `php spark companies:orphans`. Annota quali tabelle hanno
   orfani e quanti.
3. Se ci sono orfani e l'azienda predefinita è quella giusta:
   `php spark companies:orphans --fix`.
4. **Verifica**: rilanciare `php spark companies:orphans` e controllare che il
   totale sia `0`; nella pagina Stato del sistema il controllo deve essere
   verde. Aprire qualche documento/cliente della seconda azienda per
   confermare che non compaiano più voci che non le appartengono.
5. Il perimetro è già ristretto (l'eccezione `company_id IS NULL` non c'è più):
   se il conteggio non è zero, le righe rimaste non compaiono in nessuna
   azienda. Vanno assegnate con `--fix` o a mano prima di considerare chiusa
   l'installazione.

Se l'azienda predefinita non è quella a cui appartengono i dati storici, non
usare `--fix`: prima si cambia l'azienda predefinita (Impostazioni > Aziende)
o si assegna a mano il sottoinsieme interessato, poi si rilancia il conteggio.
Il comando non indovina: assegna tutto a una sola azienda, che è la scelta
giusta per i dati anteriori alla separazione ma non per dati nati dopo.

## Cosa non è coperto

- Le tabelle figlie senza `company_id` seguono il padre: un documento orfano
  trascina con sé righe e pagamenti, ma non vengono contati separatamente.
- `ip_email_tracks` usa `track_company_id`, non `company_id`: non entra in
  questo conteggio. Il tracciamento si legge per documento, quindi un invio
  orfano non amplia il perimetro di una lista.
- Il comando non decide quale azienda sia quella "giusta" per un singolo
  record: per i dati nati dopo il multi-azienda serve un controllo umano (o la
  logica più prudente di `AssignOrphanClientsProducts`, che assegna solo quando
  tutte le fatture del cliente stanno in un'unica azienda).
