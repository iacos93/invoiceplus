<?php

namespace App\Libraries\Sdi;

/**
 * Canale manuale: il file FatturaPA viene generato e salvato nel registro
 * trasmissioni, ma la consegna allo SdI avviene fuori dall'app
 * (download del file e invio tramite il proprio intermediario, oppure
 * conservazione diretta).
 *
 * Utile come primo passo quando il canale PEC/API non e' ancora
 * configurato: la fattura resta tracciata con esito 'pending' e l'esito
 * della pratica si aggiorna a mano dalla pagina fattura, finche' non si
 * attiva il polling IMAP o il webservice.
 */
class SdiManualChannel implements SdiChannelInterface
{
    public function send(string $xml, string $filename, array $invoice): array
    {
        return [
            'ok'      => true,
            'message' => 'XML generato e registrato (' . $filename . '): scaricalo e consegnalo al tuo canale SdI. L\'esito si aggiorna dalla pagina fattura.',
        ];
    }
}