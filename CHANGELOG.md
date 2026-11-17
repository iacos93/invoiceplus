# Registro delle modifiche

Le versioni seguono [Semantic Versioning](https://semver.org/): la patch sale
per le correzioni, il numero minore quando cambia qualcosa di visibile. Il
progetto è ancora in sviluppo (0.x), quindi il maggiore resta 0.

Le regole per scrivere le voci e preparare una release sono in
[docs/changelog.md](docs/changelog.md).

## [Non pubblicata]

### Corretto

- `IpBaseModel::saveRecord()` restituiva l'id anche quando `update()` falliva,
  quindi ogni form che si fida del valore — fornitori a parte, corretto nella
  v0.14.2 — annunciava «salvato correttamente» su una riga rimasta com'era. Ora
  torna `0` quando non scrive, e i form di clienti, spese, F24, fatture,
  preventivi e documenti di trasporto, e quelli dei vocabolari (unità, famiglie,
  aliquote, prodotti, attività, progetti, metodi e categorie) restano aperti con
  il messaggio dell'errore invece di dichiarare il salvataggio.
- La modifica di un metodo di pagamento non veniva mai scritta: la regola
  `is_unique` con segnaposto arrivava al modello senza l'id della riga, così
  confrontava il metodo con se stesso e rifiutava l'aggiornamento in silenzio.
  Prima lo si scopriva solo perché il nome non cambiava; ora il modello rimette
  l'id nella riga da validare e il salvataggio passa.
- Con un salvataggio rifiutato il conto, la casella e l'azienda predefiniti
  venivano tolti a **tutte** le altre righe, lasciando nessun predefinito: il
  flag ora si aggiorna solo se la riga è stata scritta. Le righe di fattura e
  preventivo non ricalcolano gli importi su un id che non esiste.

## [v0.14.2] — 2026-11-17

### Corretto

- `SupplierModel::saveRecord()` restituiva l'id anche quando non aveva scritto
  niente, per esempio con un'email non valida: il form fornitori annunciava
  «Fornitore aggiornato correttamente» su una riga rimasta com'era, l'import
  delle fatture passive collegava la spesa a un fornitore mai creato e l'errore
  di validazione restava solo in `errors()`, che nessuno leggeva. Ora torna `0`
  quando non salva: il form resta aperto con il messaggio, l'import annulla la
  transazione e la spesa non viene registrata, `suppliers:enrich` dichiara i
  fornitori non salvati.

## [v0.14.1] — 2026-11-17

### Corretto

- Un fornitore la cui fattura riporta due indirizzi email nello stesso nodo
  restava senza anagrafica: il valore intero non superava la validazione e il
  modello non salvava **nessuno** dei campi della riga, indirizzo e CAP
  compresi, senza mostrare un errore. Ora si tiene il primo indirizzo valido, e
  `suppliers:enrich` segnala i fornitori che non è riuscito a salvare invece di
  contarli come aggiornati.

## [v0.14.0] — 2026-11-16

### Aggiunto

- Il comando `suppliers:enrich` riempie i campi vuoti dell'anagrafica fornitori
  — indirizzo, CAP, comune, provincia, nazione, email, telefono, IBAN — leggendo
  gli XML delle fatture elettroniche già importate; con `--dry-run` mostra il
  diff e non scrive niente. Vince il documento più recente, e un campo già
  compilato non viene mai toccato.

### Modificato

- L'importazione di una fattura elettronica di acquisto riempie l'anagrafica del
  fornitore con la sede e i contatti che il documento riporta, invece di fermarsi
  a nome, P.IVA e codice fiscale: prima ogni fornitore nuovo nasceva senza
  indirizzo pur essendo il dato dentro il file appena letto, e un fornitore
  esistente non veniva aggiornato nemmeno nei campi vuoti.

## [v0.13.1] — 2026-11-16

### Corretto

- Il codice SIA del conto di saldo accetta solo cinque caratteri alfanumerici,
  lettera O esclusa, come chiede lo standard CBI dei flussi RiBa, e il messaggio
  d'errore dice qual è il problema. Prima passavano anche la O e i simboli: un
  «5$3T8» copiato dall'home banking veniva ripulito in quattro caratteri,
  salvato senza proteste e poi rifiutato dalla banca insieme all'intera
  distinta — non solo alla ricevuta sbagliata.

## [v0.13.0] — 2026-11-16

### Aggiunto

- Nella lista Fatture l'azione di gruppo «Segna come pagate» registra un incasso
  pari al residuo delle fatture selezionate, con la data di oggi e il metodo di
  pagamento della fattura: lo stato passa a «Pagato» perché segue i pagamenti,
  non perché qualcuno l'ha scritto. Restano fuori le bozze, le fatture già
  saldate e **le RiBa non ancora scadute**, che la banca presenterà alla
  scadenza — prima l'unica via era incassare a mano, fattura per fattura. Il
  riepilogo dice quante righe sono state saltate e quante di queste erano RiBa.

### Corretto

- Nelle liste vuote la chiamata all'azione («Aggiungi il primo conto», «Nuova
  fattura», «Nuova spesa»…) sta su una riga propria sotto il messaggio, com'era
  già nella lista Spese: le righe di stato vuoto la racchiudono in
  `.empty-actions` invece di affidare il capo riga solo al `display: flex`
  dell'ancora. Nella pagina Conti di saldo il pulsante restava attaccato alla
  frase, in linea con il testo.

## [v0.12.0] — 2026-11-16

### Modificato

- La pagina RiBa elenca solo le fatture emesse con pagamento RiBa (metodo con
  modalità MP12), con saldo aperto e non già in distinta; anche la generazione
  del flusso accetta solo quelle. Prima mostrava sotto tutte le altre fatture
  aperte, «presentabili comunque».

- La barra di composizione di «Fatture per stato» e «Preventivi per stato» in
  Bacheca è interattiva come i grafici: ogni segmento è un collegamento
  all'elenco di quello stato, con un tooltip (importo, numero di documenti,
  quota) e raggiungibile da tastiera; la barra è alta 8 px invece di 6, per
  poterla centrare con il puntatore.

- I grafici della Bacheca e della scheda cliente sono interattivi: al
  passaggio del mouse (o con Tab e le frecce, o al tocco) il mese si evidenzia
  e un tooltip mostra i valori già formattati, al posto del tooltip nativo del
  browser. Un clic sul mese di ricavi e costi apre l'elenco di fatture o spese
  di quel mese; il grafico dell'indice apre «Utile e imposte» dell'anno. Le
  voci della legenda dei ricavi accendono e spengono Fatturato e Incassato. Al
  tocco il primo tap mostra i valori e il secondo apre.

- I pulsanti dei filtri (barra e pannello di filtro, mesi, commutatore
  Clienti/Fornitori, invio dei form di filtro) non fanno più scorrere la
  pagina: restano dov'è. Solo il cambio pagina del pager riporta alla cima
  della lista.

- **Meno query a ogni pagina.** Per applicare il perimetro dell'azienda
  l'applicazione chiedeva al database, a ogni richiesta e per ogni tabella
  toccata, se esistesse la colonna `company_id` (fino a quattro interrogazioni
  dello schema sulla Bacheca, le più lente della pagina su PostgreSQL). La
  risposta positiva ora si ricorda fra una richiesta e l'altra; quella negativa
  si ricontrolla sempre, così una migrazione che aggiunge la colonna non può
  lasciare una tabella fuori dal perimetro.
- **Guida al sottodominio:** la configurazione nginx dichiara compressione e
  cache lunga degli asset (sotto nginx `public/.htaccess` non viene letto) e
  la verifica finale controlla le intestazioni e opcache.
- **Conti di saldo e metodi di pagamento: conteggi e saldi solo per le righe
  visibili.** Gli elenchi sono paginati, ma i conteggi d'uso aggregavano tutte
  le fatture, spese, movimenti e F24 e i saldi rileggevano l'intera tabella dei
  conti a ogni pagina; la cancellazione ripeteva le stesse aggregazioni per un
  solo id. Ora i metodi ricevono gli id già caricati dal controller, così il
  filtro usa gli indici e non si conta ciò che non è a schermo. I numeri
  mostrati restano gli stessi.
- **Fornitori, attività, progetti e prodotti: elenchi paginati e totali solo
  per le righe visibili.** Le quattro liste caricavano tutto l'archivio a ogni
  apertura (per i fornitori, una scansione completa di `ip_expenses` con
  GROUP BY; per i progetti, tutta `ip_tasks` aggregata) e non avevano pager.
  Ora leggono una pagina con i collegamenti di paginazione come le altre
  liste, e i totali accessori (spese per fornitore, attività per progetto)
  arrivano da un aggregato filtrato sui soli id della pagina. I numeri
  mostrati restano gli stessi.
- **Barra anagrafiche coi filtri come nelle altre liste.** La barra di
  clienti e fornitori componeva i gruppi a mano, due volte (headerbar e
  sottomenu mobile), e il commutatore Clienti/Fornitori usava i pulsanti
  «grossi» di Bootstrap accanto ai segmenti piatti che usano fatture,
  preventivi e spese: nella stessa riga convivevano due stili. Ora i filtri
  escono da `layout/list_filters`, lo stesso componente delle altre liste
  (gruppi etichettati, segmenti `index-options`), e il pannello si comporta
  come sulle altre liste: aperto su desktop, aperto da «Filtra» su telefono.

- Il riordino delle righe di fatture e preventivi legge l'appartenenza delle
  righe con una sola query invece di una per riga, e salva il nuovo ordine in
  un'unica transazione; anche "Ricalcola tutti gli importi" di fatture e
  preventivi lavora in un'unica transazione, più rapido sugli archivi grandi.

- Su Clienti e Fornitori, il commutatore fra le due anagrafiche non fa più
  scendere la pagina fino alla tabella: cambiare sezione riparte dall'alto,
  mentre filtri di stato e pager continuano ad allineare la lista.

- L'icona dell'app installata (PWA) porta il margine delle icone di sistema
  invece di andare a filo del bordo, così nel Dock, nel menu Start e nel
  launcher ha la stessa misura delle altre app; aggiunta l'icona maskable a
  192 px e versionati gli URL, perché chi ha già installato l'app riceva
  quella nuova.

- Pagina «Primo avvio» rifinita: il titolo del pannello sinistro va a capo
  bilanciato invece di lasciare «ordine.» da solo, la password indica il
  minimo di 8 caratteri, «Esito dei controlli» ha un chevron e un filetto al
  posto del triangolo nativo, e la nota finale è testo normale invece del
  carattere monospazio della riga di stato passkey.

- L'Indice di redditività mostra sempre 12 mesi, mentre Grafico ricavi e
  Grafico costi restano a 6 nella card normale e mostrano tutti i 12 mesi (con
  i loro totali e l'etichetta del periodo) quando la finestra è espansa.
  L'ultimo mese della finestra è lo stesso per le due lunghezze: con i dati
  fermi a gennaio, il 12 mesi non finisce più a oggi.

- La banda che evidenzia il mese nei grafici è traslucida e non più un grigio
  pieno: nel grafico ad area copriva la linea proprio nel mese guardato.

- Lo stato vuoto delle finestre della Bacheca («Preventivi recenti» senza
  preventivi) non si tinge più di grigio al passaggio del mouse e occupa tutto
  lo spazio della finestra, centrato, invece di un blocco grigio nella parte
  alta con il resto bianco.

- La pagina «Sei offline» usa il marchio vero dell'app (le stesse proporzioni
  di pwa-icon.svg): quello in linea era una copia a mano con barre e croce
  diverse. La cache del service worker passa a v4, perché la pagina è
  precaricata.

- Nelle finestre «Fatture per stato» e «Preventivi per stato» della Bacheca la
  barra di composizione ha lo spazio anche sotto (14 px): stava attaccata alla
  prima riga della tabella.

- Nei grafici, uscendo con il mouse da un mese compariva per un istante una
  banda a piena intensità (nera nel tema chiaro, bianca in quello scuro): il
  colore della banda si animava mentre l'opacità cambiava di colpo. Ora si
  anima solo l'opacità, e la banda sfuma in modo continuo.

