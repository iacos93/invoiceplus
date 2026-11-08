<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * I controller verificano l'azienda del record nella GET (findScoped) e la
 * dimenticavano nel ramo POST: `saveRecord($id, ...)` passa da
 * Model::update(), che filtra solo per chiave primaria, quindi un id di
 * un'altra azienda veniva riscritto anche se la pagina di modifica rispondeva
 * 404.
 *
 * Il guard è stato aggiunto a mano in ogni controller; questo test lo tiene
 * tale, perché il baco non è visibile finché non esiste una seconda azienda
 * con dati e nessun test HTTP lo coprirebbe.
 *
 * La verifica è testuale di proposito: una prova end-to-end servirebbe un
 * database con due aziende popolate, mentre la dimenticanza da prevenire è
 * esattamente "manca la riga nel ramo POST".
 */
final class CompanyScopingGuardTest extends CIUnitTestCase
{
    /**
     * Controller il cui form salva un record esistente per id (tabelle con
     * company_id, oppure perimetro che passa da fattura/cliente/progetto).
     * Restano fuori i cataloghi globali (aliquote, unità, famiglie, metodi di
     * pagamento, categorie di spesa): non hanno company_id.
     */
    private const CONTROLLERS = [
        'Clients',
        'Suppliers',
        'Products',
        'InvoiceGroups',
        'F24',
        'Expenses',
        'EmailSenders',
        'DeliveryNotes',
        'Projects',
        'Tasks',
        'Payments',
    ];

    public function testOgniSalvataggioPerIdHaIlGuardAzienda(): void
    {
        foreach (self::CONTROLLERS as $controller) {
            $file = APPPATH . 'Controllers/' . $controller . '.php';

            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);

            preg_match_all(
                '/\$this->\w+->(?:saveRecord|saveSender)\(\$id\b/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            // Se il metodo di salvataggio viene rinominato, il test non deve
            // passare a vuoto: senza chiamate trovate non sta controllando
            // niente.
            $this->assertNotEmpty(
                $matches[0],
                $controller . ': nessun salvataggio per $id trovato — il test è diventato vacuo?'
            );

            foreach ($matches[0] as [$call, $offset]) {
                $before = substr($source, 0, $offset);

                $this->assertMatchesRegularExpression(
                    '/findScoped\(\$id\)/',
                    $before,
                    $controller . ': il ramo POST esegue ' . $call . ' senza findScoped($id). '
                    . 'La GET controlla l\'azienda ma il POST la scavalca: un id di un\'altra '
                    . 'azienda verrebbe riscritto (update() filtra solo per chiave primaria).'
                );
            }
        }
    }
}
