<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Trasmissioni allo SdI: una riga per ogni invio di fattura elettronica.
 *
 * L'esito e' la posizione della pratica presso il Sistema di Interscambio:
 * null finche' non c'e' risposta, poi una delle ricevute ufficiali
 * (RC consegna, NS scarto, NE/EC/DT/MC mancate consegne) oppure 'pending'
 * per l'invio registrato ma in attesa di esito.
 */
class SdiTransmissionModel extends Model
{
    protected $table            = 'ip_sdi_transmissions';
    protected $primaryKey       = 'sdi_transmission_id';
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'invoice_id',
        'filename',
        'channel',
        'esito',
        'messaggio',
        'xml',
        'sent_at',
        'updated_at',
    ];

    /** Ultima trasmissione di una fattura (null se mai inviata). */
    public function latestForInvoice(int $invoiceId): ?array
    {
        return $this->where('invoice_id', $invoiceId)
            ->orderBy('sdi_transmission_id', 'DESC')
            ->first() ?: null;
    }

    /**
     * Mappa invoice_id => ultima trasmissione per le liste fatture:
     * una sola query con subquery su MAX(id), senza N+1.
     */
    public function latestMap(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));

        if ($invoiceIds === []) {
            return [];
        }

        $rows = $this->db->query(
            'SELECT t.* FROM ip_sdi_transmissions t
             JOIN (SELECT invoice_id, MAX(sdi_transmission_id) AS max_id
                   FROM ip_sdi_transmissions WHERE invoice_id IN (' . implode(',', $invoiceIds) . ')
                   GROUP BY invoice_id) m
               ON m.invoice_id = t.invoice_id AND m.max_id = t.sdi_transmission_id'
        )->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['invoice_id']] = $row;
        }

        return $map;
    }

    /**
     * P.IVA dell'azienda trasmittente dal nome file ufficiale
     * `IT<partitaIVA>_<progressivo>.xml` (FatturaPA::filename()).
     *
     * Il prefisso e' l'IdTrasmittente: in un'installazione multi-azienda e'
     * l'unico dato che dice a quale azienda appartiene la trasmissione,
     * perche' il progressivo puo' ripetersi fra aziende diverse.
     *
     * Metodo puro: nessun accesso al database, si prova senza PostgreSQL.
     */
    public static function vatFromFilename(string $filename): ?string
    {
        if (preg_match('/^IT(\d{11})_/i', trim($filename), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Sceglie la trasmissione da aggiornare fra le righe candidate.
     *
     * Solo una riga e' una risposta certa: zero (nessuna corrispondenza) o
     * piu' di una (stesso nome file per aziende diverse, o trasmissioni
     * ripetute) non si applicano. Meglio lasciare l'esito com'e' che
     * scriverlo sulla fattura sbagliata; la notifica resta nel log.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    public static function pickCandidate(array $rows): ?array
    {
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * Trasmissioni con lo stesso nome file, ristrette all'azienda della
     * fattura quando l'azienda e' nota (join su ip_invoices).
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesForFilename(string $filename, ?int $companyId): array
    {
        $builder = $this->db->table('ip_sdi_transmissions t')
            ->select('t.*')
            ->join('ip_invoices i', 'i.invoice_id = t.invoice_id', 'left')
            ->where('t.filename', $filename)
            ->orderBy('t.sdi_transmission_id', 'DESC');

        // Azienda non riconosciuta dal prefisso del file: nessun filtro, si
        // applica solo se la corrispondenza resta unica (vedi pickCandidate).
        if ($companyId !== null) {
            $builder->where('i.company_id', $companyId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * La sola trasmissione aggiornabile per nome file e azienda, oppure null
     * se non c'e' o se le candidate sono ambigue.
     */
    public function findByFilenameForCompany(string $filename, ?int $companyId): ?array
    {
        return self::pickCandidate($this->candidatesForFilename($filename, $companyId));
    }

    /** Aggiorna esito e messaggio di una trasmissione (ricevuta SdI o esito manuale). */
    public function setEsito(int $transmissionId, ?string $esito, string $message = ''): void
    {
        $this->update($transmissionId, [
            'esito'      => $esito ?: null,
            'messaggio'  => $message !== '' ? $message : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Tutti gli esiti passano da qui (invio, notifiche SDICoop, polling
        // PEC, registrazione a mano): e' l'unico punto in cui si puo' tenere
        // allineato il blocco della fattura a cio' che lo SdI sa di lei.
        $trasmissione = $this->find($transmissionId);

        if ($trasmissione === null) {
            return;
        }

        $effetto = self::lockEffect($esito, (string) ($trasmissione['channel'] ?? ''));

        if ($effetto !== null) {
            (new InvoiceModel())->setLocked((int) $trasmissione['invoice_id'], $effetto);
        }
    }

    /**
     * Cosa fa un esito al blocco della fattura: true la blocca, false la
     * sblocca, null lascia com'e'.
     *
     * Una fattura che lo SdI ha in mano non deve cambiare: le due copie
     * (quella dell'app e quella dello SdI) smetterebbero di coincidere senza
     * che nessuno se ne accorga. Lo scarto (NS) e' l'unico caso opposto: la
     * fattura non e' stata accettata e va corretta e rinviata, quindi deve
     * tornare modificabile.
     *
     * Il canale manuale e' un caso a parte: 'pending' li' vuol dire solo "XML
     * generato", e che sia stato consegnato davvero l'app non lo sa. Si blocca
     * quando arriva un esito vero (a mano, dalla pagina fattura).
     */
    public static function lockEffect(?string $esito, ?string $channel): ?bool
    {
        $esito = (string) $esito;

        return match (true) {
            $esito === ''       => null,
            $esito === 'NS'     => false,
            $esito === 'pending' => $channel === 'manual' ? null : true,
            default             => true,
        };
    }
}