- Il marcatore «XML» accanto al numero delle spese (scheda fornitore e lista
  Spese) resta il badge standard dell'app ma non si inverte più a colore pieno
  al passaggio del mouse sulla riga, come fanno i timbri di stato: è
  un'etichetta di origine, non uno stato, e invertito gridava più del numero.

- Il badge «XML» delle spese aveva il testo attaccato al bordo: nella scheda
  fornitore una regola per le aree cliccabili delle tabelle azzerava il
  padding orizzontale dei link, badge compresi, e nella lista Spese la riga
  dedicata al badge (display: table) annullava il padding. Ora ha lo stesso
  spazio interno degli altri badge.

- Il badge «XML» accanto al numero della spesa ha un margine proprio (circa 9
  px in tutto) invece del solo spazio della parola, che lo lasciava attaccato
  alle cifre.

- L'import degli XML di fatture emesse collegava la nota di credito alla
  fattura stornata cercando solo il numero: con più anni in archivio la NC
  agganciava la fattura omonima più vecchia, di un altro cliente (la NC 1/2026
  che storna la 21/2026 finiva sulla 21/2025). Ora usa anche la data della
  fattura collegata riportata nel tracciato (o l'anno della nota di credito)
  ed esclude le altre note di credito.

### Corretto

- Nella pagina Integrazione MCP il pulsante Copia della chiave non dice più
  «la chiave è selezionata, premi Ctrl+C» quando la chiave non è mai arrivata:
  una sessione scaduta o un token CSRF consumato da un'altra scheda facevano
  scaricare l'HTML del rimando al posto del JSON, e l'errore finiva nello
  stesso ramo della copia negata, su un campo vuoto. Ora la richiesta
  distingue una risposta non JSON e avvisa di ricaricare la pagina, e anche la
  rigenerazione segnala il fallimento invece di restare muta; se il token CSRF
  è stato consumato, la pagina rilegge il proprio e riprova una volta, invece
  di chiedere di ricaricare. La copia inoltre usa il comando sincrono del
  browser quando l'API Clipboard non è disponibile in un contesto sicuro, e se
  anche quello è negato lascia la chiave a fuoco e selezionata per Ctrl+C.

- Nel Controllo di gestione i pulsanti delle schermate «Prima il piano dei
  conti» (Importa piano standard, Crea una voce, Piano dei conti) avevano la
  misura piena di Bootstrap, più alta e con testo più grande di tutti gli
  altri pulsanti d'azione dell'app: ora sono compatti come il resto.

- I grafici della Bacheca (fatturato e incassato, spese, utile, IVA e le
  sparkline delle card) non si aprono più vuoti quando negli ultimi 6 mesi non
  ci sono movimenti: con un anno scelto in topbar la finestra finisce a
  dicembre di quell'anno, con «Tutti gli anni» segue l'ultimo mese con
  movimenti, e l'etichetta dice «6 mesi fino a MM/AAAA». Prima era sempre «gli
  ultimi 6 mesi da oggi», quindi un archivio fermo a dicembre mostrava grafici
  piatti.

- L'import degli XML di fatture emesse scartava come doppione una nota di
  credito con lo stesso numero di una fattura dello stesso anno (le note di
  credito hanno di solito una serie propria): il fatturato restava gonfiato
  del suo importo. Il controllo dei doppioni ora distingue note di credito e
  fatture.

- I pulsanti divisi (Esporta, Nuovo cliente/fornitore e gli altri con la
  freccia del menu) si mostravano come due pezzi staccati con un doppio bordo:
  il Bootstrap ridotto scartava le regole di unione del gruppo perché nominano
  `.btn-check` dentro `:not()`. Le classi dentro `:not()` non contano più come
  richieste, e un filetto interno separa la freccia anche sul pulsante
  primario.

- Su MySQL la Bacheca dava errore 500 e il cron delle fatture ricorrenti
  falliva a ogni giro: le serie mensili usavano TO_CHAR e il lock del cron
  l'advisory lock, entrambi solo PostgreSQL. Ora le serie passano da
  `sql_year_month()` e il lock usa GET_LOCK() su MySQL; su PostgreSQL un lock
  già preso da un altro giro non risulta più acquisito per errore (`'f'` letto
  come vero). Un test di guardia segnala SQL solo PostgreSQL senza ramo per
  l'altro motore.

- Su MySQL l'installazione pulita si fermava a metà migrazioni: gli indici su
  `passkey_credential_id` e `client_vat_id` (colonne TEXT) ora usano una
  lunghezza di prefisso, che MySQL richiede e MariaDB tollerava.

- **`migrate:rollback` della migrazione del codice SIA falliva su
  PostgreSQL.** Il ramo di ritorno scriveva `bank_account_is_default = 1`, e
  su PostgreSQL i booleani rifiutano il confronto con l'intero (42883): la
  discesa si fermava a metà. Ora usa `IS TRUE`, valido su entrambi i motori.
- **Import CSV dei fornitori: cella «attivo» vuota spegneva il fornitore.**
  `db_bool('')` è false, quindi una colonna presente senza valore importava i
  fornitori come inattivi invece di usare il valore predefinito (attivo).
- **Conti di saldo: il badge «Predefinito» era su tutti.** La lista leggeva
  `bank_account_is_default` con `!empty()`, ma PostgreSQL restituisce i
  booleani come stringa `"t"`/`"f"` e `!empty("f")` è true: anche i conti non
  predefiniti mostravano il badge. Ora il flag passa da `db_bool()`.
- **Booleani letti come stringhe nei form.** Altri due campi arrivavano da
  PostgreSQL come `"t"`/`"f"` e finivano in un controllo di verità: in modifica
  il conto non predefinito mostrava la spunta «Usa come conto predefinito» (e
  un salvataggio senza toccarla lo rendeva davvero predefinito), e il regime
  forfettario agevolato risultava sempre spento perché `(int) "t"` vale 0. Ora
  entrambi passano da `db_bool()`.

## [v0.11.0] — 2026-11-15

**Da fare prima di aggiornare:** `php spark migrate` (aggiunge a ogni azienda
le colonne dei loghi, del piè di pagina dei documenti e del codice destinatario
SdI, il conto di saldo sugli incassi e sui metodi di pagamento, il saldo di
apertura dei conti, porta la settimana del calendario a cominciare di lunedì,
mette gli indici sulle colonne di collegamento e rende univoche le chiavi
d'accesso di preventivi e allegati).

### Aggiunto

- **Rotazione automatica di log e debugbar.** `writable/logs` cresceva di
  200-460 KB al giorno e `writable/debugbar` di qualche MB, e nessuno le
  svuotava: sono le uniche due cartelle che crescevano senza limite, e ce ne si
  accorgeva solo quando il disco era pieno — cioè quando l'applicazione smette
  di scrivere e comincia a sbagliare. Il giro giornaliero del cron, quello che
  fa già i backup, ora comprime i log più vecchi di una settimana (un .log di
  testo si riduce a un decimo) e toglie i compressi oltre sei mesi e i file
  della debugbar oltre due settimane. Il file del giorno non si tocca: si sta
  ancora scrivendo.
- **Saldo di apertura e saldo corrente dei conti.** Ogni conto di saldo dice
  con quanto si apre e da quando: è il numero che si legge dall'estratto conto
  il giorno in cui si comincia a tenere la prima nota. L'elenco dei conti
  mostra il saldo corrente — apertura, più incassi, meno spese — con la data
  di apertura sotto; un conto senza saldo iniziale lo dice ("solo i movimenti
  registrati") invece di far credere che il totale sia quello della banca.
  I movimenti di estratto conto non entrano nella somma: sono la stessa banca
  fotografata, non soldi in più, e contare sia l'incasso sia il bonifico
  gonfierebbe il saldo del doppio. Allo stesso modo restano fuori dal totale
  gli incassi e le spese *precedenti* alla data di apertura: sono già dentro
  il saldo iniziale, che si legge dall'estratto conto quel giorno. È il secondo
  pezzo della prima nota.
- **Il conto di saldo sugli incassi.** Ogni incasso dice ora su quale banca o
  cassa sono entrati i soldi: le spese lo dicevano già, gli incassi no, quindi
  nessun saldo di conto poteva tornare. Il conto lo propone il metodo di
  pagamento — ogni metodo ha il suo in Impostazioni → Metodi di pagamento
  (bonifico sul conto corrente, contanti sulla cassa) e in mancanza vale il
  conto predefinito dell'azienda — e resta modificabile nel form; l'incasso
  rapido dalla fattura e l'import usano la stessa proposta. «Nessun conto» è
  una scelta rispettata, non un campo da riempire d'ufficio. È il primo pezzo
  della prima nota: dal saldo iniziale di ogni conto in poi.
- **Il codice destinatario SdI dell'azienda.** Nella scheda azienda, accanto a
  P.IVA e codice fiscale, c'è ora il codice destinatario con cui l'azienda
  riceve le fatture elettroniche; quando è compilato compare anche in cima alla
  barra laterale, sotto il nome dell'azienda, così si legge e si copia senza
  aprire Impostazioni. Era un dato che esisteva solo per i clienti, cioè per chi
  le fatture le riceve.
- **Codice SdI e codice cliente nella scheda cliente.** Nella tessera
  anagrafica, accanto a P.IVA e codice fiscale, ci sono ora il codice
  destinatario SdI e il codice cliente, ognuno con il pulsante che lo copia
  negli appunti: sono i due dati che si passano di continuo (al cliente, al
  commercialista, nel cassetto fiscale) e prima bisognava aprire il modulo di
  modifica per leggerli. Da tre codici in su la piastra si dispone su due
  colonne, così la tessera non si allunga; un cliente senza SdI o senza P.IVA
  vede solo le righe compilate.
- **Loghi dei documenti per azienda.** Nella scheda di ogni azienda si caricano
  il logo esteso (intestazione) e il solo segno (piè di pagina), stampati su
  fatture, preventivi e DDT di quell'azienda. Prima i due loghi erano unici
  per tutta l'installazione, quindi due aziende uscivano con lo stesso marchio.
  Il logo è quello dell'azienda che emette il documento, anche dal portale
  clienti e dagli invii automatici; un'azienda senza logo proprio continua a
  usare quello di Impostazioni → Fatture.
- **Piè di pagina dei documenti per azienda.** Accanto ai loghi, la scheda
  azienda ha ora il testo a piè di pagina di fatture e DDT e quello dei
  preventivi: contatti e diciture di legge di quel soggetto (per una S.r.l.
  sede, Registro delle imprese, capitale sociale) non finiscono più sui
  documenti di un'altra azienda. Lasciato vuoto, vale il testo delle
  Impostazioni.
- **Installabile come app (PWA).** Da Chrome, Edge o Safari Invoice+ si può
  installare sul computer o sul telefono: si apre in una finestra sua, con la
  sua icona e le scorciatoie «Nuova fattura», «Nuovo preventivo» e
  «Scadenzario». Il service worker tiene in cache solo i file statici (stili,
  script, icone) e mostra una pagina «Sei offline» se manca la rete; pagine e
  dati non vengono mai salvati sul dispositivo. Serve HTTPS (o `localhost`).
- **Primo avvio guidato.** Su un'installazione nuova, prima del modulo per
  creare l'account, Invoice+ controlla PHP, database, schema, cartelle
  scrivibili e ambiente: se qualcosa è guasto lo dice in chiaro e spiega come
  sistemarlo, invece di mostrare un errore. Il database resta da configurare
  a mano nel `.env`, e la pagina spiega perché (una pagina pubblica non deve
  poter scrivere credenziali né puntare l'app a un database altrui).
- **Pagina «Primi passi».** Dopo l'account si arriva a una lista di nove
  cose da sistemare (dati e logo dell'azienda, piè di pagina, aliquote IVA,
  invio allo SdI, chiave per le automazioni, backup, verifica in due
  passaggi, ambiente di produzione): ogni voce porta alla pagina che già
  esiste e lo stato si ricava dai dati. Un promemoria in Bacheca resta finché
  non sono tutte fatte o lo si chiude dalla pagina.
- **Impostazioni non leggibili non bloccano più la pagina.** Con il database
  irraggiungibile o lo schema non creato, la lettura delle impostazioni non
  solleva più un'eccezione (resta nel log): serve alla verifica del primo
  avvio per poter mostrare che cosa manca.
- **Barra del titolo dell'app installata.** Era blu (il colore del marchio) sopra
  la barra scura dell'app; ora ha lo stesso colore della barra, chiaro o
  scuro secondo il tema scelto. Dopo l'aggiornamento il colore cambia
  nella finestra già installata alla riapertura (o reinstallando).
- **Pulsante «Installa app» nella barra.** Sta in alto a destra finché
  l'app non è installata; con Chrome o Edge apre la finestra di installazione,
  negli altri casi (incognito, Safari, Firefox, dove il browser non offre il
  prompt) spiega come installare. Scompare a installazione fatta e non si
  vede nella finestra dell'app.
- **Colonna IVA nei documenti.** Anteprima e PDF di fatture e preventivi
  mostrano l'aliquota di ogni riga («22%», «–» se la riga non ha imposta).
- **Calendario: totali del mese.** Il riepilogo laterale apre con l'importo in
  scadenza nel mese e, se c'è, la parte già scaduta.
- **Logo e contatti anche nei report PDF**: Utile e imposte, Bilancino e i
  report (vendite per cliente, storico pagamenti, scaduto, vendite per anno)
  aprono con il logo esteso e i contatti dell'azienda attiva, gli stessi di
  fatture e preventivi.
- **Calendario a elenco.** Accanto alla griglia mensile c'è ora una vista a
  elenco dello stesso mese: una riga per giorno, in ordine di data, con la data
  scritta giorno/mese/anno e il giorno della settimana nella lingua
  dell'interfaccia — il modo in cui una scadenza si legge qui. Ogni riga porta
  il tipo (fattura, F24, attività), il titolo e l'importo, e in testa restano i
  totali del mese con la quota già scaduta. Si passa dall'una all'altra dalla
  barra in alto, senza perdere il mese aperto: la vista scelta resta anche
  cambiando mese con le frecce, e i due elenchi non sono due pagine da tenere
  allineate.

### Cambiato

- **Una sola `<form>` per riga nelle liste, invece di due o tre.** In fatture,
  preventivi e ricorrenti ogni riga ne aveva una per il preferito (nella sua
  colonna) e una per ciascuna azione del menu (elimina, invia a SdI). Nella
  lista fatture erano 15 × 3 = 45 form più quelle di pagina, ora **15 × 1**;
  nei preventivi da 15 × 2 a 15 × 1. La form della riga sta nella colonna delle
  azioni, contiene una volta sola il token CSRF e il campo «back», e i bottoni
  la raggiungono con `form="ip-row-form-<id>"`: il preferito la invia così
  com'è, «Elimina» e «Invia a SdI» indicano la propria destinazione con
  `data-confirm-action`, che la conferma applica come `formaction` di un
  submitter creato al volo — l'`action` della form resta quella di partenza,
  così un «Elimina» annullato non lascia l'indirizzo sbagliato appiccicato al
  click successivo sul preferito. Nessun comportamento visibile cambia.
- **La stella dei preferiti ora funziona.** Due bug opposti sullo stesso
  campo, invisibili finché si guardava il risultato invece del database: il
  driver Postgre restituisce i booleani come stringa `"t"`/`"f"`, quindi in
  lista `!empty("f")` era sempre vero e **la stella risultava accesa su ogni
  riga** anche con nessun preferito salvato; nel controller `empty("f")` era
  sempre falso, quindi **il click riscriveva sempre `false`** e nessuna riga
  diventava preferita. Ora lo stato si legge con un confronto esplicito e si
  inverte: click sulla stella → preferita, secondo click → no, con l'elenco
  dei preferiti che torna coerente. Corretto per fatture e preventivi.
- **Il codice del guscio viaggia nel bundle, non dentro l'HTML.** Controlli del
  tema chiaro/scuro, sidebar a scomparsa su mobile, tooltip, rientro delle
  schede e conferme delegate occupavano **9,7 KB di `<script>` inline in ogni
  pagina**: identici, mai in cache, riscaricati a ogni navigazione completa.
  Ora sono `js/ip-layout.js`, dentro `ip-app.min.js` (39.961 → 42.690 byte, che
  il browser tiene in cache e il service worker conserva): **−9,7 KB di HTML per
  pagina** dopo il primo caricamento.
  Restano inline le tre cose che devono girare mentre la pagina si costruisce,
  altrimenti si vedono: il ripristino dello scroll della colonna (subito dopo il
  markup, prima del primo disegno), la misura dell'altezza reale della topbar e
  lo stato chiuso/aperto delle sezioni. Spostarle nel bundle vorrebbe dire
  disegnare con i valori sbagliati e correggere dopo, con uno scatto visibile.
  Verificato nel browser: tema chiaro → scuro con la scelta salvata, sidebar a
  scomparsa che si apre e chiude con `aria-expanded` e backdrop, finestra di
  conferma che si apre su «Elimina» con titolo, corpo e bottone rosso, cambio
  scheda nelle impostazioni senza errori in console.
- **Gli stili dei report si caricano solo nei report.** `reports.css` era nel
  `layout/base.php`, quindi **34 KB (9 KB compressi)** su ogni pagina — bacheca,
  fatture, impostazioni — per un foglio che serve a cinque schermate. Ora lo
  carica `reports/partial_style.php`, incluso dai cinque report. Non è però
  nell'`<head>`: la navigazione parziale sostituisce solo `#main-content`,
  quindi un foglio linkato nella testa non arriverebbe aprendo un report dalla
  sidebar e la pagina resterebbe senza stile fino al primo ricaricamento. Viaggia
  col contenuto, che è l'unica parte che il pjax scambia (e `link` dentro il
  body è HTML valido).
  Verificato nel browser: la bacheca non chiede più `reports.css`; aprendo
  «Report» dalla sidebar senza ricaricare la pagina il foglio viene chiesto
  (34 KB) e si applica — la griglia dell'hub, il filtro e la rampa di gravità
  dello scaduto (`--ip-aging-3`), che è definita solo lì, si risolvono.
- **I caratteri dell'interfaccia sono file dell'app, non di Google.** Ogni
  pagina chiedeva un foglio a `fonts.googleapis.com` che ne apriva un altro
  verso `fonts.gstatic.com`: **quattro richieste su due domini esterni**, prima
  di qualsiasi contenuto e fuori da ogni cache dell'applicazione. Ora Figtree,
  Fraunces e IBM Plex Mono sono quattro woff2 in `assets/core/fonts` (**107,7
  KB**: Figtree 20,2 variabile 400-700, Fraunces 67,4 variabile, IBM Plex Mono
  500 e 600 da 10,1), dichiarati con `@font-face` in testa a
  `css/src/01-token.css`. Sono gli stessi sottoinsiemi latini che il browser
  scaricava da Google — italiano e inglese completo, euro e punteggiatura
  tipografica — quindi nessun glifo in meno. Due vantaggi oltre alle richieste
  in meno: il service worker li mette in cache come gli altri asset, così
  l'app installata resta tipografata anche offline, e la policy CSP non deve
  più ammettere due origini esterne (`font-src` ora è solo `self`). Lo `?v=`
  dell'indirizzo lo aggiunge `CssBundle` con l'impronta del file: un font
  sostituito non resta in cache per un anno. Anche le pagine d'errore hanno
  smesso di chiedere i caratteri a Google.
  Un carattere fuori dal sottoinsieme latino (latino esteso, greco, cirillico)
  cade sul fallback di sistema dichiarato nei token, come quando Google non
  rispondeva.
  Verificato nel browser: nessuna richiesta verso altri domini, i tre file
  serviti da `/assets/core/fonts/`, i font calcolati corretti su `body`
  (Figtree) e `h1` (Fraunces), `document.fonts.check` vero per entrambi.
- **L'interfaccia non carica più jQuery.** Erano **85,5 KB (29,6 KB compressi)**
  chiesti in `<head>`, cioè prima di qualsiasi contenuto, su ogni pagina dell'area
  amministrativa e del portale, per il solo `scripts.js`. Le sue trentasette
  chiamate a `$(` sono ora DOM puro: un listener per elemento al posto delle
  selezioni con delega, `fetch` al posto di `$.post`, `hidden` e `classList` al
  posto di `show`/`hide`/`toggle`; le sette view che avevano script inline
  (impostazioni, canale SdI, form utente, assegnazione clienti, integrazione MCP,
  modali di pagamento e attività) fanno lo stesso. Il codice dell'interfaccia
  cresce di 1,7 KB (bundle 39.961 byte contro 38.269), quindi il bilancio per
  pagina è **−83,8 KB e una richiesta in meno** nel percorso critico; il file
  `vendor/jquery.min.js` è stato tolto dal progetto.
  Con jQuery sono sparite due cose che si portava dietro senza far rumore: il
  prefilter CSRF leggeva il token da `document.cookie`, ma il cookie è httponly e
  il token partiva sempre vuoto; e la chiamata `$(...).tooltip()` in
  `scripts.js` non esiste in Bootstrap 5, quindi il blocco di avvio si
  interrompeva lì e l'altezza dei contenuti e il loader a pagina piena non
  venivano mai inizializzati. I tooltip restano quelli di Bootstrap 5 già
  inizializzati da `layout/base.php`, e le due modali usano `bootstrap.Modal`.
  Verificato in una sessione vera nel browser: filtro live e gruppi del canale
  SdI nelle impostazioni, campi amministratore nel form utente, assegnazione di
  tutti i clienti, integrazione MCP, misuratore di password; console senza
  errori e nessuna richiesta a `jquery.min.js`.
- **I font delle icone contengono solo i glifi che l'app disegna.** Il foglio di
  Font Awesome era già filtrato sulle icone in uso, i file dei font no: si
  scaricavano **299.644 byte** in tre file per ~150 glifi. Ora il foglio carica
  tre sottoinsiemi da **16.304 byte in tutto (−94,6 %)**: il font solido da
  156.400 a 11.344 byte, il regular da 25.392 a 4.244, e soprattutto il font dei
  marchi — che nella pagina del calendario pesava 117.852 byte per tre icone
  (Apple, Google, Outlook) — ridotto a **716 byte**. Li produce `pyftsubset`
  (`php tools/subset_icons.php`) dai code point delle sole icone citate dal
  codice; accanto a ogni font resta un manifesto con i glifi che il file
  contiene davvero. Chi installa l'app non ha bisogno di Python: i file sono
  versionati, e si rigenerano solo quando si aggiunge un'icona (senza
  fonttools lo script lo dice invece di produrre un font monco). Se la
  rigenerazione viene dimenticata lo segnala il test, che confronta i manifesti
  con gli usi reali: un'icona nuova non sparisce dalla pagina in silenzio.
- **Bootstrap ridotto alle classi che l'app usa.** Il foglio vendor era 227 KB
  e la pagina ne usa una parte: carousel, spinner, progress, toast, accordion,
  offcanvas e centinaia di utility che nessuna view scrive venivano scaricati e
  analizzati a ogni primo caricamento. Ora `layout` carica
  `bootstrap.subset.min.css` (76.305 byte invece di 232.111: −67%; in gzip
  13,5 KB invece di 31,1), generato da `php tools/subset_bootstrap.php`
  leggendo le classi nei sorgenti di `app/` e del JS, più il bundle di Bootstrap,
  che è dove vivono quelle che i componenti aggiungono da soli (`show`,
  `modal-backdrop`, i `bs-tooltip-*` del posizionamento). Una regola resta solo
  se tutte le classi del suo selettore sono in uso, quindi il foglio non è una
  lista da mantenere a mano: `missingClasses()` è l'asserzione del test, e
  aggiungere una classe in una view senza rigenerare fa fallire la suite invece
  di far sparire quello stile in silenzio.
- **Il PDF di un documento non viene più ristampato a ogni richiesta.** Lo
  stesso documento veniva impaginato da capo ogni volta — due volte, quando il
  piè di pagina porta il totale delle pagine, perché il conteggio dei fogli è un
  passaggio di stampa a sé — e le occasioni non mancano: chi apre la fattura per
  controllarla e poi la scarica, l'allegato della mail, l'export ZIP, un secondo
  invio al cliente. Ora il PDF resta in `writable/cache/pdf`, con la chiave
  presa dall'HTML: se il documento, il cliente, le righe, le impostazioni o il
  logo cambiano, cambia l'HTML e il PDF si ristampa, senza che nessuno debba
  ricordarsi di invalidare qualcosa. Misurato su una fattura vera: 255-383 ms
  per la prima stampa, 2,2 ms quando il PDF c'è già. La cartella tiene al
  massimo 200 file (i più vecchi vengono tolti) e un file troncato non viene
  servito: si ristampa, perché un PDF rotto è peggio di un PDF lento.
- **Asset compressi e messi in cache dal server.** Il `.htaccess` dichiara la
  compressione (deflate) per HTML, CSS, JS, JSON e SVG e una cache di un anno
  `immutable` per CSS, JS, font e immagini: gli URL di questi file portano già
  `?v=filemtime`, quindi cambiano quando cambia il file e la cache lunga non
  può servire una versione vecchia. Le pagine restano fuori dalla cache — sono
  la pagina, e possono contenere i dati di un altro utente. Con Apache il
  guadagno è immediato (un CSS da 168 KB scende a 27,7 KB in rete); con nginx o
  su hosting che non legge `.htaccess` non cambia nulla e non rompe nulla.
- **Il logo dell'accesso non viaggia più dentro l'HTML.** Era incorporato come
  data URI: due copie in base64, circa 33 KB ciascuna, rispedite a ogni
  apertura della pagina di login e mai messe in cache dal browser (il base64
  comprime anche male). Ora lo serve una rotta — `logo/login.<ext>` — con ETag
  e cache lunga, e il nome del file lo decide l'impostazione, non l'URL. Il PDF
  resta sul data URI: dompdf legge dal filesystem e non fa richieste HTTP.
- **Ricerca globale da tre caratteri, con 300 ms di pausa.** Con due caratteri
  ogni parola faceva partire una query su sei tabelle (join e LIKE non
  filtrati) per risultati che nessuno stava ancora cercando; il pannello si
  apriva vuoto perché il campo partiva prima del server. Ora la soglia è la
  stessa nei due punti — campo e endpoint — e la pausa è quella dello
  scheletro di caricamento.
- **Il filtro per mese è un intervallo di date, non `EXTRACT(MONTH FROM ...)`.**
  Su un archivio grande la funzione impediva l'uso dell'indice e il motore
  leggeva tutte le righe dell'anno per mostrarne un mese: misurato su 640.000
  righe, 70.272 letture contro 5.952 dell'intervallo equivalente. Nulla cambia
  per chi guarda — stessi documenti, stessi totali — e la selezione multipla
  resta un OR di intervalli, così i mesi lasciati fuori non rientrano.
- **Le chiavi d'accesso di preventivi e allegati sono UNIQUE.** Sono le uniche
  colonne interrogate da fuori, senza sessione: il cliente che apre il link del
  preventivo o scarica un allegato passa da lì. Un indice semplice le rendeva
  veloci ma non univoche. Dove i duplicati ci sono già (archivio importato da
  CI3) la migrazione non fallisce: lascia l'indice semplice e scrive nel log
  quante chiavi doppie ci sono da correggere.
- **Le iniziali del cliente arrivano a tre.** Il monogramma della tessera si
  fermava alle prime due lettere, e le ragioni sociali lunghe finivano per
  coincidere: «ICS Duca d'Aosta», «ICS Giovanni XXIII» e «ICS Filippo Maria
  Beltrami» erano tutte «IC». Ora si prendono le iniziali delle prime tre
  parole che contano («IDA», «IGX», «IFM») e il carattere scende di misura per
  farle stare nella piastrella; chi ha un nome di due parole vede lo stesso
  monogramma di prima. L'articolo eliso non ruba più l'iniziale: «d'Aosta»
  conta come «Aosta».
- **Azienda e anno fermi in cima alla barra laterale.** I due selettori erano
  già in testa al menu, ma scorrevano via con esso: su una pagina lunga, o con
  le sezioni aperte, cambiarli voleva dire risalire. Ora restano fissi in alto,
  e l'etichetta «Contesto» che li introduceva è sparita — sono due comandi che
  si spiegano da soli, e occupava una riga per dire poco.
- **Elenchi più rapidi sui database grandi.** Stati fattura, righe per
  prodotto e aliquota, pagamenti per metodo, movimenti bancari per fattura e
  spesa, note cliente: erano colonne di collegamento senza indice, e
  PostgreSQL non ne crea uno da sé sulle chiavi esterne, quindi filtrare le
  fatture per stato leggeva la tabella intera. `php spark migrate` aggiunge
  ventinove indici (con dodici fatture non si nota, con qualche migliaio sì);
  `migrate:rollback` li toglie.

- **Nuovo logo e nuovo carattere del nome.** Il segno è ora una piastrella blu
  con le righe di una fattura, l'ultima delle quali finisce in un "+"; il nome
  accanto è in Sora al posto del serif. Compaiono nella barra, nella pagina di
  accesso, nel portale clienti, nella favicon e nel logo del README. Sora è
  servito dal sito stesso (`public/assets/core/fonts/`), quindi non richiede
  modifiche alla Content-Security-Policy. Il logo caricato da Impostazioni per
  la pagina di accesso non cambia.

- **Piè di pagina dei documenti più compatto.** Logo, testo dell'azienda e
  numero di pagina stanno ora su una sola riga (prima erano due, centrate e
  fisse in ogni foglio), con il testo allineato a sinistra come il corpo; il
  markup, copiato in tre modelli, è un solo parziale (`layout/pdf_footer.php`).

- **Barra dei mesi**: l'istruzione della selezione multipla è ora una legenda
  su tre voci (clic, `Shift`+clic, `Ctrl/⌘`+clic) invece di tre frasi con lo
  stesso "con… si…" ripetuto. Stesso contenuto in metà del testo, quindi la
  riga resta una sola anche quando la barra si restringe.

- **Risposta al gesto**: pochi movimenti brevi dove l'interfaccia era muta.
  I mesi della barra periodo si alzano di un pixel sotto il mouse e si
  comprimono alla pressione; il mese appena scelto si accende crescendo dal
  94%; i pannelli del select con ricerca e del calendario compaiono invece di
  saltare fuori all'improvviso; la barra delle azioni di gruppo entra dal
  basso quando si spunta la prima casella, e le righe delle liste entrano a
  scaletta (22 ms di scarto, solo le prime otto) quando il contenuto arriva
  dalla navigazione parziale o dal cambio pagina del pager. La scaletta vale
  per tutte le liste, anche quelle in una card semplice, e resta fuori dalle
  tabelle delle righe documento (lì si aggiungono righe mentre si compila) e
  dalla bacheca, che ha già la sua. Tutto in CSS, su `transform` e `opacity`,
  fra 140 e 300 ms, senza nessuna classe aggiunta dal JS, azzerato da
  `prefers-reduced-motion` e spento anche in stampa.

- **Foglio AssoSoftware più sobrio**: la griglia nera piena su ogni cella pesava
  più delle cifre, ora le linee interne sono un grigio freddo e il contorno di
  cedente e committente sta su un pixel; le intestazioni usano una tinta sola
  (prima una era grigio e l'altra ciano), le etichette dei due soggetti sono in
  maiuscoletto e le cifre hanno larghezza fissa, così gli importi restano
  incolonnati anche cambiando le dimensioni dei numeri. In stampa le linee
  tornano nere. Il foglio dell'Agenzia delle Entrate non è toccato.

- **Cambio di pagina e di filtro che non perde il posto**: pager, filtri di
  stato e tipo, barra dei mesi e moduli di ricerca GET non riportano più la
  pagina in cima al documento, con l'elenco fuori vista: si riallinea la cima
  della lista subito sotto le barre fisse, così le righe nuove entrano dove
  stavi guardando e i filtri restano a portata d'occhio. Vale anche tornando
  indietro sulla pagina di un pager; gli altri link si comportano come prima.

- **Intestazione dei documenti allineata.** In fatture, preventivi e DDT il
  logo sta su una riga sua; sotto, i dati di chi emette e quelli del
  destinatario partono alla stessa altezza, ciascuno con la sua etichetta
  («Emittente», «Fattura a»). Prima il logo spingeva in basso l'emittente e il
  riquadro del cliente restava sfalsato.
- **Esportazione con lo stesso impianto di Importazione.** La pagina ha ora i
  passi numerati su un binario (cosa, periodo, formato), ciascuno in una
  scheda con icona e sottotitolo, e in fondo la barra conclusiva col formato
  scelto e il pulsante «Scarica»; la testata tiene solo il ritorno a
  Importa / Esporta, come nella pagina di importazione.
- **Importa / Esporta più asciutta.** Una riga d'introduzione al posto di
  occhiello, slogan e tre righe; un solo pulsante principale per card («Nuova
  importazione», «Esportazione») con la cronologia come strada secondaria; la
  nota sui formati non è più una riga larga quanto lo schermo ma sta, divisa,
  nella card a cui si riferisce.
- **Chiave cron e feed del calendario nelle Impostazioni.** I pulsanti accanto
  ai due campi hanno la misura del campo e lo stile secondario (prima «Genera»
  era un blocco nero più grande del testo); il valore è in monospazio e
  «Copia» conferma con una spunta.
- **Calendario più leggibile.** Oggi è un disco pieno invece di un puntino; i
  giorni passati senza scadenze si attenuano; nella lista laterale i titoli
  vanno a capo invece di essere troncati; la barra di sottoscrizione del feed
  si apre dal pulsante «Sottoscrivi» in testata invece di stare sempre sopra
  la griglia.
- **Cambio mese senza scroll.** Scegliere un mese dalla barra non sposta più
  la pagina: prima la lista veniva allineata sotto le barre fisse e la barra
  dei mesi scappava da sotto il puntatore a ogni clic. Pager e filtri
  continuano a riportare alla cima della lista.
- **Foglio di stile a pezzi.** `custom.css` è ora generato da quindici file in
  `public/assets/core/css/src/`, uniti in ordine da `php tools/minify_css.php`
  (lo stesso comando di prima). Il risultato è identico byte per byte: cambia
  solo dove si scrive. `custom.css` non va più modificato a mano.
- **Istruzioni dei mesi dietro una «i».** La riga «Clic: un mese · Shift+clic…»
  sotto la barra dei mesi non occupa più spazio: resta nascosta e compare al
  passaggio sulla «i», da tastiera o con un clic (Esc o clic fuori la chiudono).
- **Più movimento, dove mancava.** Il "+" del logo ruota al passaggio sul
  brand; la pagina di accesso entra a scaletta; menu utente e sottomenu della
  sidebar si aprono con una breve dissolvenza invece di comparire di colpo.
  Tutto su `transform` e `opacity`, e spento da chi ha `prefers-reduced-motion`.

### Corretto

- **Campo e pulsanti della chiave MCP come negli altri campi segreti.** La
  chiave aveva «Genera» come blocco d'avviso arancione e due pulsanti più
  alti del campo, quindi in quella scheda l'unica azione che si vedeva era
  generare una chiave nuova; l'endpoint aveva una copia di dimensioni diverse.
  Ora il campo usa la stessa forma dei campi segreti delle Impostazioni (chiave
  cron, URL del feed): pulsanti secondari del corpo del campo, valore in
  monospazio perché è una stringa da copiare, e per un attimo l'icona della
  copia diventa una spunta come lì.
- **Il pulsante Copia della chiave MCP non copiava niente.** La chiave è un
  bearer token e per scelta non sta nell'HTML della pagina: dopo averla
  generata e chiusa la scheda non c'era più modo di riprenderla, e l'unica via
  restava rigenerarla — che sconnette tutti i client già configurati. Ora il
  pulsante la chiede al server, la mostra nel campo e la copia: il segreto
  arriva solo quando si preme Copia e solo con la sessione amministrativa
  aperta, e lo snippet di configurazione si aggiorna con la chiave vera invece
  di restare sul segnaposto.
- **Lo stato della chiave MCP dice il vero.** Il campo della chiave mostrava
  sempre «nessuna chiave configurata», anche con una chiave al suo posto — e con
  il badge in cima che diceva «attivo» nella stessa schermata: il testo dello
  stato non arrivava al campo. Ora lo stato lo calcola il campo stesso, legge
  anche la chiave messa in `mcp.key` dentro `.env` (quella che il server
  confronta, prima ignorata) e manda a copiare la chiave che c'è invece di
  rigenerarla.
- **Ogni azienda ha il suo anno, e non lo perde più.** Il filtro anno della
  sidebar era uno solo per tutte le aziende e veniva azzerato a ogni cambio
  azienda: chi lavorava su due soggetti — su una chiudeva il 2025, sull'altra
  emetteva il 2026 — si trovava il periodo cambiato sotto le mani ogni volta.
  Ora il periodo è di ciascuna azienda: cambiando azienda si riprende l'anno in
  cui si lavorava lì, e tornando indietro lo si ritrova com'era; la scelta vive
  in sessione e in un cookie di un anno, quindi sopravvive anche a logout e
  chiusura del browser. Un anno rimasto in memoria ma ormai senza documenti in
  quell'azienda torna a "Tutti gli anni" invece di mostrare un archivio vuoto,
  che sembrerebbe un cambio non riuscito. Chi ha il cookie vecchio (un anno
  solo) non perde nulla: viene attribuito all'azienda in cui rientra.
- **Calendario: settimana all'italiana.** La griglia del mese si apriva con la
  domenica in prima colonna: l'impostazione arrivava dal seme di InvoicePlane
  (domenica primo giorno) e nessuno l'aveva scelta. Ora la settimana comincia
  di lunedì e la domenica chiude la griglia, come si legge un calendario in
  Italia; la migrazione cambia il valore solo se è ancora quello di partenza,
  e la stessa impostazione governa i selettori di data. Chi preferisce la
  domenica la reimposta da Impostazioni → Primo giorno della settimana.
- **Piano dei conti: azioni di riga e intestazione.** Ogni voce aveva due
  pulsanti affiancati (modifica, elimina) diversi da tutte le altre liste, che
  usano un solo pulsante «Opzioni» con il menu: con nomi o note lunghe la coppia
  finiva oltre il bordo destro della scheda. Ora anche qui c'è il menu Opzioni,
  con modifica ed eliminazione e la stessa richiesta di conferma delle altre
  tabelle, e la colonna resta stretta sul pulsante. Con loro sparisce
  l'intestazione che galleggiava dentro le righe (la card era diventata un
  contenitore di scorrimento, e l'intestazione fissa si ancorava a quello invece
  che alla pagina).
- **Favicon**: era l'ultima icona disegnata a parte — due righe invece di tre e
  una croce più grande, per reggere i 16px della scheda del browser — e non
  coincideva con il marchio della barra né con l'icona dell'app installata. Ora
  è lo stesso segno — lo stesso file di disegno delle icone dell'app, con il
  PNG rigenerato dall'SVG — ricentrato sul proprio ingombro come loro.
- **Icona dell'app installata**: le icone della PWA erano un disegno a parte
  rispetto al marchio — due righe invece di tre, la croce che usciva dal bordo
  della piastrella e l'icona Apple già arrotondata, che iOS arrotondava una
  seconda volta. Ora nascono tutte dallo stesso segno dell'app (tre righe
  sfumate e croce dentro la piastrella), con la versione *maskable* raccolta nel
  72% centrale per non farsi tagliare dalla maschera di Android; i due disegni
  stanno accanto ai PNG, con il comando per rigenerarli. La versione della
  cache del service worker cambia, così l'icona nuova arriva anche a chi ha già
  installato l'app. Il disegno è anche ricentrato sul proprio ingombro: nella
  barra, accanto al nome, la croce che sborda in basso a destra non si nota, ma
  in una piastrella da sola faceva sembrare tutto il segno spostato a destra.
  Ora i margini sono uguali sui quattro lati (84px per lato a 512).
- **Logo troppo piccolo nei PDF**: un SVG con `width`/`height` diversi dalla
  `viewBox` (tipico dei file esportati da Illustrator o Inkscape) usciva a circa
  un quarto della grandezza, qualunque fosse lo stile. Ora le dimensioni
  dichiarate vengono allineate alla `viewBox` prima di incorporare il logo.
- **Eliminare un progetto dalla sua scheda**: il pulsante «Elimina» portava a
  una pagina 404 dopo la conferma (era un link, e l'eliminazione accetta solo
  richieste POST). Ora è un modulo, come nella scheda cliente; un test
  impedisce che altre viste ripetano l'errore.
- **README e guida di installazione.** Il logo nuovo in testata (con variante
  per il tema scuro di GitHub) e nella copertina; elenco delle funzioni, passi
  di installazione e comandi di sviluppo aggiornati; nuova guida
  [Installazione su un sottodominio](docs/installazione-su-un-sottodominio.md)
  con configurazione nginx e Apache, `.env`, cron e verifiche.
- **Pagine delle impostazioni dopo la navigazione parziale.** In Impostazioni,
  Fattura elettronica, Utenti, assegnazione clienti, Integrazione MCP e nella
  scelta delle attività, gli script partivano con `DOMContentLoaded`: aprendo la
  pagina dal menu non si agganciavano e «Genera», «Copia» e i campi collegati
  non rispondevano fino a un ricaricamento. Ora usano `ipReady()`; un test
  impedisce che torni il listener nudo nelle viste.
- **Cache degli asset.** `sw.js` non cade più nella cache di un anno del
  `.htaccess` (il service worker deve poter essere aggiornato), e favicon,
  icona per iPhone, logo della pagina di benvenuto e `zxcvbn.js` portano
  `?v=` come gli altri asset, così un'icona o un logo cambiati arrivano
  anche a chi li aveva già in cache.
  Anche i font richiamati dai fogli di stile (Sora e le icone) portano
  l'impronta del file nell'indirizzo: rigenerando il sottoinsieme delle icone,
  chi aveva il file vecchio vede subito quelle nuove. Il collaudo con Apache
  verifica ora anche l'intestazione di `sw.js`.
- **Pulsanti delle conferme**: «Annulla» e «Esci senza salvare» (e le altre
  conferme di eliminazione) erano a 16px, più grandi dei pulsanti del resto
  dell'app; ora hanno la misura dei pulsanti piccoli.
- **Iniziali nella scheda cliente**: «Comune di Monte Alto» mostrava
  «CD», con la preposizione al posto del nome. Articoli e preposizioni ora si
  saltano, le virgolette non contano come iniziale e per le persone si
  prende un'iniziale dal nome e una dal cognome.
- **Saltino del menu utente**: aprendo il menu dell'utente (e i menu di riga)
  nella barra in alto il pannello faceva un piccolo salto. L'animazione di
  comparsa toccava `transform`, che Popper usa per posizionare il menu; ora i
  menu a tendina usano la sola dissolvenza.

- **Errore di schema spiegato**: quando manca indirizzo, CAP o comune di
  cedente o cessionario, la verifica del tracciato mostrava solo il messaggio
  della XSD ("Elemento 'Nazione': non atteso"), che parla dell'elemento
  presente e non di quello mancante, e non dice a chi manca. Ora la pagina
  dice quale scheda aprire — cliente o azienda, con nome e collegamento — e
  lo stesso controllo blocca l'invio allo SdI prima della validazione, con un
  messaggio che dice cosa compilare. La fattura Carta del Docente resta fuori
  dal controllo del cliente: il destinatario del file è l'ufficio del
  Ministero, con indirizzo fisso.

- **Anteprima AssoSoftware leggibile**: il foglio dichiara 800 px fissi per
  ogni tabella, quindi su una pagina da 1000 metà destra restava bianca e le
  colonne dei totali, larghe 50–100 px, mandavano "Data documento" e "Prezzo
  unitario" a capo su due righe. Ora le tabelle occupano la pagina, le cifre e
  le loro intestazioni stanno sullo stesso lato, le righe di una tabella lunga
  hanno un separatore (prima il bordo stava solo sul corpo tabella, quindi due
  articoli si leggevano come una riga sola) e l'intestazione con cedente e
  committente non esce più dal bordo, perché l'altezza fissa delle due celle è
  stata tolta. Le regole della pagina erano scritte per un foglio che non ha
  `#fattura-container`: condizione mai vera, quindi non si applicavano mai e
  l'anteprima usciva senza margine in cima. Il foglio dell'Agenzia delle
  Entrate non è toccato: nessuno dei selettori nuovi compare nel suo markup.

- **Leggibilità**: le etichette sopra le cifre (riepiloghi delle liste, KPI
  della bacheca, "Esporta", legenda del calendario) passano da 10 a 11 px e,
  nei riepiloghi, a un grigio più scuro che rispetta il contrasto minimo. Le
  righe degli stati a zero in bacheca e nelle anagrafiche restano spente ma
  non sono più quasi illeggibili (da 2,2:1 a oltre 4,5:1).
- **Telefono**: la barra delle sezioni del Controllo di gestione mostra
  un'ombra sul lato da cui ci sono altre voci da scorrere.
- **Righe di fatture e preventivi**: su schermi da 1280 px i campi Codice,
  Quantità, Prezzo e Sconto erano più corti del loro contenuto ("1.234,00"
  usciva tagliato). Le colonne sono state ribilanciate: ogni campo contiene il
  suo valore tipico e lo spazio in più va alla descrizione. L'intestazione
  Quantità è abbreviata in "Q.tà".
- **Liste**: fra 1280 e 1399 px la colonna Cliente di fatture, preventivi e
  spese andava a capo anche con nomi brevi; in quella fascia esce la colonna
  secondaria non numerica (data di creazione, categoria) e il nome sta su una
  riga.
- **Accessibilità**: il filtro delle Impostazioni e i campi Articolo e
  Descrizione delle righe di fatture e preventivi hanno ora un nome leggibile
  dagli screen reader.

## [v0.10.0] — 2026-11-14

**Da fare prima di aggiornare:** `php spark migrate` (aggiunge indici e, se
esistono righe anteriori al multi-azienda, assegna quelle senza azienda
all'azienda predefinita: vedi "Dati senza azienda" qui sotto) e un controllo di
`php spark companies:orphans`: da questa versione le righe senza azienda non
compaiono più in nessuna lista, quindi il totale deve essere 0. In produzione la
Content-Security-Policy è ora applicata:
controlla che `app.baseURL` coincida con l'indirizzo del sito e, se una pagina
non funziona, vedi "Header di sicurezza e Content-Security-Policy" in
`docs/installazione-e-aggiornamento.md`.

### Aggiunto

- **Backup dei file caricati**: il giro notturno di `cron/backup` salva ora
  anche `writable/uploads` (XML delle fatture ricevute, allegati, F24, loghi)
  in `files_AAAAMMGG_HHMMSS.zip`, con permessi 0600 e gli ultimi 14. Il dump
  del database non li conteneva: perdere il server voleva dire perdere gli
  originali. Se i file falliscono e il database no, la richiesta non risponde
  500 (il runner rifarebbe l'intero backup a ogni giro e spingerebbe fuori i
  dump buoni): arriva un'email e "Stato del sistema" mostra il nuovo controllo
  "Backup dei file". `.env` e chiave di cifratura non sono nell'archivio, di
  proposito: vanno custoditi a parte.
- **Avvisi per email dallo SdI**: scarto, mancata consegna e impossibilità di
  recapito alla PA arrivano ora al titolare con il motivo e cosa fare, e una
  volta sola anche se lo SdI ripete la notifica. Prima l'unica traccia era la
  riga nella lista fatture.
- **Controllo "Certificato SDICoop" in Stato del sistema**: avvisa 45 giorni
  prima della scadenza del certificato client e segnala come guasto uno
  scaduto, che altrimenti ferma invio e ricezione senza un errore visibile.
- **Fatture bloccate e "Sblocca"**: vedi sotto, in "Corretto".
- **Controllo "Fatture ricevute dallo SdI" in Stato del sistema**: lo SdI
  considera consegnata una fattura appena l'endpoint risponde e non la
  rimanda; se l'importazione non riusciva (partita IVA del committente che non
  corrisponde a nessuna azienda, XML illeggibile) il file restava in
  `writable/uploads/expenses/incoming` e l'unica traccia era una riga nel log.
  Ora la pagina avvisa e dice quale file importare da Spese > Importa XML.
- **Header di sicurezza**: ogni risposta porta `X-Frame-Options`,
  `X-Content-Type-Options` e `Referrer-Policy`. Le anteprime nelle pagine sono
  dello stesso sito e non ne risentono.
- **Content-Security-Policy applicata in produzione**: `app.cspMode` in `.env`
  (`off`, `report`, `enforce`). Il predefinito è `enforce` in produzione e
  `report` (segnala senza bloccare) altrove; un valore sbagliato vale `report`.
  La policy ammette solo lo stesso sito e Google Fonts, chiude plugin, form
  verso altri siti, richieste verso altri host e incorporamento in siti
  esterni. Verificata su circa 48 schermate con i dati demo, senza violazioni.
- **`app.cspReportUri`**: indirizzo facoltativo a cui il browser spedisce una
  segnalazione per ogni violazione della policy, anche in `enforce`.

### Modificato

- **Ricezione fatture di acquisto: dichiarazioni allineate allo stato reale**.
  L'endpoint non è uno scheletro: ricezione, controllo del token, salvataggio
  del file, scelta dell'azienda dal committente e importazione come spesa sono
  realizzati, provati in locale con uno SdI simulato e ora coperti da test
  automatici (token sbagliato o assente, busta illeggibile, committente
  sconosciuto, doppia consegna, nome file con percorsi, decorrenza termini).
  Resta da provare con lo SdI vero, che richiede l'accreditamento SOGEI. Nelle
  impostazioni sono spariti i due campi per l'esito del committente
  (SdIRiceviNotifica), che nessuna parte del programma leggeva: serve solo per
  la PA e non è implementato.
- **Liste e ricalcolo dei totali più rapidi su PostgreSQL**: le tabelle di
  importi, righe, aliquote, pagamenti, ricorrenze e allegati hanno ora un
  indice sulla colonna che le lega al documento. Su MariaDB esistevano già
  (li crea il vincolo), su PostgreSQL no: ogni lista scandiva quelle tabelle
  per intero, e il costo cresceva con l'archivio.
- **Preventivi e pagamenti a pagine**: le due liste rispettano ora "Numero di
  elementi nelle liste" come fatture, spese e clienti, con i numeri di pagina
  in fondo. Prima caricavano l'intero archivio a ogni apertura. Il conteggio
  accanto al titolo e i totali del periodo riguardano sempre tutto l'elenco;
  la ricerca continua a cercare in tutto l'archivio, non solo nella pagina.
- **Meno query a ogni pagina**: gli anni del selettore in alto vengono letti
  una volta per pagina invece che a ogni controllo dell'anno scelto.

- **Una sola azione in evidenza nelle pagine di modifica**: in fattura e
  preventivo "Anteprima" diventa secondario e resta in evidenza solo "Salva";
  prima convivevano un pulsante blu e uno verde.
- **Pulsanti più grandi sui dispositivi touch**: con tablet e telefono i
  pulsanti compatti salgono a 44 px di altezza; con il mouse restano come
  prima.
- **Stati con lo stesso aspetto ovunque**: i badge "Numero provvisorio",
  "Predefinito", "Non impostata", gli esiti di invio email e dei log di
  pagamento, l'approvazione del preventivo nel portale cliente e lo stato
  dell'integrazione MCP usano ora il timbro di stato delle liste, con testo
  sopra la soglia di contrasto.
- **Bordo dei campi più marcato**: il contorno di campi e menu sul fondo grigio
  della pagina era a 3,01:1, appena sopra il minimo; ora è a 3,24:1.

### Sicurezza

- **La CSP non può più perdere gli script in linea per un nonce**: il
  framework, con il debug acceso, chiede un nonce a ogni richiesta, e un solo
  nonce nell'header fa ignorare `'unsafe-inline'` ai browser, cioè smettere di
  eseguire gli script di tutte le pagine. Il servizio CSP dell'app non emette
  più nonce.
- **Il secondo fattore non si può più provare all'infinito**: il tetto di 5
  codici sbagliati era nella sessione e si azzerava a ogni accesso con password
  riuscito, quindi chi conosceva la password poteva ripartire dal login e
  continuare a provare. Ora c'è anche un contatore per utente (10 codici
  sbagliati in 15 minuti, validi per codice dell'app e codici di recupero) che
  si azzera solo con un codice giusto o con il tempo. Conseguenza: chi ha la
  password può bloccare per un quarto d'ora il secondo fattore del titolare.
- **Cookie `Secure` con HTTPS forzato**: `app.forceGlobalSecureRequests`
  faceva il reindirizzamento ma lasciava il cookie di sessione senza
  l'attributo, e un browser lo avrebbe spedito anche su una richiesta in
  chiaro. Ora il cookie è `Secure` quando l'HTTPS è forzato.
- **La password dei documenti guest non è più reversibile**: era cifrata con
  `encrypt_secret()`, quindi un dump o un backup del database la restituiva in
  chiaro. Ora è un hash bcrypt, verificato con `password_verify()`; i valori
  vecchi vengono convertiti al primo sblocco riuscito, senza chiedere di nuovo
  la password al cliente. Nelle schede di fattura e preventivo il campo è
  write-only: la password salvata non è più visibile e lasciarlo vuoto non la
  cancella. L'integrazione MCP non restituisce più l'hash.
- **Il portale guest non accetta più tentativi illimitati**: cinque password
  sbagliate in quindici minuti per lo stesso documento e indirizzo bloccano il
  modulo con un messaggio generico. Il contatore sta nella cache, si azzera al
  primo sblocco riuscito ed è separato per fatture e preventivi.
- **La chiave dei cron è cifrata a riposo e non si vede più**: `cron_key` era
  salvata in chiaro in `ip_settings` e la pagina Impostazioni mostrava il
  ciphertext. Ora è cifrata come i token SdI, il campo è write-only (vuoto =
  non modificare) e la chiave si vede una sola volta, subito dopo averla
  generata.
- **Canale SDI in ingresso: limiti e niente sovrascritture**: body SOAP/MTOM
  fino a 20 MB, singolo XML fino a 10 MB, ZIP con al massimo 50 voci, 50 MB
  decompressi e rapporto di compressione 100:1; i limiti si controllano prima
  di decomprimere. Un `NomeFile` ripetuto non sovrascrive più la copia
  conservata: se il contenuto è identico si riusa, altrimenti si salva con un
  suffisso d'hash; la notifica SDICoop duplicata non riapplica l'esito né
  rimanda l'avviso. `FatturaXml::validate()` usa `LIBXML_NONET`. Il canale API
  di invio rifiuta endpoint non HTTPS, non segue redirect e non mette URL o
  credenziali nei log. La configurazione richiesta al webserver (mTLS,
  allowlist IP SOGEI, limiti) è ora documentata in `docs/sdicoop.md`.
- **Export CSV/XLS: neutralizzate le formule**: le celle che iniziano con `=`,
  `+`, `@`, TAB o CR (e con `-` quando non sono numeri) vengono prefissate con
  un TAB, così Excel le tratta come testo; gli importi negativi restano
  numeri e il giro esporta → reimporta resta coerente.
- **Endpoint pubblici: tetto alle richieste**: `/mcp`, i quattro indirizzi
  `cron/*`, `sdi-coop/*` e `sdi-ricezione/*` non hanno sessione e non avevano
  un limite, quindi i tentativi con token sbagliato si potevano ripetere a
  volontà. Ora c'è un contatore per indirizzo IP e per gruppo di endpoint
  (120 richieste al minuto per MCP, 30 per i cron, 300 per lo SdI, generoso
  perché i lotti arrivano ravvicinati); oltre il tetto la risposta è 429 con
  `Retry-After`, prima ancora del controllo del token, e senza rivelare il
  limite esatto. Il contatore sta nella cache, così sopravvive alla sessione.
- **La chiave dei cron non viaggia più solo nell'URL**: oltre al vecchio
  parametro nel percorso (mantenuto per i cron già installati) si accettano
  gli header `X-Cron-Key` e `Authorization: Bearer`, che le tracce del
  webserver non registrano come un indirizzo. Nessun messaggio di log
  riporta più la chiave.
- **La notifica SDICoop è legata all'azienda giusta**: l'esito veniva cercato
  solo per nome del file, quindi un nome uguale poteva aggiornare la
  trasmissione di un'altra azienda. Ora l'azienda si ricava dalla partita IVA
  nel nome (`IT<partita IVA>_<progressivo>.xml`) e, se restano più
  corrispondenze o nessuna, la notifica viene ignorata e registrata nel log
  invece di scrivere l'esito sulla fattura sbagliata.
- **Import e allegati: tipo e dimensione controllati davvero**: ogni punto di
  ingresso si fidava dell'estensione dichiarata dal browser, quindi un PDF
  rinominato `.csv` passava e un file enorme veniva letto in memoria. Ora un
  unico controllo (`UploadGuard`) verifica il tipo reale con `finfo`, applica
  i limiti (5 MB per i CSV, 10 MB per XML, PDF e allegati) e vale per fatture,
  estratti conto, clienti, fornitori, spese, F24 e l'importazione delle
  fatture emesse. `StatementImporter` ha un tetto di 10 MB anche quando è
  chiamato da codice futuro. La chiave negli indirizzi degli allegati deve
  essere di 32 caratteri esadecimali e corrispondere a un documento
  dell'azienda attiva.
- **La chiave MCP non si vede più in pagina**: la schermata Integrazione MCP
  mostrava il ciphertext salvato. Ora il campo parte vuoto, spiega che la
  chiave non è più visibile e la mostra in chiaro una sola volta, subito dopo
  la generazione, con un pulsante per copiarla. Come per la chiave dei cron,
  ricaricare la pagina non la espone di nuovo.
- **Firma digitale delle fatture ricevute**: i `.p7m` venivano estratti senza
  controllare la firma. Ora la busta CMS viene verificata con i certificati
  che contiene (integrità di digest e firma): una busta manomessa viene
  rifiutata, una con firmatario scaduto viene importata ma segnalata, una che
  OpenSSL non riesce a interpretare — le buste SOGEI a lunghezza indefinita —
  viene accettata con un avviso nel risultato e nel log. Attendibilità della
  CA e revoca restano fuori: quelle le coprono mTLS e accreditamento SOGEI.
- **Gli errori interni non escono più verso l'esterno**: `/mcp` e il backup
  cron restituivano il messaggio dell'eccezione, con possibili nomi di tabelle,
  `SQLSTATE` e percorsi. Ora il client riceve un messaggio generico (per MCP
  con un identificativo di correlazione) e il dettaglio resta nel log e
  nell'email all'amministratore.
- **Dati senza azienda: diagnosi e riassegnazione**: le righe per-azienda con
  `company_id` NULL erano visibili a tutte le aziende. Arrivano un comando
  `php spark companies:orphans` (sola diagnosi; `--fix` riassegna all'azienda
  predefinita), un controllo "Dati senza azienda" in Stato del sistema e una
  migrazione idempotente che copre anche le tabelle nate dopo il primo
  backfill (`ip_suppliers`, `ip_imports`, `ip_costing_accounts`,
  `ip_costing_budgets`). Con il conteggio a zero l'eccezione `company_id IS
  NULL` è stata tolta dalle query (vedi "Perimetro azienda senza eccezioni"):
  una riga che restasse senza azienda non comparirebbe più in nessuna lista, e
  il controllo in Stato del sistema la segnala. Guida in
  `docs/dati-senza-azienda.md`.
- **Perimetro azienda senza eccezioni**: `company_scope_sql()`,
  `scope_builder_to_company()`, `scopeToCompany()`, la ricerca globale, i
  report e i punti che ripetevano la condizione filtrano ora solo per
  `company_id = N`. Restano condivisi, di proposito, gli anni e gli scaglioni
  fiscali con `company_id NULL`, che `FiscalYearModel::scopeEffective()` e
  `FiscalBracketModel::ofCompany()` leggono come modello comune. Effetto:
  dopo il backfill (0 orfani) nessun dato reale sparisce, e un futuro errore
  di scrittura senza azienda diventa visibile invece di finire nei totali di
  tutte le aziende.
- **La coda Redis non deserializza classi arbitrarie**: `RedisQueue::pop()`
  accetta solo `SendEmailJob` e `GenerateFatturaJob`. Un Redis compromesso non
  può più far istanziare al worker classi qualsiasi.
- **Endpoint SDICoop solo in HTTPS**: `sdi_coop_endpoint` viene validato come
  l'endpoint API del trasmittente (schema `https`, URL con host) e curl è
  limitato a HTTPS. Prima un indirizzo `http://` avrebbe spedito XML e
  credenziali in chiaro.
- **Sessione: il vecchio ID muore al ricambio**: con
  `regenerateDestroy = false` l'identificativo precedente restava valido fino
  alla scadenza (due ore) dopo login o secondo fattore. Ora viene distrutto.
- **Reset password con un tetto**: cinque richieste per indirizzo e email in
  un'ora. Prima si poteva far arrivare email a ripetizione a una casella e
  usare la pagina per capire quali indirizzi sono registrati.
- **I tag delle email non portano HTML nel messaggio**: `{{tag}}` viene
  escapato (nome cliente, ragione sociale e altri dati sono testo), mentre
  `{{{tag}}}` resta la sintassi esplicita per inserire markup. Prima un nome
  contenente `<a href=…>` costruiva contenuto attivo nell'email.
- **Permessi più stretti per allegati e log**: la cartella degli allegati
  nasce `0755` (era `0777`) e i file di log `0640` (erano `0644`): contengono
  dati di documenti e clienti.
- **Uscita dalla sessione in POST**: il link "esci" della barra superiore è
  ora un form con token CSRF. Una GET la poteva far partire anche un link di
  terzi o un prefetch, senza un clic vero.
- **Eliminare un'azienda non lascia più dati appesi**: verso `ip_companies`
  non c'è chiave esterna, quindi cancellare la sola riga lasciava clienti,
  fatture e serie con un `company_id` inesistente, invisibili a tutti. Ora
  l'eliminazione viene rifiutata se l'azienda ha righe collegate e propone di
  disattivarla; il controllo usa lo stesso elenco di tabelle di
  `companies:orphans`.
- **Archivio fatture per azienda**: l'elenco e il download in
  `invoices/archive` accettano solo i PDF il cui nome termina con il numero di
  una fattura dell'azienda attiva. La cartella è comune e i nomi non
  contengono l'azienda: prima si poteva scaricare il PDF di un altro soggetto
  giuridico.
- **Esportare le fatture non le segna più come inviate**: `generate_pdf` è una
  GET e con `mark_invoices_sent_pdf` attivo numerava e marcava la fattura come
  inviata; l'export ZIP, che genera i PDF in blocco, le spediva tutte senza
  che nessuno lo chiedesse. La marcatura ora ha un POST esplicito ("Segna come
  inviata" nella scheda del documento) per fatture e preventivi.

### Corretto

- **Gli avvisi di importazione non sparivano più nel nulla**: i messaggi di
  tipo avviso (per esempio le righe saltate durante un import) non avevano un
  toast e non venivano mostrati da nessuna parte. Ora compaiono come gli altri,
  con il contenuto escapato.

- **I toast non eseguono più HTML proveniente dai dati importati**: il
  messaggio veniva stampato senza escaping, quindi un file XML o CSV
  artigianale poteva eseguire JavaScript nella sessione dell'amministratore
  che lo importava (nome del file, P.IVA del committente, errori di
  validazione). Ora ogni messaggio viene escapato e solo i fine riga diventano
  a capo; i messaggi multi-riga delle impostazioni restano su righe separate.
- **La "sola lettura" delle fatture non bloccava niente**: il flag veniva
  scritto (dall'importazione, dall'impostazione "Imposta in sola lettura") ma
  nessuna pagina lo leggeva, quindi una fattura "bloccata" si poteva
  modificare, svuotare delle righe, intestare a un altro cliente o far
  riscrivere dall'integrazione MCP. Ora lo rispettano la modifica, il
  salvataggio, righe, imposte, riordino, cambio cliente, eliminazione (anche
  di gruppo) e l'MCP. Restano possibili pagamenti e note di credito.
  **Effetto sui tuoi dati**: le fatture importate da un altro gestionale
  sono segnate in sola lettura e ora lo sono davvero; per correggerne una c'è
  il pulsante **Sblocca** nella scheda.
- **Una fattura trasmessa allo SdI ora si blocca**: l'invio (canali PEC, API,
  SDICoop) e ogni esito che dice che lo SdI la ha (consegna, mancata consegna,
  notifica, decorrenza termini) la mettono in sola lettura; lo scarto la
  sblocca per correggerla e rinviarla. Con il canale manuale si blocca quando
  registri l'esito. Prima si poteva cambiare una fattura già inviata senza
  accorgersi che l'app e lo SdI non coincidevano più. "Sblocca" non è
  consentito se lo SdI la ha per buona: si emette una nota di credito.
- **L'opzione "Bozza" dell'impostazione di sola lettura bloccava le bozze**
  appena ricalcolate: ora il blocco per stato parte dalla prima emissione.
- **Categoria spesa predefinita della ricezione SdI mai applicata**: il campo
  in Impostazioni > Fattura elettronica > Ricezione si salvava ma nessuno lo
  leggeva, quindi le fatture che arrivano dallo SdI entravano sempre senza
  categoria, proprio quello che il testo sotto il campo diceva di evitare. Ora
  si applica; una categoria cancellata nel frattempo vale "nessuna" e non
  blocca l'importazione.
- **Estratti conto CAMT.053 letti come vuoti**: un file nella forma più comune,
  con `<Document xmlns="urn:iso:...">` senza prefissi, veniva importato con zero
  movimenti e nessun errore, perché il lettore toglieva i prefissi ma non il
  namespace predefinito. Gli estratti con prefisso (`ns2:`) funzionavano.
- **Giorni di ritardo sbagliati su MariaDB**: i giorni trascorsi dalla scadenza
  erano calcolati sottraendo le date come numeri, quindi a cavallo di un cambio
  di mese risultavano gonfiati (dal 28 gennaio al 3 febbraio: 75 invece di 6).
  Il report di anzianità dei crediti metteva le fatture nella fascia sbagliata.
  "Scaduta sì/no" era già corretto; su PostgreSQL il difetto non c'era.
- **Automazioni con più aziende**: nel giro delle fatture ricorrenti le aziende
  dopo la prima usavano le impostazioni della prima (per esempio la serie di
  numerazione), perché l'azienda attiva restava in memoria dall'inizio del
  giro.
- **Testi di avviso poco leggibili**: l'avviso arancione nella pagina del
  secondo fattore e la spunta verde negli elenchi di importazione ed
  esportazione usano ora la tonalità scura prevista per il testo, sopra la
  soglia di contrasto 4,5:1 (prima 2,1:1 e 4,3:1).
- Il badge "Attivo" del secondo fattore nella scheda utente ha lo stesso
  aspetto degli altri stati.

## [v0.9.1] — 2026-11-12

### Corretto

- **Il secondo fattore non rifiuta più codici giusti per pochi secondi di
  scarto**: la verifica accetta due minuti di differenza fra l'orologio del
  server e quello del dispositivo, invece di un minuto. Con una deriva di 30-60
  secondi alcuni tentativi fallivano senza motivo e bisognava aspettare il
  codice successivo. Se lo scarto è più ampio, il messaggio dice di quanto e
  invita ad attivare data e ora automatiche.
- **Un codice già usato non conta più come tentativo sbagliato**: chi conferma
  il secondo fattore e prova subito ad accedere non consuma i cinque tentativi
  e riceve un messaggio che spiega di attendere il codice nuovo. La conferma
  del setup non segna più il codice come "già consumato".

## [v0.9.0] — 2026-11-12

### Aggiunto

- **Prova in locale con dati demo**: guida e script per macOS, Linux e Windows
  che creano un database separato (`invoiceplane_demo`), caricano dati
  inventati e avviano l'applicazione su localhost. Non serve Redis: per la
  prova cache e sessioni usano file. Non tocca il database di lavoro.

### Modificato

- **Email con alternativa testuale**: oltre alla parte HTML parte anche la
  versione `text/plain`, così i client che non mostrano HTML leggono il
  messaggio invece del solo avviso. Il pixel di tracciamento resta nella parte
  HTML: la versione testuale non viene conteggiata.

## [v0.8.0] — 2026-11-12

### Aggiunto

- **Indicatore delle email nelle liste**: fatture e preventivi mostrano sotto
  il numero l'esito dell'ultimo invio — "Aperta" con la data della prima
  apertura, oppure "Non aperta" — così non serve aprire ogni documento per
  sapere quali email sono state lette. Il dettaglio nel tooltip riporta
  destinatario e numero di aperture; i documenti mai inviati non hanno nota.

## [v0.7.1] — 2026-11-12

### Aggiunto

- **Pulizia del tracciamento email**: il cron settimanale elimina le righe più
  vecchie di un anno e quelle di fatture o preventivi eliminati. Prima la
  tabella cresceva a ogni invio e non calava mai, e i documenti cancellati
  lasciavano righe che nessuna pagina poteva più mostrare.

### Modificato

- **Privacy del tracciamento**: l'indirizzo IP viene salvato in forma anonima
  (rete, non singolo host) e lo user agent non viene più memorizzato. Erano
  dati personali che nessuna schermata mostrava.
- **"Prima apertura il …"** invece di "Aperta il …": il pixel dice quando è
  stata rilevata la prima richiesta dell'immagine, non quando la persona ha
  letto il messaggio.

## [v0.7] — 2026-11-12

### Aggiunto

- **Tracciamento delle aperture email** per fatture e preventivi: le email
  portano un pixel invisibile e la pagina del documento mostra gli invii con
  data, destinatario e apertura. Il conteggio è indicativo, perché chi blocca
  le immagini non carica il pixel e alcuni client le scaricano in anticipo;
  la nota è scritta accanto all'elenco. Si spegne da **Email e mittenti**. Se
  il tracciamento non è raggiungibile l'email parte lo stesso, e un invio
  fallito non lascia righe nella pagina del documento.

### Modificato

- **Le email partono in HTML**: i a capo del testo semplice non si perdono e i
  caratteri speciali (`&`, `<`) non vengono interpretati come markup. Prima il
  corpo era inviato così com'era, con i `<br>` del testo semplice visibili in
  alcuni client.

## [v0.6] — 2026-11-12

### Aggiunto

- **Selezione multipla dei mesi** spiegata sotto la barra del periodo in
  fatture, preventivi e spese: un clic sceglie un mese, Shift+clic estende
  l'intervallo, Ctrl/⌘+clic aggiunge o toglie un mese. La funzione c'era già,
  ma senza istruzioni restava invisibile. La riga compare solo con JavaScript
  attivo, perché senza script i mesi sono link singoli.

### Modificato

- **Stato del sistema** separa i controlli che richiedono un intervento da
  quelli regolari: i primi stanno in cima con il comando suggerito in evidenza,
  gli altri in un gruppo a parte. Prima dieci righe dello stesso peso chiedevano
  la stessa attenzione e un guasto si perdeva tra i "regolare".

### Corretto

- **Polling SdI**: quando la casella IMAP non è configurata il job si ferma
  senza scrivere un errore nel log. Prima `cron/sdi_poll` registrava un ERROR a
  ogni giro (ogni 15 minuti) per l'estensione `php-imap` mancante, anche se il
  canale SdI non usava la PEC.
- **Migrazione fornitori**: `BackfillSuppliers` inserisce `true` nella colonna
  `supplier_active`. Su PostgreSQL l'inserimento di `1` in una colonna boolean
  faceva fallire le migrazioni di un database nuovo.
- **Log del download del backup**: riporta il nome del dump scaricato. Il
  segnaposto `{file}` è riservato dal logger di CodeIgniter e veniva sostituito
  con il file del controller (`APPPATH/Controllers/SystemStatus.php`).

### Sicurezza

- **Cron key fuori dal repository**: `tools/cron-runner.sh` legge `BASE_URL` e
  `CRON_KEY` da `writable/cron-runner.conf`, che non è versionato; i file
  tracciati restano con i segnaposto. La chiave non finisce più in un commit.

## [v0.5] — 2026-11-12

### Aggiunto

- **Invia fattura** e **Invia preventivo** per email dalla pagina del documento
  e dall'elenco: apre la schermata con template, mittente, destinatari e PDF
  allegabile. Il Mailer c'era già, ma ci si arrivava solo dallo scadenzario e
  dal sollecito, quindi dalla fattura sembrava che la funzione non esistesse.

## [v0.4] — 2026-11-12

### Aggiunto

- **Gestione > Stato del sistema**: il controllo "Backup del database" offre
  **Scarica l'ultimo backup**, per portare la copia fuori dal server. Il file
  è sempre l'ultimo dump valido (niente percorsi nell'URL), il pulsante compare
  solo se esiste e il download resta riservato agli amministratori.
