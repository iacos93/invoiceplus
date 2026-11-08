<?php

use App\Models\ClientModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Risoluzione dello split payment per cliente.
 *
 * Alimenta l'autocompilazione dell'esigibilità IVA in fattura: un errore qui
 * non produce un errore visibile, produce una fattura verso la PA con
 * l'esigibilità sbagliata — un problema che si scopre solo quando lo SdI (o
 * il commercialista) la respinge.
 *
 * @internal
 */
final class ClientSplitPaymentTest extends CIUnitTestCase
{
    public function testPublicAdministrationIsSplitByDefault(): void
    {
        $this->assertTrue(ClientModel::splitPaymentApplies([
            'client_type' => 'pa', 'client_split_payment' => 'auto',
        ]));
    }

    public function testCompanyIsNotSplitByDefault(): void
    {
        $this->assertFalse(ClientModel::splitPaymentApplies([
            'client_type' => 'company', 'client_split_payment' => 'auto',
        ]));
    }

    public function testPersonIsNotSplitByDefault(): void
    {
        $this->assertFalse(ClientModel::splitPaymentApplies([
            'client_type' => 'person', 'client_split_payment' => 'auto',
        ]));
    }

    /** Una società privata soggetta per legge (partecipata, quotata) non si riconosce dal tipo: va forzata. */
    public function testCompanyCanBeForcedToAlwaysSplit(): void
    {
        $this->assertTrue(ClientModel::splitPaymentApplies([
            'client_type' => 'company', 'client_split_payment' => 'yes',
        ]));
    }

    /** Anche una PA può essere esclusa esplicitamente, se il caso lo richiede. */
    public function testPublicAdministrationCanBeForcedOff(): void
    {
        $this->assertFalse(ClientModel::splitPaymentApplies([
            'client_type' => 'pa', 'client_split_payment' => 'no',
        ]));
    }

    /** Clienti creati prima di questo campo: nessuna colonna in memoria, non solo vuota. */
    public function testMissingFieldBehavesLikeAuto(): void
    {
        $this->assertTrue(ClientModel::splitPaymentApplies(['client_type' => 'pa']));
        $this->assertFalse(ClientModel::splitPaymentApplies(['client_type' => 'company']));
    }

    public function testUnknownModeFallsBackToAuto(): void
    {
        $this->assertTrue(ClientModel::splitPaymentApplies([
            'client_type' => 'pa', 'client_split_payment' => 'qualcosa_di_invalido',
        ]));
    }
}
