<?php

namespace App\Libraries\Sdi;

use App\Models\SdiTransmissionModel;

/**
 * Orchestratore delle trasmissioni allo SdI.
 *
 * Prende una fattura e il suo XML FatturaPA, determina il nome file di
 * trasmissione (IdTrasmittente_Progressivo.xml, la stessa convenzione di
 * FatturaPA::filename()), registra la trasmissione nel log
 * (ip_sdi_transmissions) e la consegna al canale configurato nelle
 * Impostazioni (PEC, webservice API o download manuale).
 *
 * Il progressivo di trasmissione viene dal campo custom IT_UTENTE_PROGR_XML_ID
 * e avanza solo a trasmissione riuscita, cosi' il numero non viene bruciato
 * da anteprime e tentativi falliti. Senza campo custom si ripiega sul
 * numero fattura in base36, come in generateXml().
 */
class SdiSender
{
    public const CHANNELS = [
        'pec'    => 'PEC',
        'api'    => 'Webservice (API intermediario)',
        'sdicoop' => 'SDICoop (accreditamento diretto)',
        'manual' => 'Manuale (download)',
    ];

    /** Ricevute ufficiali dello SdI + stato 'pending' per l'invio registrato in attesa di esito. */
    public const ESITI = [
        'RC'      => 'Ricevuta di consegna',
        'NS'      => 'Scarto',
        // NE e' la notifica con cui lo SdI inoltra l'esito del committente
        // (EC01 accettata / EC02 rifiutata, dettaglio nel messaggio), non
        // una mancata consegna: quella e' MC.
        'NE'      => 'Esito committente',
        'EC'      => 'Esito committente',
        'MC'      => 'Mancata consegna',
        'DT'      => 'Decorrenza termini',
        'AT'      => 'Trasmessa, impossibile recapitare (PA)',
        'pending' => 'Inviata: in attesa di esito',
        // Fattura importata dall'archivio di un altro gestionale
        // (SalesInvoiceImporter): è stata trasmessa, ma l'esito non è
        // noto da qui. Non va rinviata (vedi Invoices::sendSdi).
        'IMP'     => 'Trasmessa da altro gestionale',
    ];

    public const ESITO_CLASSES = [
        'RC'      => 'success',
        'NS'      => 'danger',
        'NE'      => 'warning',
        'EC'      => 'warning',
        'MC'      => 'warning',
        'DT'      => 'warning',
        'AT'      => 'warning',
        'pending' => 'warning',
        'IMP'     => 'info',
    ];

    /** Canale configurato nelle Impostazioni (default: manuale). */
    public static function channel(): SdiChannelInterface
    {
        return match ((string) get_setting('sdi_channel', 'manual')) {
            'pec'     => new SdiPecChannel(),
            'api'     => new SdiApiChannel(),
            'sdicoop' => new SdiCoopChannel(),
            default   => new SdiManualChannel(),
        };
    }

    public static function channelLabel(string $key): string
    {
        return self::CHANNELS[$key] ?? $key;
    }

    public static function esitoLabel(?string $esito): string
    {
        if ($esito === null || $esito === '') {
            return trans('sdi_not_sent', '', 'Non trasmessa');
        }

        return self::ESITI[$esito] ?? $esito;
    }

    public static function esitoClass(?string $esito): string
    {
        if ($esito === null || $esito === '') {
            return 'secondary';
        }

        return self::ESITO_CLASSES[$esito] ?? 'secondary';
    }

    /**
     * Trasmette la fattura al canale configurato.
     *
     * @return array{ok: bool, message: string, filename: string, transmission_id: int}
     */
    public static function send(array $invoice, string $xml): array
    {
        $companyId = (int) ($invoice['company_id'] ?? 0);
        $progr     = self::currentProgressive($companyId);

        if ($progr === '') {
            $num   = preg_replace('/[^0-9]/', '', (string) ($invoice['invoice_number'] ?? ''));
            $progr = strtoupper(base_convert($num ?: '0', 10, 36));
        }

        $filename = self::filenameFromXml($xml, $progr);

        if ($filename === '') {
            return ['ok' => false, 'message' => 'Impossibile ricavare il nome file dall\'XML della fattura', 'filename' => '', 'transmission_id' => 0];
        }

        $channelKey = (string) get_setting('sdi_channel', 'manual');
        $model      = new SdiTransmissionModel();

        $transmissionId = (int) $model->insert([
            'invoice_id' => (int) $invoice['invoice_id'],
            'filename'   => $filename,
            'channel'    => $channelKey,
            'esito'      => 'pending',
            'sent_at'    => date('Y-m-d H:i:s'),
            'xml'        => $xml,
        ]);

        $result = self::channel()->send($xml, $filename, $invoice);

        $model->setEsito($transmissionId, $result['ok'] ? 'pending' : null, $result['message']);

        if ($result['ok']) {
            self::advanceProgressive($companyId);
        }

        return [
            'ok'              => $result['ok'],
            'message'         => $result['message'],
            'filename'        => $filename,
            'transmission_id' => $transmissionId,
        ];
    }