- Un backup fallito manda una **email** alla casella di sistema (o al primo
  amministratore): prima restava solo nel log e in un HTTP 500. Il freno è di
  dodici ore, azzerato dal primo backup riuscito.
- **Test nel browser** con Playwright (login, tema chiaro/scuro persistente,
  ricerca nelle liste, Ctrl+K) e job dedicato in CI.
- **Misura della copertura** in CI, con soglia minima sotto il valore attuale
  per intercettare un crollo senza bloccare i refactoring.
- `backup.pgDumpPath` e `backup.mysqldumpPath` sono nel `env` di esempio, dove
  chi installa li cerca, oltre che nella guida.

### Modificato

- **Gestione > Stato del sistema**: riepilogo in testa con esito complessivo e
  conteggi per stato; i controlli sono una lista con icona, etichetta,
  dettaglio e suggerimento a tutta larghezza, invece di una tabella a tre
  colonne. Guasti e avvisi salgono in cima; in testa ci sono l'orario del
  rilevamento e il pulsante "Aggiorna".

### Corretto

- Il dettaglio delle migrazioni diceva "1 migrazioni da applicare": ora dice
  "1 migrazione".
- **Errori nel log** leggeva solo gli ultimi 200 KB del file: in una giornata
  piena un errore scritto la mattina restava invisibile e la pagina diceva
  "nessun errore". Ora scorre tutto il file una riga per volta e indica l'ora
  dell'ultimo errore; un log non leggibile è un avviso, non un "tutto ok".

