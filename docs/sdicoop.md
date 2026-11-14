# Canale SDICoop: configurazione e confini

Questa guida riguarda i due webservice che l'applicazione **espone** allo SdI
quando è accreditata come trasmittente SDICoop, e il canale in uscita verso
`SdIRiceviFile`. Il codice è in `app/Controllers/SdiCoopEndpoint.php`,
`app/Controllers/SdiRicezioneEndpoint.php`, `app/Libraries/Sdi/`.

L'applicazione da sola **non è** un endpoint SDICoop valido: l'autenticazione
della controparte e i limiti di dimensione più bassi stanno nel webserver o nel
reverse proxy. Questa pagina dice cosa va configurato lì e cosa fa invece
l'applicazione.

## 1. Flusso

### In uscita (noi → SdI)

| Cosa | Dove |
| --- | --- |
| Endpoint `SdIRiceviFile` (collaudo o produzione) | impostazione `sdi_coop_endpoint` |
| Certificato e chiave client per il TLS mutuo (PEM) | `sdi_coop_cert_path`, `sdi_coop_key_path` |
| Passphrase della chiave | `sdi_coop_key_passphrase` (cifrata) |
| CA che firma il certificato del server SdI, se non è nel trust store di sistema | `sdi_coop_ca_path` |
| Firma CAdES del file XML | **a monte**, non la fa `SdiCoopChannel` |

La richiesta è SOAP/MTOM (`SdiSoapMessage::riceviFileRequest()`); la risposta
immediata contiene solo l'`IdentificativoSdI`. Gli esiti veri arrivano dopo,
sull'endpoint in ingresso.

### In ingresso (SdI → noi)

| Rotta | Webservice | Cosa consegna |
| --- | --- | --- |
| `POST /sdi-coop/{token}` | TrasmissioneFatture | notifiche RC, NS, NE, MC, DT, AT sulle fatture emesse |
| `POST /sdi-ricezione/{token}` | RicezioneFatture | fatture di **acquisto** (e `fileSdI` di decorrenza termini) |

Il `{token}` è un segreto lungo e casuale, salvato cifrato nelle impostazioni
(`sdi_coop_inbound_token`, `sdi_ricezione_inbound_token`) e confrontato con
`hash_equals`. L'endpoint non ha sessione né CSRF: il token è l'unico
differenziatore applicativo, **non** l'autenticazione della controparte.

## 2. Cosa DEVE fare il webserver

L'applicazione risponde su HTTP; TLS mutuo, allowlist e limiti di body sono
responsabilità di chi la espone. In ordine di importanza:

