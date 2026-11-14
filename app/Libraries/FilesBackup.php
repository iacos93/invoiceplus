<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Copia dei file caricati (writable/uploads) in un archivio ZIP.
 *
 * Il dump del database non li contiene: gli XML delle fatture di acquisto
 * ricevute dallo SdI (expenses/{id}.xml e la cartella incoming/), gli allegati
 * dei clienti, i file F24 e i loghi stanno su disco. Perdere il server vuol
 * dire perdere gli originali, che per una fattura sono l'XML e non la riga nel
 * database. Con la conservazione a norma dell'Agenzia delle Entrate gli XML
 * transitati dallo SdI esistono anche li', ma non il resto.
 *
 * Il nome dell'archivio ha un prefisso diverso da quello dei dump
 * (`files_` e non `backup_`): la pagina Stato del sistema, il download e la
 * verifica del ripristino cercano `backup_*` e non devono scambiare uno ZIP
 * per un dump. La rotazione e' la stessa (gli ultimi 14).
 *
 * Non contiene il .env ne' la chiave di cifratura: sono segreti e vanno
 * custoditi a parte, non in un file che resta nella stessa cartella dei dump.
 */
final class FilesBackup
{
    public const PREFIX = 'files_';
    public const KEEP   = DatabaseBackup::KEEP;

    private string $source;
    private string $dir;

    public function __construct(?string $source = null, ?string $dir = null)
    {
        $this->source = rtrim($source ?? WRITEPATH . 'uploads', '/');
        $this->dir    = rtrim($dir ?? WRITEPATH . 'backups', '/');
    }

    /**
     * @return array{file: string, bytes: int, files: int, removed: int}
     *
     * @throws RuntimeException con il motivo, da mostrare in chiaro
     */
    public function run(): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP zip non disponibile: backup dei file impossibile');
        }

        if (! is_dir($this->source)) {
            throw new RuntimeException('Cartella dei file non trovata: ' . $this->source);
        }

        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cartella dei backup non creabile: ' . $this->dir);
        }

        $file = $this->dir . '/' . self::PREFIX . date('Ymd_His') . '.zip';
        $temp = $file . '.tmp';

        // Si scrive su un nome provvisorio e si rinomina a fine lavoro: la
        // rotazione e il controllo di stato guardano solo i .zip, e un
        // archivio a meta' non deve mai passare per un backup riuscito.
        @unlink($temp);

        $zip = new \ZipArchive();

        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare l\'archivio ' . basename($temp));
        }

        $count = 0;

        foreach ($this->files() as $path => $relative) {
            if (! $zip->addFile($path, $relative)) {
                $zip->close();
                @unlink($temp);

                throw new RuntimeException('Impossibile aggiungere ' . $relative . ' all\'archivio');
            }

            $count++;
        }

        if ($count === 0) {
            $zip->close();
            @unlink($temp);

            throw new RuntimeException('Nessun file da salvare in ' . $this->source);
        }

        // close() scrive davvero: un disco pieno si scopre qui.
        if (! $zip->close() || ! is_file($temp) || filesize($temp) === 0) {
            @unlink($temp);

            throw new RuntimeException('Scrittura dell\'archivio non riuscita (spazio su disco?)');
        }

        if (! @rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException('Impossibile rinominare l\'archivio in ' . basename($file));
        }

        // Come i dump: contiene dati fiscali e personali.
        if (! @chmod($file, 0600)) {
            log_message('warning', 'Backup file: impossibile impostare i permessi 0600 su ' . $file);
        }

        $removed = DatabaseBackup::rotate($this->dir, self::PREFIX, '.zip', self::KEEP);

        return ['file' => $file, 'bytes' => (int) filesize($file), 'files' => $count, 'removed' => $removed];
    }

    /**
     * File da salvare: percorso assoluto => percorso nell'archivio.
     * Si saltano i collegamenti simbolici (potrebbero portare fuori dalla
     * cartella) e i file non leggibili.
     *
     * @return iterable<string, string>
     */
    private function files(): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isFile()) {
                continue;
            }

            if (! $item->isReadable()) {
                log_message('warning', 'Backup file: ' . $item->getPathname() . ' non leggibile, saltato');

                continue;
            }

            yield $item->getPathname() => ltrim(substr($item->getPathname(), strlen($this->source)), '/');
        }
    }
}