### Sicurezza

- I dump nascono con permessi **0600** invece di 0644: contengono dati
  personali e fiscali e non devono essere leggibili da ogni utente locale.
- Un test verifica che `custom.min.css` corrisponda alla minificazione fresca di
  `custom.css`: in produzione si serve il compilato, e senza questa guardia una
  modifica al foglio poteva non arrivare mai alla pagina.

## [v0.3] — 2026-11-12

### Aggiunto

- `php spark backup:verify`: prova che l'ultimo dump si ripristini davvero.
  Lo carica in un database temporaneo `<database>_verify_<timestamp>`,
  confronta i conteggi delle tabelle principali e il totale fatturato, poi
  elimina la copia; l'esito finisce in `writable/cron/backup_verify.json`.
  Serve un utente con permesso di creazione database (`CREATEDB` su
  PostgreSQL, `CREATE` su MySQL/MariaDB).
- Quattro controlli nuovi in **Gestione > Stato del sistema**: verifica del
  ripristino (con avviso se l'ultimo dump non è stato provato; un permesso di
  creazione database mancante è un avviso, non un guasto), ambiente
  dell'applicazione (`CI_ENVIRONMENT`, per accorgersi di un'installazione con
  dati veri lasciata in sviluppo), spazio libero sul disco, errori nel log di
  oggi.

