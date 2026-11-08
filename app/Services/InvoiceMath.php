<?php

namespace App\Services;

/**
 * Aritmetica dei documenti: importi di riga, saldo e importi del tracciato
 * FatturaPA.
 *
 * Come FinanceService è puro di proposito — numeri in ingresso, numeri in
 * uscita, niente database né impostazioni — perché è proprio qui che si
 * annidano gli errori da un centesimo che lo SdI non perdona, e vanno
 * provati con i test unitari, non scoperti su una ricevuta di scarto.
 *
 * I chiamanti sono ItemAmountModel e InvoiceAmountModel (che scrivono i
 * totali derivati) e Invoices::generateXml (che li riporta nel tracciato):
 * tenere la stessa formula in un punto solo è ciò che impedisce al PDF e
 * all'XML di raccontare due fatture diverse.
 */
final class InvoiceMath
{
    /**
     * Arrotondamento commerciale al centesimo (mezzo centesimo in su),
     * immune al rumore dei float.
     *
     * round() da solo non basta: 3,5 × 4,59 in binario vale
     * 16,064999999999998, e round(…, 2) lo porta a 16,06 invece di 16,07 —
     * esattamente lo sconto del 10% su 160,65 € che risultava sbagliato di
     * un centesimo. Il primo round() a 9 decimali riporta il valore al
     * decimale "vero" (prezzi e quantità hanno al più due decimali ciascuno,
     * quindi i prodotti che calcoliamo stanno ben dentro i 9), il secondo
     * arrotonda al centesimo come farebbe chiunque con carta e penna.
     */
    public static function round2(float $value): float
    {
        $rounded = round(round($value, 9), 2);

        // -0.0 in uscita diventa "-0.00" con number_format: meglio uno zero
        // pulito, che nel tracciato e a video non sembri un importo negativo.
        return $rounded == 0.0 ? 0.0 : $rounded;
    }

