# Installazione su un sottodominio

Come mettere Invoice+ online su un indirizzo proprio, per esempio
`https://invoice.tuo-dominio.example/`. Per i passi generali
(`composer install`, `.env`, migrazioni) vedi il [README](../README.md#installazione);
qui restano la scelta dell'indirizzo, il server web, i cron e le verifiche.

## Sottodominio o sottocartella

Si consiglia **un sottodominio dedicato** (`invoice.tuo-dominio.example`) a una
sottocartella di un host condiviso con altre applicazioni
(`app.tuo-dominio.example/invoice`):

- **Le passkey sono legate al nome del sito.** Se l'indirizzo cambia dopo averle
  registrate, smettono di funzionare e vanno rifatte. Il nome va scelto una volta.
- **Isolamento.** Sullo stesso host cookie, `localStorage` e service worker sono
  condivisi con le altre applicazioni: una vulnerabilità in una di esse potrebbe
  leggere la sessione di un gestionale con dati fiscali.
- **Configurazione.** In una sottocartella `app.baseURL` deve contenere il
  percorso e il server deve riscrivere gli indirizzi dentro quella cartella.
- **Indirizzi comunicati a terzi** (l'endpoint SDICoop per lo SdI, il feed del
  calendario) non devono cambiare: un sottodominio resta tuo anche se un giorno
  sposti l'applicazione su un altro server.

## 1. DNS e HTTPS

Crea il record DNS (A/AAAA o CNAME) e ottieni un certificato valido per il
nome, per esempio con Let's Encrypt:

```console
certbot --nginx -d invoice.tuo-dominio.example
```

L'HTTPS non è facoltativo: senza, il browser non registra il service worker e
non propone l'installazione come app, e i cookie di sessione non possono essere
marcati `Secure`.

## 2. Il server web

La document root è la cartella **`public/`**, non la radice del progetto: `.env`,
`app/`, `writable/` e `vendor/` restano fuori dalla portata del web.

### nginx con PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name invoice.tuo-dominio.example;

    ssl_certificate     /etc/letsencrypt/live/invoice.tuo-dominio.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/invoice.tuo-dominio.example/privkey.pem;

    root  /var/www/invoiceplus/public;
    index index.php;

    # Allegati e importazioni: il predefinito di nginx (1 MB) è troppo basso.
    client_max_body_size 20m;

    # Compressione: una pagina pesa 100-200 KB e compressa ne viaggia un
    # settimo. Sotto nginx il file public/.htaccess non viene letto, quindi
    # compressione e cache degli asset vanno dichiarate qui.
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;
    gzip_min_length 1024;

    # Asset statici: portano ?v= nell'indirizzo, quindi una cache lunga è
    # sicura. sw.js è escluso più sotto.
    location ~* ^/assets/.+\.(css|js|woff2?|png|jpe?g|gif|webp|svg|ico)$ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # Il service worker non va tenuto in cache dal browser: un aggiornamento
    # deve arrivare al primo ricaricamento, non dopo un giorno.
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files $uri =404;
    }

    # File nascosti (.env, .git, .htaccess): mai serviti.
    location ~ /\.(?!well-known) {
        deny all;
    }
}

server {
    listen 80;
    server_name invoice.tuo-dominio.example;
    return 301 https://$host$request_uri;
}
```

### Apache

Con `mod_rewrite` attivo e `AllowOverride All` sulla cartella, `public/.htaccess`
fa già la riscrittura verso `index.php`:

```apache
<VirtualHost *:443>
    ServerName invoice.tuo-dominio.example
    DocumentRoot /var/www/invoiceplus/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/invoice.tuo-dominio.example/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/invoice.tuo-dominio.example/privkey.pem

    <Directory /var/www/invoiceplus/public>
        AllowOverride All
        Require all granted
    </Directory>

    <Files "sw.js">
        Header set Cache-Control "no-cache"
    </Files>
</VirtualHost>
```

## 3. Configurazione

Nel `.env` (vedi [README](../README.md#installazione)):

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://invoice.tuo-dominio.example/'
```

`app.baseURL` deve coincidere **esattamente** con l'indirizzo con cui si apre il
sito, schema e barra finale compresi: con la Content-Security-Policy in
`enforce`, un indirizzo diverso fa smettere `'self'` di corrispondere agli
asset e la pagina resta bianca.

Poi:

```console
php spark migrate
chown -R www-data:www-data writable     # o l'utente del pool PHP-FPM
```

Apri l'indirizzo: la pagina iniziale controlla l'ambiente e chiede di creare
l'amministratore, poi **Primi passi** elenca il resto.

## 4. Automazioni e backup

Genera la chiave in **Impostazioni → Impostazioni sistema → CRON Key**, scrivila in
`writable/cron-runner.conf` insieme all'indirizzo, e installa la riga di crontab
del runner:

```console
# writable/cron-runner.conf
BASE_URL="https://invoice.tuo-dominio.example"
CRON_KEY="la-chiave-generata"

# crontab -e
*/15 * * * * /var/www/invoiceplus/tools/cron-runner.sh
```

Il dettaglio di backup, ripristino e verifica è in
[Installazione, aggiornamento e ripristino](installazione-e-aggiornamento.md).

## 5. Se usi lo SdI con SDICoop

L'accreditamento lega l'indirizzo dell'endpoint che l'applicazione espone:
registralo una volta sul sottodominio definitivo. La procedura, con il
certificato lato server e la mutua autenticazione, è in [sdicoop.md](sdicoop.md).

## 6. Verifica

- Il sito risponde in HTTPS e il lucchetto non segnala avvisi.
- **Stato del sistema** non mostra controlli guasti (database, migrazioni,
  cartelle, backup, cron).
- Gli asset arrivano compressi e con cache lunga:
  `curl -sI -H 'Accept-Encoding: gzip' https://invoice.tuo-dominio.example/assets/core/css/custom.min.css`
  deve mostrare `content-encoding: gzip` e `cache-control: … immutable`.
- **opcache** è attivo per PHP-FPM (`opcache.enable=1`): senza, ogni richiesta
  ricompila il codice e le pagine impiegano diverse volte tanto.
- Il pulsante **Installa app** in barra apre la finestra di installazione di
  Chrome o Edge.
- Una fattura di prova si scarica in PDF con logo e piè di pagina.