### Modificato

- `tools/cron-runner.sh` esegue `backup:verify` una volta a settimana dopo il
  backup, con il binario PHP configurabile in `PHP_BIN` (in crontab il PATH è
  ridotto).

### Corretto

- Con utente del database vuoto (connessione come utente di sistema) i comandi
  di dump passavano `-U ''`; la verifica del ripristino perdeva anche il nome
  del database da creare. Ora l'opzione viene omessa quando l'utente non è
  configurato.

## [v0.2] — 2026-11-11

### Aggiunto

- **Gestione > Stato del sistema**: pagina in sola lettura con i controlli che
  possono guastarsi in silenzio — versione di PHP, cartelle scrivibili,
  connessione al database, migrazioni da applicare, età dell'ultimo backup,
  ultima esecuzione delle automazioni. Un backup assente o vecchio, o un
  cron-runner fermo, ora si vedono senza aprire i log del server.
- Il backup automatico funziona anche su MySQL/MariaDB: l'endpoint sceglie
  `pg_dump` o `mysqldump` dal motore configurato (`backup_*.dump` e
  `backup_*.sql`), con rotazione separata per formato. Prima lanciava sempre
  `pg_dump`, quindi su un'installazione MariaDB falliva parlando di PostgreSQL.
  Il binario di `mysqldump` si può indicare in `backup.mysqldumpPath`.
