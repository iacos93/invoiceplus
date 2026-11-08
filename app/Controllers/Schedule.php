<?php

namespace App\Controllers;

use App\Models\InvoiceModel;

/**
 * Scadenzario — registro delle scadenze aperte.
 *
 * Equivalente della sezione "Scadenzario" di Fatture in Cloud, limitato a
 * quello che questo gestionale conosce: le fatture emesse con saldo residuo.
 * (Non ci sono acquisti/fornitori, quindi non ci sono scadenze passive.)
 *
 * I dati arrivano tutti da InvoiceModel::getDueSchedule(); qui si raggruppano
 * in fasce temporali e si calcolano i totali per fascia.
 */
class Schedule extends BaseController
{
    /** Fasce temporali dello scadenzario, in giorni di ritardo. */
    private const BUCKETS = [
        'overdue'  => ['label' => 'Scadute',            'class' => 'danger'],
        'week'     => ['label' => 'Entro 7 giorni',     'class' => 'warning'],
        'month'    => ['label' => 'Entro 30 giorni',    'class' => 'info'],
        'later'    => ['label' => 'Oltre 30 giorni',    'class' => 'neutral'],
    ];

    public function index()
    {
        // Filtro anno globale (topbar): filtra per anno di scadenza, vedi
        // il commento su InvoiceModel::getDueSchedule().
        $rows = (new InvoiceModel())->getDueSchedule(selected_year());

        // Inizializza le fasce in ordine, così la view le stampa sempre
        // nella stessa sequenza anche quando alcune sono vuote.
        $buckets = [];
        foreach (self::BUCKETS as $key => $meta) {
            $buckets[$key] = $meta + ['rows' => [], 'total' => 0.0];
        }

        $grandTotal   = 0.0;
        $overdueTotal = 0.0;

        foreach ($rows as $row) {
            $days    = (int) $row['days_overdue'];   // >0 = in ritardo
            $balance = (float) $row['invoice_balance'];

            if ($days > 0) {
                $key = 'overdue';
                $overdueTotal += $balance;
            } elseif ($days >= -7) {
                $key = 'week';
            } elseif ($days >= -30) {
                $key = 'month';
            } else {
                $key = 'later';
            }

            $buckets[$key]['rows'][]  = $row;
            $buckets[$key]['total']  += $balance;
            $grandTotal              += $balance;
        }

        return view('schedule/index', [
            'buckets'       => $buckets,
            'grand_total'   => $grandTotal,
            'overdue_total' => $overdueTotal,
            'open_count'    => count($rows),
        ]);
    }
}
