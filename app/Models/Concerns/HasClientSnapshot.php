<?php

namespace App\Models\Concerns;

use App\Libraries\ClientSnapshot;

/**
 * Scrittura e rilettura della copia del cliente sui documenti.
 *
 * Il trait sta su InvoiceModel, QuoteModel e DeliveryNoteModel e agisce in due
 * punti soli, scelti perché sono gli unici da cui passano tutti i percorsi:
 *
 * - doInsert/doUpdate: la copia nasce con il documento. Finché è in bozza si
 *   rifà a ogni salvataggio, compreso quello che la segna come inviata; dopo
 *   si riscrive solo se cambia il cliente (il documento viene riassegnato),
 *   così correggere l'anagrafica non tocca ciò che è già uscito.
 * - afterFind: i campi congelati sostituiscono quelli del join su ip_clients,
 *   tranne sulle bozze, che mostrano l'anagrafica di oggi.
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

        if (is_array($row) && is_numeric($docId)) {
            $snapshot = $this->clientSnapshotForUpdate((int) $docId, (int) ($row['client_id'] ?? 0));

            if ($snapshot !== null) {
                $row['client_snapshot'] = $snapshot;
            }
        }

        return parent::doUpdate($id, $row);
    }

    /**
     * Colonna di stato e valore che vuol dire "bozza", per le tabelle che
     * hanno una bozza. Il DDT non ce l'ha: nasce già consegnato, e la sua
     * copia resta quella della creazione.
     *
     * @return array{0: string, 1: int}|null
     */
    private function clientSnapshotDraftStatus(): ?array
    {
        return match ($this->table) {
            'ip_invoices' => ['invoice_status_id', 1],
            'ip_quotes'   => ['quote_status_id', 1],
            default       => null,
        };
    }

    /**
     * Copia da scrivere con questa update, o null per lasciarla com'è.
     *
     * Finché il documento è in bozza la copia si rifà a ogni salvataggio: una
     * bozza non è ancora uscita, e se nel frattempo si corregge la partita IVA
     * in anagrafica deve partire con quella giusta, non con quella del giorno
     * in cui è stata creata. Lo stato letto è quello prima dell'update, quindi
     * anche il salvataggio che la segna come inviata rifà la copia: è lì che
     * l'intestazione si ferma. Dopo, la copia cambia solo se cambia il cliente.
     */
    private function clientSnapshotForUpdate(int $id, int $clientId): ?string
    {
        $draft = $this->clientSnapshotDraftStatus();
        $fields = 'client_id, client_snapshot' . ($draft !== null ? ', ' . $draft[0] : '');

        $stored = $this->db->table($this->table)
            ->select($fields)
            ->where($this->primaryKey, $id)
            ->get()
            ->getRowArray();

        if ($stored === null) {
            return null;
        }

        $targetClient = $clientId ?: (int) ($stored['client_id'] ?? 0);

        if ($draft !== null && (int) ($stored[$draft[0]] ?? 0) === $draft[1]) {
            return ClientSnapshot::capture($targetClient);
        }

        if ($clientId === 0) {
            return null;
        }

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
            $event['data'] = $this->applySnapshotUnlessDraft($data);

            return $event;
        }

        foreach ($data as $key => $row) {
            if (is_array($row) || is_object($row)) {
                $event['data'][$key] = $this->applySnapshotUnlessDraft($row);
            }
        }

        return $event;
    }

    /**
     * Una bozza mostra l'anagrafica di oggi anche se la sua copia è più
     * vecchia: la copia di una bozza si aggiorna solo quando la si salva, e
     * chi corregge il cliente e riapre la bozza deve già vedere la correzione.
     * Se la riga non porta la colonna di stato (select ridotte), si applica la
     * copia: nel dubbio vale l'intestazione congelata.
     *
     * @param array<string, mixed>|object $row
     *
     * @return array<string, mixed>|object
     */
    private function applySnapshotUnlessDraft($row)
    {
        $draft = $this->clientSnapshotDraftStatus();

        if ($draft !== null) {
            $status = is_array($row) ? ($row[$draft[0]] ?? null) : ($row->{$draft[0]} ?? null);

            if ($status !== null && (int) $status === $draft[1]) {
                return $row;
            }
        }

        return ClientSnapshot::apply($row);
    }
}