- Test sul comando di dump (argomenti, password fuori dalla riga di comando,
  quotatura dei nomi), sulla rotazione e sui controlli di stato.

### Modificato

- `tools/cron-runner.sh` scrive log e stato dei job in `writable/cron` invece
  che in `~/.invoiceplane-cron` (con ripiego sulla home se la cartella non è
  scrivibile): è il posto da cui la pagina "Stato del sistema" legge se le
  automazioni girano. Un log fuori dall'applicazione non lo guarda nessuno.

## [v0.1.1] — 2026-11-11

### Aggiunto

- Test d'integrazione sugli incassi (eliminazione, modifica dell'importo,
  importo oltre il saldo, fattura inesistente), sulle spese (totale derivato,
  tendine vuote, fornitore digitato a mano, modifica, fornitore mancante) e
  sulla conversione preventivo → fattura (righe, sconto di riga, data e
  collegamento fra i documenti).
- Il fornitore della fixture di test ora ha un'azienda: senza, i test che lo
  cercano nell'azienda attiva lo saltavano.
- Test sul `pg_dump` da usare: versione letta dal server, scelta del primo
  binario abbastanza recente e ripiego sul più nuovo disponibile.
- Analisi statica con PHPStan (livello 3) e `composer audit` in CI, più il
  comando `composer analyse` per eseguirla in locale.