    /**
     * Progressivo di trasmissione per trasmittente (azienda), non per utente.
     *
     * Prima era legato a un campo custom per-utente: due utenti della stessa
     * azienda/P.IVA trasmittente producevano "IdCodice_1.xml" identici e lo SdI
     * li rifiutava come duplicati. Ora il contatore vive in ip_settings con
     * chiave "sdi_progressive_<company_id>", cosi' e' condiviso da tutti gli
     * utenti del trasmittente. Resta vuoto se non configurato: in quel caso
     * send() ricade sul progressivo derivato dal numero fattura.
     */
    private static function currentProgressive(int $companyId): string
    {
        $companyId = $companyId > 0 ? $companyId : (int) active_company_id();

        if ($companyId <= 0) {
            return '';
        }

        return (string) get_setting('sdi_progressive_' . $companyId, '');
    }

    /**
     * Il progressivo avanza solo a trasmissione riuscita (stessa regola di
     * generateXml). Incremento atomico in una sola UPDATE, non leggi-poi-
     * scrivi: due invii concorrenti per la stessa azienda (due utenti, o un
     * invio manuale sovrapposto a un retry) leggevano lo stesso valore prima
     * che uno dei due scrivesse, generando due XML con lo stesso progressivo
     * -- che lo SdI scarta come duplicato, con perdita silenziosa di una
     * fattura registrata come inviata. Il WHERE su setting_value garantisce
     * che l'update tocchi una riga solo se il progressivo e' configurato
     * (stesso comportamento del ramo "non configurato" prima del fix).
     */
    private static function advanceProgressive(int $companyId): void
    {
        $companyId = $companyId > 0 ? $companyId : (int) active_company_id();

        if ($companyId <= 0) {
            return;
        }

        $key = 'sdi_progressive_' . $companyId;
        $db  = \Config\Database::connect();

        // Confronto-e-scambio invece di (setting_value::int + 1)::text: la
        // sintassi dei cast e dell'operatore regex e' di PostgreSQL e su
        // MySQL la UPDATE falliva. Si legge il valore, si scrive quello
        // incrementato solo se nel frattempo nessun altro l'ha cambiato:
        // stesso effetto atomico, senza dialetto.
        for ($tentativo = 0; $tentativo < 5; $tentativo++) {
            $row = $db->query(
                'SELECT setting_value FROM ip_settings WHERE setting_key = ?',
                [$key]
            )->getRow();

            if ($row === null) {
                return;
            }

            $corrente = trim((string) $row->setting_value);

            // Progressivo non configurato (stringa vuota) o non numerico: si
            // lascia com'e', come faceva il filtro regex.
            if ($corrente === '' || !ctype_digit($corrente)) {
                return;
            }

            $db->query(
                'UPDATE ip_settings SET setting_value = ? WHERE setting_key = ? AND setting_value = ?',
                [(string) ((int) $corrente + 1), $key, $corrente]
            );

            if ($db->affectedRows() > 0) {
                \App\Models\SettingModel::resetCache();

                return;
            }

            // Un altro invio ha incrementato nel frattempo: si rilegge.
        }
    }

    /**
     * Nome file di trasmissione dall'XML appena generato: stesso risultato
     * di FatturaPA::filename(), letta la sezione DatiTrasmissione.
     */
    private static function filenameFromXml(string $xml, string $progr): string
    {
        $pattern = '/<IdTrasmittente>\s*<IdPaese>([A-Za-z]{2})<\/IdPaese>\s*<IdCodice>([^<]+)<\/IdCodice>/';

        if (!preg_match($pattern, $xml, $m)) {
            return '';
        }

        return $m[1] . $m[2] . '_' . $progr . '.xml';
    }
}