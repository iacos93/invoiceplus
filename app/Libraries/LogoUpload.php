<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Controllo e salvataggio dei loghi caricati (impostazioni e scheda azienda).
 *
 * Queste regole stavano dentro Controllers\Settings. Da quando anche ogni
 * azienda può avere i propri loghi, i punti di caricamento sono due: tenerle
 * in un solo posto evita che uno dei due resti indietro proprio sui controlli
 * di sicurezza (tipo reale del file, SVG con contenuti attivi, nome scelto dal
 * browser).
 */
class LogoUpload
{
    /** Estensioni ammesse (le stesse che logo_data_uri() sa servire). */
    public const EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'svg'];

    /**
     * Dimensione massima di un logo. Il valore arriva nel data URI di ogni
     * pagina che lo mostra e dentro i PDF: senza limite un file da decine di MB
     * faceva crescere la memoria a ogni tag, e il browser restava fermo.
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Tipo reale atteso per ogni estensione ammessa. L'SVG è un documento XML:
     * libmagic lo riconosce come image/svg+xml solo a volte, altrimenti lo
     * classifica come testo o XML generico (il contenuto lo controlla isSafeSvg).
     */
    private const MIMES = [
        'gif'  => ['image/gif'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/html', 'application/xml', 'text/xml'],
    ];

    /**
     * Errore da mostrare se il file non è un logo accettabile, null se va bene.
     * Non scrive nulla: chi chiama controlla tutti i file prima di toccare il
     * database, così un logo sbagliato non lascia un salvataggio a metà.
     */
    public static function check(UploadedFile $file, string $label): ?string
    {
        $extension = strtolower((string) $file->getClientExtension());

        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $label . ': formato non consentito ('
                . $extension . '). Formati ammessi: GIF, JPEG, PNG, SVG.';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $label . ': immagine troppo grande (massimo '
                . (self::MAX_BYTES / 1024 / 1024) . ' MB).';
        }

        // L'estensione dice solo come si chiama il file: il tipo lo legge
        // fileinfo dal file temporaneo, non il browser (che può mentire).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName()) ?: '';

        if (!in_array($mime, self::MIMES[$extension], true)) {
            return $label . ': il file non contiene un\'immagine ' . strtoupper($extension) . '.';
        }

        // Un SVG è un documento, non solo un'immagine: può portare script,
        // gestori di eventi o HTML incorporato. Nell'<img> non girano, ma il
        // file sta in una cartella scaricabile: qui si rifiuta il contenuto
        // attivo invece di sperare che nessuno lo apra da solo.
        if ($extension === 'svg' && !self::isSafeSvg($file->getTempName())) {
            return $label . ': il file SVG contiene codice o contenuti attivi.';
        }

        return null;
    }

    /**
     * Vero se l'SVG è un disegno e non un documento con parti attive: script,
     * gestori di eventi (onload, onclick…), entità esterne o foreignObject con
     * HTML dentro. Tutti modi per far eseguire qualcosa a chi apre il file.
     */
    public static function isSafeSvg(string $path): bool
    {
        // Basta l'inizio del file: un SVG con contenuti attivi li ha nelle
        // prime righe, e leggere 256 KB evita di caricare in memoria un file
        // enorme solo per controllarlo.
        $source = @file_get_contents($path, false, null, 0, 262144);

        if ($source === false || stripos($source, '<svg') === false) {
            return false;
        }

        return preg_match('/<\s*script|<\s*foreignObject|javascript:|<!ENTITY|\son[a-z]+\s*=/i', $source) !== 1;
    }

    /**
     * Sposta un logo in writable/uploads e restituisce il nome salvato ('' se
     * il file non si è potuto scrivere).
     *
     * Il nome del browser non è fidato: può contenere percorsi ("../../x.png")
     * e due campi diversi possono caricare lo stesso "logo.png", sovrascriven-
     * dosi a vicenda. Qui il nome si costruisce: prefisso + originale ripulito.
     */
    public static function store(UploadedFile $file, string $prefix): string
    {
        $uploadDir = WRITEPATH . 'uploads';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return '';
        }

        try {
            // move() lancia FileException se move_uploaded_file() fallisce
            // (permessi, disco pieno): qui diventa un errore da mostrare.
            $file->move($uploadDir, self::fileName(
                (string) $file->getClientName(),
                (string) $file->getClientExtension(),
                $prefix
            ), true);
        } catch (\Throwable) {
            return '';
        }

        return $file->getName();
    }

    /**
     * Nome da scrivere per un logo: prefisso (campo, o azienda e campo), nome
     * originale ridotto a lettere, numeri e trattini, estensione già verificata.
     */
    public static function fileName(string $clientName, string $clientExtension, string $prefix): string
    {
        $extension = strtolower($clientExtension);
        $base      = strtolower(pathinfo($clientName, PATHINFO_FILENAME));
        $base      = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base      = trim($base, '-_');

        if ($base === '') {
            $base = 'logo';
        }

        return $prefix . '-' . mb_substr($base, 0, 40) . '.' . $extension;
    }

    /**
     * Quale logo stampare: quello dell'azienda se ne ha uno, altrimenti quello
     * globale delle Impostazioni. Sta qui, senza database né impostazioni,
     * perché è la regola che decide cosa finisce su un documento fiscale e
     * così si prova da sola (vedi document_logo(), che le fornisce i dati).
     *
     * @param array<string, mixed> $company riga di ip_companies (anche vuota)
     */
    public static function pick(array $company, string $key, string $global): string
    {
        $own = trim((string) ($company[$key] ?? ''));

        return $own !== '' ? $own : trim($global);
    }

    /**
     * Cancella il file di un logo non più usato. basename() perché il nome
     * arriva dal database: non deve poter indicare un file fuori da uploads.
     */
    public static function delete(?string $name): void
    {
        if (!$name) {
            return;
        }

        $path = WRITEPATH . 'uploads/' . basename($name);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