- Guida `docs/installazione-e-aggiornamento.md`: aggiornamento, backup,
  ripristino verificato e checklist da passare sull'ambiente reale.

### Corretto

- `GenerateFatturaJob` chiamava `FatturaXml::generate()`, che non esiste: il
  job in coda sarebbe fallito a ogni esecuzione. Ora usa `FatturaPaXmlService`.
- `ClientModel::delete()` e `InvoiceModel::delete()` non restituivano l'esito
  della cancellazione, quindi un errore del database passava per riuscita.
- La lingua italiana aveva due chiavi `new`: la seconda sovrascriveva la
  prima in silenzio.
- `ProjectModel::getSummary()` usava `get()->getRowArray()` su un metodo del
  model che PHPStan non risolve; ora usa `first()`.
- Documentazione di `set_node()`, `_set_anagr()`, `_to_xml()`, `Cryptor` e
  `Management::csv()` allineata ai parametri e ai tipi reali; `FatturaPA`
  converte esplicitamente a float l'aliquota letta dalla chiave.
- `tools/cron-runner.sh` chiamava `/cron/remind_overdue/`, rotta che non
  esiste: ogni giorno il job finiva in 404 e nel log sembrava un guasto. I
  solleciti si inviano a mano dalla scheda della fattura e la riga è stata
  tolta.
