<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Una rotta GET deve leggere, non scrivere.
 *
 * Le GET non hanno token CSRF e il browser (o un prefetch, o un link messo su
 * una pagina di terzi) puo' farle partire senza un clic vero. In questo
 * progetto il problema si e' presentato tre volte: approvazione/rifiuto dei
 * preventivi e i due ricalcoli globali di importi.
 *
 * Il test guarda i nomi dei metodi raggiunti da rotte GET e blocca quelli che
 * dichiarano una mutazione. Le eccezioni sono elencate esplicitamente, con il
 * motivo: aggiungerne una deve essere una scelta, non una svista.
 */
final class MutatingRoutesTest extends CIUnitTestCase
{
    /**
     * Gestori GET autorizzati: non mutano dati, anche se il nome sembra
     * suggerirlo.
     */
    private const ECCEZIONI = [
        'Sessions::passwordreset' => 'mostra il form di reimpostazione password; la scrittura avviene in POST',
        'UserClients::create'     => 'la GET mostra il form di assegnazione clienti; la scrittura avviene in POST sulla stessa rotta',
        'CustomValues::create'    => 'la GET mostra il form del valore personalizzato; la scrittura avviene in POST sulla stessa rotta',
    ];

    /**
     * Verbi che nel nome di un metodo indicano una scrittura. Si guarda solo
     * il metodo (dopo '::'): il nome del controller non conta, altrimenti
     * EmailSenders::index verrebbe scambiato per una spedizione.
     */
    private const VERBI = '/^(delete|remove|toggle|recalculate|approve|reject|cancel|mark|send|convert|update|save|reset|logout|create|destroy|trash)/i';

    public function testNessunaRottaGetEsegueUnaMutazione(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        preg_match_all(
            '/\$routes->get\(\s*\'([^\']*)\'\s*,\s*\'([^\']+)\'/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        // Se il formato delle rotte cambia, il test non deve passare a vuoto.
        $this->assertNotEmpty($matches, 'Nessuna rotta GET trovata in Config/Routes.php.');

        $sospette = [];

        foreach ($matches as [, $path, $handler]) {
            // 'GuestView::quote/$1' -> 'GuestView::quote'
            $handler = (string) preg_replace('#/\$[0-9a-z]+$#i', '', $handler);

            if (isset(self::ECCEZIONI[$handler])) {
                continue;
            }

            $method = str_contains($handler, '::') ? explode('::', $handler, 2)[1] : $handler;

            if (preg_match(self::VERBI, $method)) {
                $sospette[] = $path . ' => ' . $handler;
            }
        }

        $this->assertSame(
            [],
            $sospette,
            "Rotte GET che sembrano modificare dati:\n - " . implode("\n - ", $sospette)
            . "\nSe la scrittura e' voluta, la rotta va registrata su POST (con CSRF) e il form aggiornato."
        );
    }

    /**
     * Il rovescio della regola sopra: se eliminare è una POST, nessuna vista
     * può portarci con un link. Un <a href=".../delete/1"> passa dalla
     * conferma e poi fa una GET, che la rotta non conosce: l'utente conferma
     * e si ritrova su un 404 (è successo nella scheda progetto).
     */
    public function testNessunaVistaLinkaUnaRottaDiEliminazione(): void
    {
        $colpevoli = [];
        $iterator  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . 'Views'));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'confirm_modal.php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // href="…/delete/…" scritto in chiaro oppure composto con site_url('x/delete/').
            if (preg_match('#href="[^"<]*/delete/#', $source)
                || preg_match('#href="\'\s*\.\s*site_url\(\'[a-z_/]+/delete/#', $source)
                || preg_match('#href="<\?=\s*site_url\(\'[a-z_/]+/delete/#', $source)) {
                $colpevoli[] = str_replace(APPPATH, '', $file->getPathname());
            }
        }

        $this->assertSame([], $colpevoli, 'Viste con un link (GET) verso una rotta di eliminazione: usare un form POST.');
    }
}