    /**
     * Importi di una riga documento.
     *
     * Lo sconto a importo è per unità (come nel form e in CI3) e quello
     * percentuale si applica al prezzo unitario pieno: la riga netta vale
     * quindi quantità × (prezzo − importo − prezzo × %), che è anche la
     * cascata con cui lo SdI ricostruisce PrezzoTotale dagli sconti di riga
     * (vedi lineDiscounts()).
     *
     * Una riga con Natura IVA (N1-N7) non porta imposta anche con aliquota
     * piena: è un'operazione non imponibile, esente, fuori campo o in
     * inversione contabile, e il cedente non addebita IVA.
     *
     * @return array{subtotal: float, discount: float, taxable: float, tax: float, total: float}
     */
    public static function lineAmounts(
        float $quantity,
        float $price,
        float $discountAmount,
        float $discountPercent,
        float $taxPercent,
        bool $hasNatura = false
    ): array {
        $subtotal = self::round2($quantity * $price);
        $discount = self::round2(
            ($discountAmount * $quantity) + ($price * $discountPercent / 100 * $quantity)
        );
        $taxable = self::round2($subtotal - $discount);
        $tax     = $hasNatura ? 0.0 : self::round2($taxable * $taxPercent / 100);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'taxable'  => $taxable,
            'tax'      => $tax,
            'total'    => self::round2($taxable + $tax),
        ];
    }

    /**
     * Nodi ScontoMaggiorazione di una riga (2.2.1.10 del tracciato).
     *
     * Senza questi nodi PrezzoTotale (netto) non torna con
     * Quantita × PrezzoUnitario e lo SdI scarta con codice 00423. La
     * percentuale va per prima: lo SdI applica gli sconti in cascata
     * nell'ordine in cui compaiono, e solo così prezzo × (1 − %) − importo
     * coincide con la formula di lineAmounts(), dove entrambi gli sconti
     * partono dal prezzo pieno.
     *
     * $sign è il segno con cui il prezzo unitario esce nel tracciato: lo
     * sconto a importo deve seguirlo (su un prezzo negativo lo "sconto" va
     * verso lo zero), la percentuale invece è indifferente al segno.
     *
     * @return list<array{Tipo: string, Percentuale?: string, Importo?: string}>
     */
    public static function lineDiscounts(float $discountAmount, float $discountPercent, int $sign = 1): array
    {
        $nodes = [];

        if ($discountPercent != 0.0) {
            $nodes[] = [
                'Tipo'        => $discountPercent > 0 ? 'SC' : 'MG',
                'Percentuale' => self::dec(abs($discountPercent)),
            ];
        }

        if ($discountAmount != 0.0) {
            $nodes[] = [
                'Tipo'    => $discountAmount > 0 ? 'SC' : 'MG',
                'Importo' => self::dec(abs($discountAmount) * ($sign < 0 ? -1 : 1)),
            ];
        }

        return $nodes;
    }

    /**
     * Riga come deve uscire nel tracciato.
     *
     * Quantita nello schema non ammette il segno (pattern senza "-"): una
     * riga a quantità negativa — lo storno dentro una fattura, o le righe
     * di una nota di credito creata da InvoicesAjax::createCredit() — porta
     * il segno sul prezzo. $flip = -1 rovescia tutta la riga: è il caso
     * della nota di credito TD04, che internamente resta negativa (così
     * dashboard e report la sottraggono senza saperlo) ma nel tracciato va
     * con importi positivi, perché è già il tipo documento a dire "storno".
     *
     * @return array{quantity: float, price: float, total: float, sign: int}
     */
    public static function xmlLine(float $quantity, float $price, float $taxable, int $flip = 1): array
    {
        $sign = ($quantity < 0 ? -1 : 1) * ($flip < 0 ? -1 : 1);

        return [
            'quantity' => abs($quantity),
            'price'    => $price * $sign,
            'total'    => self::round2($taxable * ($flip < 0 ? -1 : 1)),
            'sign'     => $sign,
        ];
    }

    /**
     * Ritenuta d'acconto sull'imponibile del documento.
     *
     * L'imponibile è quello al netto degli sconti di riga: calcolarla sul
     * lordo (come faceva il tracciato) dava una ritenuta più alta di quella
     * dovuta, e un ImportoRitenuta che il sostituto d'imposta non riconosce.
     * Il segno segue l'imponibile, così una nota di credito storna anche la
     * sua ritenuta.
     */
    public static function withholding(float $taxable, ?string $type, float $rate): float
    {
        if (trim((string) $type) === '' || $rate <= 0) {
            return 0.0;
        }

        return self::round2($taxable * $rate / 100);
    }

    /**
     * Casse il cui contributo entra nella base della ritenuta d'acconto.
     *
     * La rivalsa INPS del 4% (gestione separata, TC22) è per l'Agenzia parte
     * del compenso e subisce la ritenuta; il contributo integrativo delle
     * casse professionali (TC01–TC21) no, è un onere che il professionista
     * riversa alla cassa. È la stessa distinzione che nel tracciato si
     * esprime con Ritenuta=SI dentro DatiCassaPrevidenziale.
     */
    public static function fundSubjectToWithholding(?string $fundType): bool
    {
        return strtoupper(trim((string) $fundType)) === 'TC22';
    }

    /**
     * Riepilogo del documento: righe, sconto di documento, contributo cassa,
     * IVA per aliquota, ritenuta e totale.
     *
     * È il punto in cui InvoiceAmountModel (i totali salvati) e
     * Invoices::generateXml (i DatiRiepilogo del tracciato) devono dire la
     * stessa cosa, ed è per questo che vive qui e non in uno dei due.
     *
     * Lo sconto di documento si applica all'imponibile, prima dell'IVA. In
     * CI3 veniva tolto dal totale IVA inclusa, che per la normativa italiana
     * non esiste: lo sconto incondizionato riduce la base imponibile (art. 15
     * DPR 633/72) e l'IVA va ricalcolata sul netto. Se lo si toglie dopo,
     * l'XML porta un ImponibileImporto che non è quello vero, l'IVA è
     * calcolata su un importo mai fatturato e totale e riepilogo non tornano.
     * Prima l'importo, poi la percentuale sul residuo, come in CI3.
     *
     * Con più aliquote lo sconto si ripartisce in proporzione all'imponibile
     * di ciascuna; l'ultima assorbe il resto dell'arrotondamento, così la
     * somma delle quote è esattamente lo sconto indicato.
     *
     * Il contributo cassa (art. 8 L. 335/95 e casse professionali) si
     * calcola sull'imponibile già scontato e, come vogliono le specifiche
     * SdI, si somma all'imponibile dell'aliquota della prestazione —
     * convenzionalmente la prima riga, come faceva il tracciato — e paga
     * quindi la stessa IVA (o porta la stessa Natura).
     *
     * L'IVA si calcola per aliquota sull'imponibile complessivo, non riga per
     * riga (sommare IVA già arrotondate per riga può discostarsene di un
     * centesimo), e sull'importo esatto delle righe quando lo si conosce
     * ('exact', vedi linesFromItems()).
     *
     * Gli importi mantengono il segno del documento (una nota di credito
     * interna è negativa in tutte le sue parti, sconto compreso): è il
     * tracciato a rovesciarli se serve.
     *
     * @param list<array{taxable: float, exact?: float, rate: float, natura?: string|null}> $lines
     *        imponibile di ogni riga al netto dei suoi sconti (al centesimo) e,
     *        se noto, lo stesso importo non arrotondato
     *
     * @return array{
     *     buckets: array<string, array{rate: float, natura: string|null, lines: float, discount: float, fund: float, taxable: float, tax: float}>,
     *     lines_taxable: float, discount: float, fund: float, fund_base: float, fund_bucket: string|null,
     *     taxable: float, tax: float, total: float, withholding: float
     * }
     */
    public static function documentSummary(
        array $lines,
        float $discountAmount = 0.0,
        float $discountPercent = 0.0,
        float $fundRate = 0.0,
        bool $fundWithholding = false,
        ?string $withholdingType = null,
        float $withholdingRate = 0.0
    ): array {
        $buckets = [];

        foreach ($lines as $line) {
            $rate   = (float) $line['rate'];
            $natura = trim((string) ($line['natura'] ?? '')) ?: null;
            $key    = self::bucketKey($rate, $natura);

            $buckets[$key] ??= ['rate' => $rate, 'natura' => $natura, 'lines' => 0.0, 'exact' => 0.0,
                'discount' => 0.0, 'fund' => 0.0, 'taxable' => 0.0, 'tax' => 0.0];
            $buckets[$key]['lines'] = self::round2($buckets[$key]['lines'] + (float) $line['taxable']);
            $buckets[$key]['exact'] = round($buckets[$key]['exact'] + (float) ($line['exact'] ?? $line['taxable']), 9);
        }

        $linesTaxable = self::round2(array_sum(array_column($buckets, 'lines')));

        // Sconto di documento, ripartito per aliquota.
        $discount = 0.0;
        if ($linesTaxable != 0.0 && ($discountAmount != 0.0 || $discountPercent != 0.0)) {
            $discount = self::round2(
                $discountAmount + ($linesTaxable - $discountAmount) * $discountPercent / 100
            );

            $keys      = array_keys($buckets);
            $last      = end($keys);
            $allocated = 0.0;

            foreach ($keys as $key) {
                $share = $key === $last
                    ? self::round2($discount - $allocated)
                    : self::round2($discount * $buckets[$key]['lines'] / $linesTaxable);

                $buckets[$key]['discount'] = $share;
                $allocated = self::round2($allocated + $share);
            }
        }

        $netTaxable = self::round2($linesTaxable - $discount);

        // Contributo cassa sull'imponibile scontato, nell'aliquota della
        // prima riga.
        $fund       = 0.0;
        $fundBucket = null;
        if ($fundRate > 0 && $buckets) {
            $fund       = self::round2($netTaxable * $fundRate / 100);
            $fundBucket = array_key_first($buckets);
            $buckets[$fundBucket]['fund'] = $fund;
        }

        $tax = 0.0;
        foreach ($buckets as &$bucket) {
            $bucket['taxable'] = self::round2($bucket['lines'] - $bucket['discount'] + $bucket['fund']);
            // IVA sull'importo esatto delle righe, non su quello già
            // arrotondato al centesimo: con un prezzo scorporato (350 € IVA
            // inclusa → 286,8852459) l'IVA è 63,11 e il totale torna 350,00;
            // sull'imponibile arrotondato (286,89) usciva 63,12 e la fattura,
            // incassata per 350 €, restava aperta per un centesimo. È la
            // regola dei gestionali da cui si importa: sulle 80 fatture
            // emesse di prova la riproduce in tutti i casi, contro 77 su 80.
            // Lo SdI la accetta: controlla Imposta con una tolleranza.
            $base = round($bucket['exact'] - $bucket['discount'] + $bucket['fund'], 9);
            $bucket['tax']     = $bucket['natura'] !== null
                ? 0.0
                : self::round2($base * $bucket['rate'] / 100);
            $tax = self::round2($tax + $bucket['tax']);
        }
        unset($bucket);

        $taxable = self::round2($netTaxable + $fund);

        return [
            'buckets'       => $buckets,
            'lines_taxable' => $linesTaxable,
            'discount'      => $discount,
            'fund'          => $fund,
            'fund_base'     => $fundRate > 0 ? $netTaxable : 0.0,
            'fund_bucket'   => $fundBucket,
            'taxable'       => $taxable,
            'tax'           => $tax,
            'total'         => self::round2($taxable + $tax),
            'withholding'   => self::withholding(
                $netTaxable + ($fundWithholding ? $fund : 0.0),
                $withholdingType,
                $withholdingRate
            ),
        ];
    }

    /**
     * Vero quando lo sconto di documento supera l'imponibile delle righe.
     *
     * Oltre quella soglia l'imponibile netto scende sotto zero e il totale del
     * documento diventa negativo: un documento che non si può incassare né
     * rappresentare in FatturaPA (ImportoTotaleDocumento negativo), e che
     * prima veniva accettato senza avvisi. Il confronto usa la stessa formula
     * di documentSummary(), così la soglia non può divergere dal totale che
     * poi viene davvero scritto in ip_invoice_amounts.
     *
     * Il margine di 0,004 assorbe l'arrotondamento al centesimo: uno sconto
     * pari all'imponibile è legittimo (documento a zero), uno più alto no.
     *
     * @param list<array{taxable: float}> $lines
     */
    public static function discountExceedsBase(array $lines, float $discountAmount, float $discountPercent): bool
    {
        if ($discountAmount == 0.0 && $discountPercent == 0.0) {
            return false;
        }

        $summary = self::documentSummary($lines, $discountAmount, $discountPercent);

        return $summary['discount'] > $summary['lines_taxable'] + 0.004;
    }

    /**
     * Righe di ItemModel::getByInvoice() nella forma di documentSummary().
     *
     * Sta qui perché il modello dei totali e il tracciato devono leggere le
     * righe allo stesso modo: imponibile al netto dello sconto di riga,
     * aliquota e Natura censita sull'aliquota.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{taxable: float, rate: float, natura: string|null}>
     */
    public static function linesFromItems(array $items): array
    {
        return array_map(static function (array $item): array {
            $quantity = (float) ($item['item_quantity'] ?? 0);
            $price    = (float) ($item['item_price'] ?? 0);

            return [
                'taxable' => self::round2((float) ($item['item_subtotal'] ?? 0) - (float) ($item['item_discount'] ?? 0)),
                // Stessa formula di lineAmounts(), senza arrotondare: è la
                // base dell'IVA (vedi documentSummary()).
                'exact'   => round(
                    $quantity * $price
                    - (float) ($item['item_discount_amount'] ?? 0) * $quantity
                    - $price * (float) ($item['item_discount_percent'] ?? 0) / 100 * $quantity,
                    9
                ),
                'rate'    => (float) ($item['item_tax_rate_percent'] ?? 0),
                'natura'  => ($item['item_tax_rate_natura'] ?? null) ?: null,
            ];
        }, array_values($items));
    }

    /**
     * Chiave di raggruppamento per aliquota e Natura, nello stesso formato
     * che FatturaPA::set_auto_totali() usa per i DatiRiepilogo ("22.00|",
     * "0.00|N2.2"): così il tracciato può ritrovare un gruppo calcolato qui.
     */
    public static function bucketKey(float $rate, ?string $natura): string
    {
        return self::dec($rate) . '|' . (trim((string) $natura) ?: '');
    }

    /**
     * Quanto il cliente deve effettivamente versare.
     *
     * Con lo split payment (EsigibilitaIVA "S", art. 17-ter DPR 633/72) la PA
     * paga al fornitore solo l'imponibile e versa l'IVA direttamente
     * all'Erario: il totale documento resta lordo, ma il credito verso il
     * cliente è il netto. Tenerne conto qui è ciò che evita a una fattura PA
     * incassata per intero di restare "aperta" per l'importo dell'IVA in
     * scadenzario e nei solleciti, e all'XML di chiedere in DatiPagamento
     * un importo che non arriverà mai.
     *
     * Stesso discorso per la ritenuta d'acconto: il cliente sostituto
     * d'imposta la trattiene e la versa lui con l'F24, quindi al fornitore
     * arriva il totale meno la ritenuta.
     */
    public static function amountDue(float $total, float $vatTotal, ?string $vatExigibility, float $withholding = 0.0): float
    {
        $due = $total - $withholding;

        if (strtoupper((string) $vatExigibility) === 'S') {
            $due -= $vatTotal;
        }

        return self::round2($due);
    }

    /**
     * Saldo residuo del documento: dovuto meno incassato.
     */
    public static function balance(float $total, float $vatTotal, ?string $vatExigibility, float $paid, float $withholding = 0.0): float
    {
        return self::round2(self::amountDue($total, $vatTotal, $vatExigibility, $withholding) - $paid);
    }

    /**
     * Verso del documento: -1 per una nota di credito interna (totale
     * negativo), 1 per tutto il resto.
     *
     * Un pagamento su una nota di credito è un rimborso al cliente e si
     * registra negativo, così il saldo torna a zero come per una fattura
     * incassata; chi compila il form scrive però l'importo come lo legge
     * sull'estratto conto, in positivo. Le funzioni qui sotto ragionano
     * "nel verso del documento" per non dover ricordare il segno.
     */
    public static function documentSign(float $total): int
    {
        return $total < 0 ? -1 : 1;
    }

    /**
     * Importo da proporre nel form pagamenti, sempre positivo: il residuo da
     * incassare (fattura) o da rimborsare (nota di credito), zero se il
     * documento è già saldato o pagato in eccesso.
     *
     * Prima si proponeva invoice_balance così com'era: una nota di credito
     * suggeriva "-1.049,00" e una fattura incassata con un centesimo in più
     * "-0,01".
     */
    public static function suggestedPayment(float $total, float $balance): float
    {
        return max(0.0, self::round2($balance * self::documentSign($total)));
    }

    /**
     * Il pagamento (già col segno del documento) supera il residuo?
     *
     * Il confronto "importo > saldo" funziona solo per le fatture: su una
     * nota di credito con saldo -1.049 bocciava un rimborso parziale di -500
     * (che è maggiore di -1.049) e lasciava passare un rimborso di -2.000.
     */
    public static function exceedsBalance(float $total, float $balance, float $amount): bool
    {
        $sign = self::documentSign($total);

        return self::round2($amount * $sign) > self::round2($balance * $sign);
    }

    /**
     * Se il documento va portato a "Pagato" (stato 4).
     *
     * La regola di CI3 (Mdl_invoice_amounts::calculate) era "saldo a zero",
     * con un totale diverso da zero — una fattura vuota ha saldo zero ma non
     * è stata pagata — oppure trattandosi di una nota di credito, che può
     * chiudersi a zero per definizione. Qui conta anche il saldo "oltre lo
     * zero": un incasso arrivato con un centesimo in più lasciava la fattura
     * "Inviata" per sempre, fuori dallo scadenzario (saldo non positivo) ma
     * mai chiusa. Il verso si legge dal segno del totale, così vale anche
     * per la nota di credito, che internamente è negativa e si chiude con un
     * rimborso. Le bozze restano bozze: è l'utente a emetterle, non un
     * ricalcolo.
     */
    public static function shouldMarkPaid(int $statusId, float $total, float $balance, bool $isCredit): bool
    {
        if ($statusId === 1 || $statusId === 4) {
            return false;
        }

        return self::isSettled($total, $balance, $isCredit);
    }

    /**
     * Se un documento "Pagato" (stato 4) va riaperto perché il saldo non è
     * più chiuso.
     *
     * shouldMarkPaid() lavora in un verso solo: portava la fattura a
     * "Pagato" ma nessuno la riportava indietro. Eliminando uno di due
     * pagamenti, o correggendone l'importo al ribasso, la fattura restava
     * "Pagato" con un saldo aperto; e poiché scadenzario, solleciti e
     * "da incassare" escludono lo stato 4, quel residuo spariva da tutti i
     * conti senza che nessuno l'avesse incassato. PaymentModel::delete()
     * copriva solo il caso "nessun pagamento rimasto".
     *
     * Si riapre solo se ci sono pagamenti registrati: senza, lo stato
     * "Pagato" è stato scelto a mano dall'utente (incasso tenuto fuori dal
     * gestionale) e un ricalcolo qualsiasi — una riga modificata — non deve
     * smentirlo.
     */
    public static function shouldReopen(int $statusId, float $total, float $balance, bool $isCredit, bool $hasPayments): bool
    {
        return $statusId === 4 && $hasPayments && !self::isSettled($total, $balance, $isCredit);
    }

    /**
     * Saldo chiuso, nel verso del documento. Vedi shouldMarkPaid() per la
     * regola: zero o oltre lo zero, con la fattura vuota che non conta come
     * pagata salvo sia una nota di credito.
     */
    private static function isSettled(float $total, float $balance, bool $isCredit): bool
    {
        $total   = self::round2($total);
        $balance = self::round2($balance);

        if ($total == 0.0) {
            return $isCredit && $balance == 0.0;
        }

        return $balance == 0.0 || ($balance < 0) === ($total > 0);
    }

    /**
     * Formato decimale del tracciato (punto, due decimali), lo stesso di
     * FatturaPA::dec() — duplicato qui per non legare un servizio puro alla
     * libreria XML.
     */
    public static function dec(float $value): string
    {
        return number_format(self::round2($value), 2, '.', '');
    }
}