- Il backup automatico non produceva nessun file quando il `pg_dump` nel PATH
  era di versione inferiore al server (16 contro 17.11): ora l'endpoint sceglie
  il binario adatto fra i percorsi installati, e `backup.pgDumpPath` resta la
  scelta esplicita.
- Un backup fallito rispondeva `200` con il messaggio d'errore nel corpo, e il
  cron-runner lo registrava come riuscito: ora risponde `500`, viene annotato
  `FALLITO` e riprovato al giro successivo.

## [v0.1] — 2026-11-11

Prima versione pubblica. Comprende fatture e note di credito con fatturazione
elettronica (XML FatturaPA, PDF, invio allo SdI), preventivi, DDT, incassi,
spese, ritenute, F24, RiBa, bilancino e piano dei conti, gestione multi-azienda,
portale pubblico per i documenti e API per l'automazione.

### Aggiunto

- Test d'integrazione sulle scritture di fatture e preventivi: totali con più
  righe e aliquote diverse, sconti di riga e di documento, modifica e rimozione
  delle righe, duplicazione, eliminazione, incasso parziale, creazione di un
  preventivo con testata e righe. Girano in transazione con rollback e usano il
  token CSRF della pagina del form.
- Job di CI su MariaDB accanto a quello su PostgreSQL, così i rami del codice
  che dipendono dal motore vengono eseguiti almeno una volta a ogni push.
- Fixture `tests/fixtures/ci_seed_mysql.sql`, con gli stessi dati della versione
  PostgreSQL.
- `CHANGELOG.md`, `SECURITY.md` e copertina del progetto nel README.

### Corretto

- La creazione di una fattura senza cliente (o senza data e gruppo di
  numerazione) finiva su `/invoices/view/0` senza spiegazioni: le regole di
  validazione non venivano caricate e, anche caricandole, i campi assenti dal
  POST non venivano controllati. Ora il form si riapre con il motivo.
- Stesso difetto nella creazione di un preventivo senza cliente, che in più
  arrivava a calcolare gli importi di un id inesistente.
- Le migrazioni non si applicavano a un database MySQL/MariaDB vuoto: un
  `DROP INDEX` su un indice mai creato, `CREATE INDEX IF NOT EXISTS` (che MySQL
  non accetta), `UPDATE ... FROM` e cast `::tipo` solo PostgreSQL.
- I conteggi delle ricorrenti (`NOW()::date`) e il progressivo SdI
  (`(setting_value::int + 1)::text`) usavano sintassi PostgreSQL e su MySQL
  fallivano a runtime.

### Rimosso

- Script di verifica una tantum dalla radice (`smoke_test.php`, `test_db.php`),
  il comando temporaneo `audit:check` e la cartella `pg-compat/`, sostituiti
  dai test automatici. `tag_check.php` resta come strumento di sviluppo in
  `tools/`.

[v0.3]: https://github.com/iacos93/invoiceplus/releases/tag/v0.3
[v0.2]: https://github.com/iacos93/invoiceplus/releases/tag/v0.2
[v0.1]: https://github.com/iacos93/invoiceplus/releases/tag/v0.1
