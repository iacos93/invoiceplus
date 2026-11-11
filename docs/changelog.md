# Come si scrive il changelog

Il `CHANGELOG.md` nella radice del progetto è il registro delle modifiche
visibili. Le note di release su GitHub si ricavano da lì: quello che non è nel
changelog non esiste per chi aggiorna.

Questa guida dice come scrivere le voci, come scegliere il numero di versione e
in che ordine fare commit, tag e release.

## Quando si scrive

Non alla fine: **a ogni modifica che cambia qualcosa per chi usa il programma.**
La voce si aggiunge subito sotto `## [Non pubblicata]`, nel momento in cui la
modifica è verificata, non quando si prepara la release. Se si rimanda, le voci
si dimenticano e la release esce con un changelog che non corrisponde al codice.

Una modifica è "visibile" quando una persona che usa l'applicazione se ne può
accorgere: una pagina nuova, un errore che prima capitava e ora no, un comando
che cambia comportamento, un file di configurazione nuovo. Un refactoring
interno senza effetti esterni non va nel changelog, a meno che non spieghi un
guasto che l'utente ha visto.

## Struttura del file

```markdown
# Registro delle modifiche

<come vengono numerate le versioni>

## [Non pubblicata]

### Aggiunto / Modificato / Corretto / Rimosso / Sicurezza

- voce

## [v0.2] — 2026-11-11

### Aggiunto

- voce
```

- Le versioni stanno **dalla più recente alla più vecchia**.
- La data è quella di pubblicazione, in formato `AAAA-MM-GG`.
- La sezione `Non pubblicata` esiste sempre, anche vuota: è il punto in cui si
  scrive durante lo sviluppo.
- Alla release, `## [Non pubblicata]` diventa `## [v0.3] — 2026-11-12` e se ne
  apre una nuova vuota sopra.

## Le cinque categorie

| Categoria | Quando usarla |
|---|---|
| **Aggiunto** | Funzionalità, pagina, comando, opzione che prima non c'erano. |
| **Modificato** | Comportamento o interfaccia cambiata, anche se migliorativa. |
| **Corretto** | Un difetto che l'utente poteva incontrare. È la categoria più usata. |
| **Rimosso** | Qualcosa che non c'è più: una funzione, una rotta, un'opzione. |
| **Sicurezza** | Vulnerabilità, permessi, dati esposti, credenziali. |

Se una modifica sta in due categorie, va in quella che interessa di più a chi
legge: una pagina nuova che corregge anche un errore è **Aggiunto**; un
permesso sbagliato che esponeva dati è **Sicurezza**, non **Corretto**.

## Come scrivere una voce

La voce dice **cosa cambia per chi usa il programma**, non come è stato scritto
il codice. Chi legge deve poter capire se lo riguarda e cosa deve fare.

- Una voce = un cambiamento. Se servono due frasi per due cose diverse, sono due
  voci.
- Verbo al presente o all'infinito, terza persona: "Il backup funziona anche
  su…", non "Ho sistemato il backup".
- Prima il comportamento nuovo, poi — se serve — il motivo: che cosa
  succedeva prima e perché era un problema.
- I nomi concreti aiutano: pagina, rotta, comando, file di configurazione,
  chiave del `.env`.
- Niente "fix vari", "miglioramenti", "pulizia del codice": non dicono niente e
  costringono a leggere il diff.

Esempi:

```markdown
# No
- Sistemato il backup.

# Sì
- Il backup automatico funziona anche su MySQL/MariaDB: l'endpoint sceglie
  `pg_dump` o `mysqldump` dal motore configurato. Prima lanciava sempre
  `pg_dump`, quindi su un'installazione MariaDB falliva parlando di PostgreSQL.
```

```markdown
# No
- Refactoring di GenerateFatturaJob.

# Sì
- `GenerateFatturaJob` chiamava un metodo inesistente: il job in coda sarebbe
  fallito a ogni esecuzione. Ora usa `FatturaPaXmlService`.
```

Per le correzioni conviene dire **come si manifestava** il difetto (errore,
pagina bianca, file mancante): è così che chi lo ha visto lo riconosce.

## Cosa non deve entrare

Il repository è pubblico e la storia Git non si cancella davvero: una voce
sbagliata resta.

