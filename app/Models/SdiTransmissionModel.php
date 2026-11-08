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

    /** Aggiorna esito e messaggio di una trasmissione (ricevuta SdI o esito manuale). */
    public function setEsito(int $transmissionId, ?string $esito, string $message = ''): void
    {
        $this->update($transmissionId, [
            'esito'      => $esito ?: null,
            'messaggio'  => $message !== '' ? $message : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}