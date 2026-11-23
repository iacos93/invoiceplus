<?php

namespace App\Models\Concerns;

use App\Libraries\ClientSnapshot;

/**
 * Scrittura e rilettura della copia del cliente sui documenti.
 *
 * Il trait sta su InvoiceModel, QuoteModel e DeliveryNoteModel e agisce in due
 * punti soli, scelti perché sono gli unici da cui passano tutti i percorsi:
 *
 * - doInsert/doUpdate: la copia nasce con il documento. Su update si riscrive
 *   solo se cambia il cliente (il documento viene riassegnato), non a ogni
 *   salvataggio: se no, aprire e salvare una bozza dopo aver corretto
 *   l'anagrafica rimetterebbe in circolo il nome nuovo, cioè proprio quello che
 *   la copia serve a evitare.
 * - afterFind: i campi congelati sostituiscono quelli del join su ip_clients.
 *
 * Sta dopo il filtro degli allowedFields (doInsert/doUpdate arrivano già
 * filtrati), quindi client_snapshot non va aggiunto agli allowedFields: un
 * POST non deve poterlo inventare.
 */
trait HasClientSnapshot
{
    protected function doInsert(array $row)
    {
        if (!empty($row['client_id'])) {
            $row['client_snapshot'] = ClientSnapshot::capture((int) $row['client_id']);
        }

        return parent::doInsert($row);
    }

    protected function doUpdate($id = null, $row = null): bool
    {
        // Model::update() avvolge sempre l'id in un array prima di arrivare qui:
        // con un controllo su is_int($id) la copia non si aggiornava mai quando
        // il documento cambiava cliente. Un aggiornamento su più documenti
        // insieme resta escluso, perché la copia giusta è una per documento.
        $docId = is_array($id) && count($id) === 1 ? reset($id) : $id;

        if (is_array($row) && !empty($row['client_id']) && is_numeric($docId)) {
            $snapshot = $this->clientSnapshotOnClientChange((int) $docId, (int) $row['client_id']);

            if ($snapshot !== null) {
                $row['client_snapshot'] = $snapshot;
            }
        }

        return parent::doUpdate($id, $row);
    }

    /**
     * Copia da scrivere quando una update porta con sé un client_id: null se
     * il cliente è lo stesso di sempre e la copia va lasciata com'è.
     */
    private function clientSnapshotOnClientChange(int $id, int $clientId): ?string
    {
        $stored = $this->db->table($this->table)
            ->select('client_snapshot')
            ->where($this->primaryKey, $id)
            ->get()
            ->getRowArray();

        $snapshot = ClientSnapshot::decode($stored['client_snapshot'] ?? null);

        if ($snapshot !== [] && (int) ($snapshot['client_id'] ?? 0) === $clientId) {
            return null;
        }

        return ClientSnapshot::capture($clientId);
    }

    /**
     * Evento afterFind del model: applica la copia a una riga o a un elenco.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    protected function applyClientSnapshot(array $event): array
    {
        $data = $event['data'] ?? null;

        if (!is_array($data) && !is_object($data)) {
            return $event;
        }

        if (!empty($event['singleton'])) {
            $event['data'] = ClientSnapshot::apply($data);

            return $event;
        }

        foreach ($data as $key => $row) {
            if (is_array($row) || is_object($row)) {
                $event['data'][$key] = ClientSnapshot::apply($row);
            }
        }

        return $event;
    }
}