1. **TLS 1.2+ obbligatorio** sulla rotta degli endpoint (o sull'intero sito).
2. **TLS mutuo con verifica del client**: il certificato presentato dallo SdI
   deve essere richiesto e verificato contro la CA indicata da SOGEI in fase di
   accreditamento. Senza `SSLVerifyClient require` chiunque abbia il token
   entra; con la verifica, serve anche il certificato.
3. **Allowlist degli IP SOGEI** (aggiornata quando SOGEI comunica nuove
   uscite): seconda barriera indipendente dal certificato.
4. **Limite sul body** *prima* di PHP: 20 MB, allineato a
   `SdiPayloadGuard::MAX_BODY_BYTES`. Il webserver rifiuta con 413 senza
   consegnare il corpo al processo PHP, che quindi non lo tiene in memoria.
5. **Timeout** di lettura e invio adeguati (l'invio MTOM può durare decine di
   secondi) e disattivazione del buffering delle richieste, se il proxy ne
   aggiunge uno.
6. **Nessun altro percorso pubblico**: `writable/` non va servito; la cartella
   `incoming/` con le fatture conservate non è scaricabile dal web.

### nginx (estratto)

```nginx
# Certificato client richiesto e verificato con la CA SOGEI.
ssl_client_certificate /etc/ssl/sogeicoop/ca-sogeicoop.pem;
ssl_verify_client on;
ssl_verify_depth 3;
ssl_protocols TLSv1.2 TLSv1.3;

client_max_body_size 20m;
client_body_timeout 60s;
proxy_read_timeout 120s;

# Solo i due webservice, e solo dagli IP concordati.
location ~ ^/sdi-(coop|ricezione)/ {
    allow 192.0.2.10;      # segnaposto: usare gli IP comunicati da SOGEI
    deny all;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

### Apache (estratto)

```apache
SSLVerifyClient require
SSLVerifyDepth 3
SSLCACertificateFile /etc/ssl/sogeicoop/ca-sogeicoop.pem

LimitRequestBody 20971520
<LocationMatch "^/sdi-(coop|ricezione)/">
    Require ip 192.0.2.10
</LocationMatch>
```

`SSLVerifyClient require` sul vhost intero va bene solo se l'intero sito è
dedicato allo SdI; altrimenti va messo nel `<Location>` delle due rotte, perché
il resto dell'applicazione usa il login normale.

## 3. Token: uso e rotazione

- Il token sta **nel percorso**, non in query string né in un header: è quello
  che SOGEI registra come URL del webservice, quindi cambiarlo cambia l'URL da
  comunicare.
- Va generato con almeno 32 byte casuali (per esempio
  `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`) e incollato in
  Impostazioni > SdI. Non va scritto in documentazione, ticket o repository.
- **Rotazione**: generare il nuovo token, salvarlo nell'app, comunicare a SOGEI
  il nuovo URL, e solo dopo considerare dismesso il vecchio. L'app accetta un
  token solo: tra il salvataggio e l'aggiornamento lato SOGEI le chiamate con il
  vecchio token ricevono 403 e lo SdI ritenta. Va quindi pianificata in una
  finestra concordata, non fatta "a caldo" senza avvisare SOGEI.
- Un 403 con body `Token non valido` è l'unico caso in cui l'app risponde senza
  guardare il corpo: nessun dettaglio interno viene restituito.

## 4. Limiti applicati dall'app

Definiti in `app/Libraries/Sdi/SdiPayloadGuard.php` (un punto solo, così i test
e il webserver restano allineabili):

| Limite | Valore | Dove si applica |
| --- | --- | --- |
| Body SOAP/MTOM | 20 MB | entrambi gli endpoint in ingresso |
| Singolo XML o `.p7m` decodificato | 10 MB | `RiceviFattura`, notifiche, import da file |
| Voci in uno ZIP | 50 | `FatturaXml::extract()` |
| Dimensione decompressa per voce | 50 MB | idem, letta da `statIndex()` prima di decomprimere |
| Dimensione decompressa totale | 50 MB | idem |
| Rapporto di compressione per voce | 100:1 | idem (blocca le zip bomb) |

Superato un limite, l'endpoint risponde **413 con un SOAP Fault** (non un 500):
il messaggio dice quale limite è stato superato e non contiene percorsi o dati
interni. `FatturaXml::extract()` continua a restituire una lista vuota come per
un file illeggibile — così gli import da form non cambiano comportamento — ma
il motivo resta in `FatturaXml::lastError()` e nel log.

C'è anche un tetto alle **richieste** (`PublicEndpointThrottle`, 300 al minuto
per IP, condiviso dai due endpoint): oltre il limite la risposta è **429 con
`Retry-After`**, prima ancora del controllo del token, così i tentativi con
token sbagliato non si possono ripetere all'infinito. Dietro un reverse proxy
serve `Config\App::$proxyIPs`, altrimenti tutti i client condividono l'IP del
proxy e quindi lo stesso contatore.

### Duplicati

Lo SdI può ripetere una consegna, e due documenti diversi possono arrivare con
lo stesso `NomeFile` (il nome è nella busta, non lo scegliamo noi).

- Il file già presente in `writable/uploads/expenses/incoming/` **non viene
  sovrascritto**.
- Contenuto identico: si riusa la copia esistente, l'import a valle è
  idempotente (una fattura già registrata viene saltata) e si risponde comunque
  `ER01`, così lo SdI smette di ritentare.
- Contenuto diverso: la nuova copia prende un suffisso d'hash prima
  dell'estensione (`IT0123_00001.a1b2c3d4e5f6.xml`, `.xml.p7m` resta `.p7m`) e
  il caso finisce nel log.
- Anche una notifica di esito ripetuta non viene applicata due volte: se esito
  e messaggio coincidono con quelli già registrati, la seconda viene ignorata.

## 5. Cosa l'app verifica e cosa NON verifica

- **La firma CMS dei file `.p7m` (verifica best-effort).** Prima di estrarre
  l'XML, `FatturaXml::unwrapP7m()` passa la busta a
  `App\Libraries\Sdi\P7mSignature::inspect()`, che usa `openssl_pkcs7_verify()`
  con `PKCS7_NOVERIFY | PKCS7_BINARY` sui certificati inclusi. Vengono
  controllati l'**integrità** (digest del contenuto e firma del firmatario:
  una busta manomessa non torna) e la **scadenza** del certificato del
  firmatario, riportata come avviso nell'import. Se la firma non torna il
  documento è rifiutato (`firma digitale non valida: il contenuto è stato
  modificato`); se OpenSSL non riesce a leggere la busta il documento è
  **accettato** con un avviso, perché le buste reali dello SdI sono BER a
  lunghezza indefinita e non si può perdere una fattura valida solo perché
  una versione di OpenSSL non la interpreta. La verifica non è quindi una
  garanzia di autenticità: un `.p7m` costruito con un certificato self-signed
  e contenuto coerente risulta "valido".
- **Chi è l'emittente e se il certificato è attendibile.** Catena di
  certificati, revoca (CRL/OCSP), CA SOGEI e identità del firmatario non sono
  verificati: `PKCS7_NOVERIFY` salta apposta la catena, che senza la CA
  attendibile non si potrebbe controllare. Per le fatture che arrivano
  dallo SdI l'autenticità è garantita dal canale (TLS mutuo + certificato
  SOGEI) e dallo SdI stesso; per i file caricati a mano da `Spese > Importa
  XML` resta la verifica di integrità qui sopra, non l'identità di chi ha
  firmato. Resta anche il limite delle **buste BER non interpretabili**:
  quando OpenSSL non le legge, l'unica cosa che si può dire è che la firma
  non è stata verificata.
- **Lo schema XSD delle fatture ricevute.** Si fa il parsing dei campi che
  servono (`FatturaXml::parse()`), non `schemaValidate()`: una fattura ricevuta
  fuori schema ma leggibile viene importata. La validazione XSD resta sul
  percorso di invio.
- **La firma del messaggio SOAP** e l'identità dell'emittente a livello
  applicativo: sono delegate al certificato client verificato dal webserver.
- **Antivirus o sandbox** dei file: i file sono conservati come arrivano. Il
  parsing XML usa `LIBXML_NONET`, quindi non contatta la rete, ma non è un
  motore di sicurezza dei contenuti.
- **La correttezza fiscale** dei dati: quella la decide chi importa e controlla
  la spesa.

## 6. Test in collaudo

Con l'accreditamento SOGEI di collaudo:

1. **TLS mutuo**: senza certificato client il webserver deve rifiutare
   l'handshake, non l'applicazione.
   ```console
   curl -v https://sdi.example.test/sdi-coop/TOKEN   # deve fallire l'handshake
   ```
2. **Token**: con certificato valido ma token sbagliato, risposta 403 con
   `Token non valido` nel body.
3. **Notifica**: inviare un file di notifica di esempio (RC/NS conforme allo
   schema) e controllare nella lista fatture che l'esito sia passato
   da `pending` a quello atteso. Reinvarlo identico e verificare nel log
   `notifica ... gia' registrata, ignorata`.
4. **Limite body**: inviare un corpo oltre 20 MB con `curl --data-binary` e
   verificare 413 con SOAP Fault, e che il log non contenga il contenuto.
5. **ZIP anomalo**: importare uno ZIP con più di 50 voci o con una voce molto
   compressa da `Spese > Importa XML`; deve essere rifiutato con il motivo in
   log e nessuna spesa creata.
6. **Endpoint API del trasmittente**: in Impostazioni > SdI, un endpoint
   `http://...` deve essere rifiutato dall'app prima di qualunque connessione;
   un endpoint `https://...` che risponde 3xx deve essere trattato come rifiuto
   (i redirect sono disabilitati).
7. **Conservazione**: controllare che i file arrivino in
   `writable/uploads/expenses/incoming/` e che una seconda consegna dello
   stesso file non ne cambi il contenuto.
