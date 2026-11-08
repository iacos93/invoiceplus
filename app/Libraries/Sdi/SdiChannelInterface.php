<?php

namespace App\Libraries\Sdi;

/**
 * Canale di trasmissione allo SdI.
 *
 * Ogni canale implementa la stessa operazione: mandare il file FatturaPA
 * (nome IdTrasmittente_Progressivo.xml) al Sistema di Interscambio e
 * ritornare l'esito immediato dell'operazione. L'esito definitivo
 * (ricevute RC/NS/NE/EC/MC/DT) arriva dopo, come risposta del canale:
 *
 *  - PEC:  ricevuta nella casella PEC mittente (la legge Cron::sdiPoll)
 *  - API:  risposta/notifica del webservice del trasmittente
 *  - manuale: download del file, poi l'esito si aggiorna a mano
 */
interface SdiChannelInterface
{
    /**
     * Invia il file allo SdI.
     *
     * @param string $xml      contenuto XML FatturaPA
     * @param string $filename nome file di trasmissione (IdTrasmittente_Progressivo.xml)
     * @param array  $invoice  riga fattura (getById)
     *
     * @return array{ok: bool, message: string} message sempre valorizzato
     */
    public function send(string $xml, string $filename, array $invoice): array;
}