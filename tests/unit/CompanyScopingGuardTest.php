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
 *
 * Il salvataggio passa ora da BaseController::saveFormRecord(), che chiama
 * saveRecord() per conto del controller: il riconoscimento copre anche quella
 * forma, altrimenti le chiamate sparirebbero dal conteggio e il test
 * passerebbe controllando zero righe.
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
        // I parametri fiscali hanno una riga condivisa (company_id NULL) che
        // non si riscrive sul posto: il perimetro lo controlla findEffective(),
        // che riconosce anche quella condivisa e ne fa una copia dell'azienda.
        'FiscalYears',
    ];

    public function testOgniSalvataggioPerIdHaIlGuardAzienda(): void
    {
        foreach (self::CONTROLLERS as $controller) {
            $file = APPPATH . 'Controllers/' . $controller . '.php';

            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);

            // Il salvataggio può ricevere l'id con un altro nome (FiscalYears
            // passa $targetId, che è null quando la riga condivisa diventa una
            // copia dell'azienda): il riconoscimento accetta qualunque
            // variabile che finisca per «id», o quelle chiamate sparirebbero
            // dal conteggio e il test passerebbe controllando meno righe.
            preg_match_all(
                '/\$this->\w+->(?:saveRecord|saveSender)\(\$id\b|\$this->saveFormRecord\([^\n]*\$\w*[Ii]d\b/',
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

                // findEffective() è la variante dei parametri fiscali: tiene
                // dentro la riga condivisa e fuori quelle delle altre aziende.
                $this->assertMatchesRegularExpression(
                    '/find(?:Scoped|Effective)\(\$id\)/',
                    $before,
                    $controller . ': il ramo POST esegue ' . $call . ' senza findScoped($id). '
                    . 'La GET controlla l\'azienda ma il POST la scavalca: un id di un\'altra '
                    . 'azienda verrebbe riscritto (update() filtra solo per chiave primaria).'
                );
            }
        }
    }
}
