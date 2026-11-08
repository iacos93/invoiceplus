<?php

namespace App\Services;

/**
 * Riferimenti di commessa del tracciato (DatiOrdineAcquisto, DatiContratto,
 * DatiConvenzione, DatiRicezione) e il loro rapporto con CIG e CUP.
 *
 * Puro come InvoiceMath: la stessa regola decide cosa esce nell'XML
 * (Invoices::addEinvoiceReferences) e quando avvisare l'utente nella scheda
 * fattura, e va provata senza database.
 *
 * Il problema da cui nasce: CIG e CUP nel tracciato non esistono da soli,
 * stanno dentro uno di quei blocchi, e ogni blocco vuole un IdDocumento. Se
 * l'utente indica il CIG ma nessun numero d'ordine, il tracciato usciva con
 * IdDocumento = numero della fattura stessa — formalmente valido, ma
 * l'amministrazione lo legge come "ordine n. 0005", cioè un riferimento a un
 * atto che non esiste, e l'ufficio che liquida può rifiutare la fattura.
 */
final class EinvoiceReferences
{
    /**
     * IdDocumento usato quando c'è il CIG/CUP ma nessun riferimento.
     *
     * Lo SdI controlla solo che il campo sia una stringa non vuota di al più
     * 20 caratteri: "ND" (non disponibile) è la convenzione diffusa fra i
     * gestionali e dichiara apertamente che il riferimento manca, invece di
     * inventarne uno. Resta un ripiego: la scheda fattura avvisa finché il
     * numero dell'ordine o della determina non viene indicato.
     */
    public const FALLBACK_ID = 'ND';

    /** Campi "numero" dei quattro blocchi di riferimento. */
    public const NUMBER_FIELDS = [
        'invoice_order_number',
        'invoice_contract_number',
        'invoice_convention_number',
        'invoice_receipt_number',
    ];

    /**
     * Vero se il documento ha CIG o CUP ma nessun numero di riferimento in
     * cui farli viaggiare.
     *
     * Una nota di credito che rettifica una fattura dell'archivio porta CIG e
     * CUP dentro DatiFattureCollegate: lì il riferimento c'è già (la fattura
     * stornata), e non serve né il ripiego né l'avviso.
     */
    public static function missingOrderReference(array $invoice): bool
    {
        $cig = trim((string) ($invoice['invoice_cig'] ?? ''));
        $cup = trim((string) ($invoice['invoice_cup'] ?? ''));

        if ($cig === '' && $cup === '') {
            return false;
        }

        if (!empty($invoice['creditinvoice_parent_id'])) {
            return false;
        }

        foreach (self::NUMBER_FIELDS as $field) {
            if (trim((string) ($invoice[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