- **Dati reali di un'azienda o di una persona**: conteggi di documenti,
  fatturato, importi, dimensioni dei dump, nomi di clienti o fornitori, numeri
  di fattura, indirizzi. Al loro posto si descrive il criterio ("conteggi
  identici all'originale"), non il valore.
- **Segreti**: chiavi, password, token, stringhe di connessione, anche se
  sembrano di prova.
- **Dati di terzi** e riferimenti a software o progetti esterni non pertinenti.
- **Percorsi o nomi interni all'azienda** che non servono a chi usa il
  programma.

Prima di committare una release conviene cercarli:

```console
git diff v0.2..v0.3
git log v0.2..v0.3 --format=%B
git show v0.3:CHANGELOG.md
```

## Scegliere il numero di versione

Le versioni seguono [Semantic Versioning](https://semver.org/). Il progetto è
in `0.x`, quindi il maggiore resta 0:

| Cambiamento | Versione | Esempio |
|---|---|---|
| Solo correzioni, nessuna novità visibile | patch: `0.2.1` | un errore che faceva fallire il backup |
| Novità visibili o comportamento cambiato | minor: `0.3.0` | una pagina nuova, il backup su un altro motore |
| Cambiamento che rompe configurazioni o procedure esistenti | minor con nota evidente (in `0.x`) | rinomina di una chiave del `.env` |

In `0.x` non si usa il maggiore: si passa a `1.0.0` solo quando il progetto esce
dallo stato di sviluppo. Se una modifica rompe qualcosa, va detto in apertura
della sezione, con la riga "**Da fare prima di aggiornare:** …".

## Procedura di rilascio

Nell'ordine, senza saltare passaggi:

1. **Chiudere il changelog.** In `CHANGELOG.md`, `## [Non pubblicata]` diventa
   `## [v0.3] — 2026-11-12`; sotto si apre una nuova `## [Non pubblicata]`
   vuota.
2. **Controllare il diff** con i tre comandi della sezione precedente: niente
   dati reali, niente segreti, niente voci che descrivono cose non presenti.
3. **Commit e push.**

   ```console
   git add CHANGELOG.md README.md
   git commit -m "Prepara la versione v0.3"
   git push origin main
   ```

   Il README contiene la riga con l'ultima versione pubblicata: va aggiornata
   nello stesso commit.
4. **Aspettare la CI verde** sul commit appena pushato. Non si tagga un commit
   che non è passato.
5. **Tag annotato** sul commit della release:

   ```console
   git tag -a v0.3 -m "v0.3

   <due o tre righe su cosa porta questa versione>"
   git push origin v0.3
   ```

6. **Release GitHub**, con le note ricavate dalla sezione del changelog ma
   riscritte per chi legge: un titolo, le novità in evidenza, la tabella dei
   comandi nuovi se servono, la procedura di aggiornamento e cosa è stato
   verificato.

   ```console
   gh release create v0.3 --title "v0.3" --notes-file /tmp/note-v0.3.md
   ```

7. **Verificare che il tag contenga il changelog giusto**, non quello del
   commit precedente:

   ```console
   git show v0.3:CHANGELOG.md | head -20
   ```

   Se la sezione `v0.3` non c'è, il tag è sul commit sbagliato: si sposta il
   tag (`git tag -f` e push forzato del solo tag) prima che qualcuno lo
   scarichi.

### Note di release e changelog

Sono due testi diversi per due lettori diversi:

- il **changelog** è completo e asciutto: elenca tutto, in ordine di categoria;
- le **note di release** sono la parte che conta per chi aggiorna: cosa cambia
  nella pratica, cosa fare prima di aggiornare, cosa è stato verificato.

Non si copia il changelog così com'è: si riorganizza. Quello che non può
mancare nelle note è la procedura di aggiornamento e l'elenco delle migrazioni
da applicare (o la frase "nessuna migrazione").

## Errori da evitare

- **Correggere dopo il tag.** Se il changelog era sbagliato e il tag è già
  pubblico, la correzione è una nuova patch, non un tag riscritto: chi ha già
  scaricato `v0.3` non vedrebbe la differenza.
- **Tag su un commit con `Non pubblicata` dentro.** È l'errore più frequente:
  la release dichiara una versione che nel file non esiste.
- **Raccontare il codice invece dell'effetto.** "Aggiunto il servizio X" non
  dice niente; "Le fatture ricorrenti non vengono più create due volte" sì.
- **Dimenticare il README.** La riga con l'ultima versione è parte della
  release.
- **Mettere dati reali.** Un conteggio o un importo sembrano dettagli tecnici,
  ma sono dati di un'azienda: nel dubbio, si scrive il criterio di verifica, non
  il numero.

Un'eccezione sola alla regola "non si riscrive la storia": se è sfuggito un dato
personale o un segreto, si riscrive la storia, si ricreano i tag e si chiede a
GitHub la rimozione degli oggetti vecchi (restano raggiungibili per SHA anche
dopo un force-push). È una procedura d'emergenza, non il modo normale di
correggere una voce.
