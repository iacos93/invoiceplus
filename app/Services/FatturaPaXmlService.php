<?php

namespace App\Services;

use App\Models\ClientCustomModel;
use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\UserCustomModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Tracciato della fattura elettronica (FatturaPA).
 *
 * Era il corpo di Invoices::generateXml: cinquecentocinquanta righe che nessun
 * test attraversava, perche' stavano dentro un controller e finivano dritte in
 * una risposta HTTP. Un errore qui non si vede in pagina, si vede come scarto
 * dello SdI. Come servizio il tracciato si genera e si controlla da solo.
 */
class FatturaPaXmlService
{
    protected InvoiceModel $invoiceModel;

    public function __construct(?InvoiceModel $invoiceModel = null)
    {
        $this->invoiceModel = $invoiceModel ?? new InvoiceModel();
    }
    /**
     * Compone l'XML della fattura elettronica.
     *
     * Restituisce tracciato e nome file e non tocca la risposta HTTP: cosi' lo
     * stesso codice serve al download, all'invio allo SdI e ai test.
     */
    public function build(int $invoiceId): array
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoiceObj = (object) $invoice;
        $userId     = (int) $invoice['user_id'];
        $clientId   = (int) $invoice['client_id'];

        // Campi custom collegati tramite variabili d'ambiente IT_*
        $customs = [];

        // utente: regime fiscale, natura IVA 0, progressivo XML
        foreach ([
            'IT_UTENTE_REGIMEFISC_ID' => 'utente_regimefisc',
            'IT_UTENTE_NATURA_IVA0_ID' => 'utente_natura_iva0',
            'IT_UTENTE_PROGR_XML_ID'   => 'utente_progr_xml',
        ] as $env => $key) {
            $customs[$key] = null;
            $fieldId = env($env);
            if ($fieldId) {
                $row = (new UserCustomModel())->where('user_id', $userId)
                    ->where('user_custom_fieldid', $fieldId)
                    ->first();
                $customs[$key] = $row && $row['user_custom_fieldvalue'] ? $row['user_custom_fieldvalue'] : null;
            }
        }

        // cliente: formato xml, codice e/o pec SdI
        foreach ([
            'IT_CLIENTE_FORMATO_XML_ID' => 'cliente_formato_xml',
            'IT_CLIENTE_SDI_CODICE_ID'  => 'cliente_sdi_codice',
            'IT_CLIENTE_SDI_PEC_ID'     => 'cliente_sdi_pec',
        ] as $env => $key) {
            $customs[$key] = null;
            $fieldId = env($env);
            if ($fieldId) {
                $row = (new ClientCustomModel())->where('client_id', $clientId)
                    ->where('client_custom_fieldid', $fieldId)
                    ->first();
                $customs[$key] = $row && $row['client_custom_fieldvalue'] ? $row['client_custom_fieldvalue'] : null;
            }
        }

        try {
            // Formato dal tipo di cliente: FPA12 verso la pubblica
            // amministrazione, FPR12 verso privati e aziende. Il campo custom
            // resta come override esplicito per i casi particolari.
            // La fattura Carta del Docente è sempre verso la PA: il documento
            // che liquida il buono è intestato al Ministero dell'Istruzione,
            // non al docente che ha acquistato.
            // db_bool(): su PostgreSQL il booleano arriva come 't'/'f', e
            // (int) 't' === 1 era sempre falso — la fattura Carta del Docente
            // usciva come una fattura ordinaria verso il docente.
            $isCartaDocente = db_bool($invoice['invoice_carta_docente'] ?? false);
            $clientType     = $invoice['client_type'] ?? 'company';
            $formato        = $customs['cliente_formato_xml'] ?: ($clientType === 'pa' ? 'FPA12' : 'FPR12');

            if ($isCartaDocente) {
                $formato = 'FPA12';
            }

            $fatturapa  = new \App\Libraries\fatturapa\FatturaPA($formato);

            // Mittente (cedente/prestatore). I dati vengono dall'azienda del
            // documento, non dal profilo utente: con piu' soggetti giuridici il
            // profilo ne descrive uno solo, quindi una fattura emessa dalla
            // seconda azienda usciva con la partita IVA della prima. E la
            // ragione sociale era 'user_name', cioe' il nome dell'account
            // ("Admin"), non la denominazione del soggetto.
            $fatturapa->set_mittente([
                'ragsoc'    => $invoice['emitter_name'] ?? '',
                'indirizzo' => $invoice['emitter_address_1'] ?? '',
                'cap'       => $invoice['emitter_zip'] ?? '',
                'comune'    => $invoice['emitter_city'] ?? '',
                'prov'      => $invoice['emitter_state'] ?? '',
                'paese'     => ($invoice['emitter_country'] ?? '') ?: 'IT',
                'piva'      => ($invoice['emitter_vat_id'] ?? '') ?: null,
                'codfisc'   => ($invoice['emitter_tax_code'] ?? '') ?: null,
                'regimefisc' => $customs['utente_regimefisc'],
            ]);

            // Destinatario. Una persona fisica va in <Nome>/<Cognome>, non in
            // <Denominazione>: sono rami alternativi dello stesso nodo, e
            // riempire quello sbagliato fa scartare la fattura. Il ramo
            // nome/cognome viene impostato dopo, perche' la libreria mappa
            // solo la denominazione.
            // Per la Carta del Docente il destinatario non è il cliente del
            // documento (il docente, che resta destinatario della fattura
            // normale): è l'ufficio del Ministero incaricato della
            // liquidazione, con dati fissi indicati dalle linee guida
            // dell'iniziativa (codice ufficio QGGT71, CF 80185250588).
            $isPerson = $clientType === 'person' && trim((string) $invoice['client_surname']) !== '';

            if ($isCartaDocente) {
                $fatturapa->set_destinatario([
                    'ragsoc'     => trans('miur_name', '', 'Ministero dell\'Istruzione e del Merito'),
                    'indirizzo'  => 'Viale Trastevere 76/A',
                    'cap'        => '00153',
                    'comune'     => 'Roma',
                    'prov'       => 'RM',
                    'paese'      => 'IT',
                    'codfisc'    => '80185250588',
                    'sdi_codice' => 'QGGT71',
                ]);
            } else {
                $fatturapa->set_destinatario([
                    'ragsoc'     => $isPerson ? null : $invoice['client_name'],
                    'indirizzo'  => $invoice['client_address_1'],
                    'cap'        => $invoice['client_zip'],
                    'comune'     => $invoice['client_city'],
                    'prov'       => $invoice['client_state'],
                    'paese'      => $invoice['client_country'] ?: 'IT',
                    'piva'       => $invoice['client_vat_id'] ?: null,
                    'codfisc'    => $invoice['client_tax_code'] ?: null,
                    'sdi_codice' => $invoice['client_sdi_code'] ?: $customs['cliente_sdi_codice'],
                    'sdi_pec'    => $invoice['client_sdi_pec'] ?: $customs['cliente_sdi_pec'],
                ]);

                if ($isPerson) {
                    $fatturapa->set_node(
                        'FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica',
                        [
                            'Nome'    => $invoice['client_name'],
                            'Cognome' => $invoice['client_surname'],
                        ]
                    );
                }
            }

            // Tipo documento. Una nota di credito creata prima che
            // createCredit() impostasse TD04 ha il campo vuoto: la si
            // riconosce dalla fattura d'origine, invece di mandarla come TD01.
            $isCredit = !empty($invoice['creditinvoice_parent_id']);
            $tipoDoc  = $invoice['invoice_doc_type'] ?: ($isCredit ? 'TD04' : 'TD01');

            // Una nota di credito vive internamente con importi negativi
            // (righe a quantita' negativa, totale negativo: e' cosi' che
            // dashboard e report la sottraggono), ma nel tracciato il TD04
            // va con importi positivi — e' gia' il tipo documento a dire
            // "storno". $flip rovescia righe, sconti e pagamento. Una TD04
            // scritta a mano con importi positivi resta com'e'.
            $flip = ($tipoDoc === 'TD04' && (float) $invoice['invoice_total'] < 0) ? -1 : 1;

            // Dati fattura
            $fatturapa->set_intestazione([
                'tipodoc' => $tipoDoc,
                'valuta'  => 'EUR',
                'data'    => $invoice['invoice_date_created'],
                'numero'  => $invoice['invoice_number'],
            ]);

            // I riferimenti di commessa e i blocchi fiscali (ritenuta, cassa,
            // bollo) appartengono alla fattura ordinaria: la fattura Carta del
            // Docente è fuori campo IVA e non ne porta nessuno.
            // Righe e riepilogo. Il riepilogo e' quello di InvoiceAmountModel
            // (sconto di documento per aliquota, cassa, ritenuta): calcolarlo
            // una volta qui e da li' ricavare righe di sconto, DatiRiepilogo,
            // DatiCassaPrevidenziale e DatiRitenuta e' cio' che tiene il
            // tracciato allineato al totale salvato. Le righe a 0% senza
            // Natura sull'aliquota prendono quella globale, come nel ciclo
            // delle righe piu' sotto, perche' i gruppi siano gli stessi.
            $items = (new ItemModel())->getByInvoice($invoiceId);
            $lines = InvoiceMath::linesFromItems($items);

            foreach ($lines as &$summaryLine) {
                if ($summaryLine['natura'] === null && $summaryLine['rate'] == 0) {
                    $summaryLine['natura'] = $customs['utente_natura_iva0'] ?: null;
                }
            }
            unset($summaryLine);

            $summary = InvoiceMath::documentSummary(
                $lines,
                (float) ($invoice['invoice_discount_amount'] ?? 0),
                (float) ($invoice['invoice_discount_percent'] ?? 0),
                trim((string) ($invoice['invoice_fund_type'] ?? '')) !== '' ? (float) ($invoice['invoice_fund_rate'] ?? 0) : 0.0,
                InvoiceMath::fundSubjectToWithholding($invoice['invoice_fund_type'] ?? null),
                $invoice['invoice_withholding_type'] ?? null,
                (float) ($invoice['invoice_withholding_rate'] ?? 0)
            );

            if (!$isCartaDocente) {
                $this->addEinvoiceReferences($fatturapa, $invoice, $summary, $flip);
            }

            // Fattura rettificata (2.1.6): una nota di credito senza il
            // riferimento al documento che storna e' formalmente valida ma
            // non dice al cessionario cosa sta correggendo, e la PA la
            // respinge. Si cerca la fattura d'origine dentro l'azienda attiva:
            // l'id sta sul documento, ma non per questo va creduto oltre il
            // perimetro.
            if ($isCredit) {
                $parent = $this->invoiceModel->getByIdScoped((int) $invoice['creditinvoice_parent_id']);

                if ($parent && trim((string) $parent['invoice_number']) !== '') {
                    $collegata = ['IdDocumento' => $parent['invoice_number']];

                    if (!empty($parent['invoice_date_created'])) {
                        $collegata['Data'] = $parent['invoice_date_created'];
                    }
                    if (!empty($invoice['invoice_cig'])) {
                        $collegata['CodiceCIG'] = $invoice['invoice_cig'];
                    }
                    if (!empty($invoice['invoice_cup'])) {
                        $collegata['CodiceCUP'] = $invoice['invoice_cup'];
                    }

                    $fatturapa->add_node('FatturaElettronicaBody/DatiGenerali/DatiFattureCollegate', $collegata);
                }
            }

            $hasWithholding = !$isCartaDocente
                && !empty($invoice['invoice_withholding_type'])
                && (float) $invoice['invoice_withholding_rate'] > 0;

            if ($isCartaDocente) {
                $this->addCartaDocenteBody($fatturapa, $invoice);
            } else {
                foreach ($items as $i => $item) {
                    $description = trim((string) $item['item_name']) ? $item['item_name'] : '';
                    if (!empty($item['item_description'])) {
                        if ($description) {
                            $description .= ' - ';
                        }
                        $description .= $item['item_description'];
                    }

                    // Natura IVA della riga: prevale quella censita
                    // sull'aliquota (cosi' anche un'inversione contabile N6
                    // con aliquota piena 22% esce con il suo codice), con
                    // ripiego sul Natura globale delle righe a 0% per non
                    // rompere le fatture gia' emesse senza aliquota nativa.
                    $natura = $item['item_tax_rate_natura'] ?? null;
                    if (!$natura && (float) $item['item_tax_rate_percent'] == 0) {
                        $natura = $customs['utente_natura_iva0'];
                    }

                    // PrezzoTotale è l'imponibile della riga al netto dello
                    // sconto di riga: usare item_subtotal (lordo) faceva
                    // uscire un XML con IVA e totale maggiori del vero
                    // invoice_total ogni volta che c'era uno sconto.
                    // Quantita non ammette il segno: xmlLine() lo sposta sul
                    // prezzo (e per la TD04 rovescia tutta la riga).
                    $line = InvoiceMath::xmlLine(
                        (float) $item['item_quantity'],
                        (float) $item['item_price'],
                        (float) $item['item_subtotal'] - (float) ($item['item_discount'] ?? 0),
                        $flip
                    );

                    $riga = [
                        'num'          => $i + 1,
                        'descrizione'  => $description,
                        'prezzo'       => \App\Libraries\fatturapa\FatturaPA::decUnit($line['price']),
                        'qta'          => \App\Libraries\fatturapa\FatturaPA::decUnit($line['quantity']),
                        'importo'      => \App\Libraries\fatturapa\FatturaPA::dec($line['total']),
                        'perciva'      => \App\Libraries\fatturapa\FatturaPA::dec($item['item_tax_rate_percent']),
                        'natura_iva0'  => $natura ?: null,
                    ];

                    // Sconto di riga: senza ScontoMaggiorazione dentro la
                    // linea, PrezzoTotale (netto) non torna con
                    // Quantita × PrezzoUnitario e lo SdI scarta con 00423.
                    $sconti = InvoiceMath::lineDiscounts(
                        (float) ($item['item_discount_amount'] ?? 0),
                        (float) ($item['item_discount_percent'] ?? 0),
                        $line['sign']
                    );

                    if ($sconti) {
                        $riga['sconti'] = $sconti;
                    }

                    // Con DatiRitenuta nel documento, lo SdI vuole almeno una
                    // linea marcata Ritenuta=SI (scarto 00411): la ritenuta
                    // si calcola sull'intero imponibile, quindi le si marca
                    // tutte.
                    if ($hasWithholding) {
                        $riga['ritenuta'] = 'SI';
                    }

                    $fatturapa->add_riga($riga);
                }

                // Sconto di documento: una riga di sconto (TipoCessionePrestazione
                // SC, importo negativo) per ogni aliquota, con la quota
                // ripartita da documentSummary(). Era un ScontoMaggiorazione
                // nei dati generali, che lo SdI non riporta su DatiRiepilogo:
                // l'imponibile e l'IVA del riepilogo restavano quelli pieni e
                // non tornavano con il totale. Come riga, invece, entra
                // nell'imponibile della sua aliquota e l'IVA si ricalcola sul
                // netto — art. 15 DPR 633/72, lo sconto incondizionato riduce
                // la base imponibile.
                $numeroLinea = count($items);
                $label       = trans('invoice_discount', '', 'Sconto sul documento');

                if ((float) ($invoice['invoice_discount_percent'] ?? 0) != 0) {
                    $label .= ' ' . format_amount((float) $invoice['invoice_discount_percent']) . '%';
                }

                foreach ($summary['buckets'] as $bucket) {
                    if ($bucket['discount'] == 0) {
                        continue;
                    }

                    $importo = \App\Libraries\fatturapa\FatturaPA::dec(-$bucket['discount'] * $flip);
                    $riga    = [
                        'num'         => ++$numeroLinea,
                        'tipo'        => 'SC',
                        'descrizione' => $label,
                        'prezzo'      => $importo,
                        'importo'     => $importo,
                        'perciva'     => \App\Libraries\fatturapa\FatturaPA::dec($bucket['rate']),
                        'natura_iva0' => $bucket['natura'],
                    ];

                    // Lo sconto riduce anche la base della ritenuta.
                    if ($hasWithholding) {
                        $riga['ritenuta'] = 'SI';
                    }

                    $fatturapa->add_riga($riga);
                }
            }

            // Totale. L'esigibilita' e il bollo erano cablati (immediata, bollo
            // automatico): con lo split payment verso la PA o con un bollo che
            // va addebitato comunque, il tracciato usciva sbagliato. La
            // fattura Carta del Docente non ha esigibilita' ne' bollo: la
            // riga e' fuori campo IVA e i totali nascono comunque dalle righe.
            // Il contributo cassa non e' una riga: entra nell'imponibile della
            // sua aliquota attraverso 'extra'.
            $totale = $fatturapa->set_auto_totali(
                $isCartaDocente ? [] : ['esigiva' => $invoice['invoice_vat_exigibility'] ?: 'I'],
                $isCartaDocente ? [] : [
                    'autobollo' => ($invoice['invoice_stamp_mode'] ?? '') !== 'no',
                    'extra'     => $summary['fund_bucket'] !== null
                        ? [$summary['fund_bucket'] => InvoiceMath::round2($summary['fund'] * $flip)]
                        : [],
                ]
            );
            $totale = InvoiceMath::round2($totale);

            // L'Imposta del riepilogo è quella di documentSummary(), calcolata
            // sull'importo esatto delle righe: set_auto_totali() la ricava dai
            // PrezzoTotale già arrotondati, e su un prezzo scorporato (350 €
            // IVA inclusa) scriveva 63,12 dove la fattura salvata dice 63,11.
            if (!$isCartaDocente) {
                $riepiloghi = &$fatturapa->get_node('FatturaElettronicaBody/DatiBeniServizi/DatiRiepilogo');

                if (is_array($riepiloghi)) {
                    $totale = 0.0;

                    foreach ($riepiloghi as &$riepilogo) {
                        $key = InvoiceMath::bucketKey((float) $riepilogo['AliquotaIVA'], $riepilogo['Natura'] ?? null);

                        if (isset($summary['buckets'][$key])) {
                            $riepilogo['Imposta'] = \App\Libraries\fatturapa\FatturaPA::dec($summary['buckets'][$key]['tax'] * $flip);
                        }

                        $totale += (float) $riepilogo['ImponibileImporto'] + (float) $riepilogo['Imposta'];
                    }
                    unset($riepilogo);

                    $totale = InvoiceMath::round2($totale);
                }
                unset($riepiloghi);
            }

            // Totale documento (2.1.1.9): IVA inclusa, al netto degli sconti
            // (gia' nelle righe) e con il contributo cassa, senza togliere la
            // ritenuta. Facoltativo nello schema, ma e' il numero che il
            // cessionario confronta con il suo gestionale: senza, ognuno lo
            // ricostruiva a modo suo.
            $fatturapa->set_node(
                'FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento',
                \App\Libraries\fatturapa\FatturaPA::dec($totale)
            );

            // RiferimentoNormativo: nel riepilogo della fattura Carta del
            // Docente va indicato l'articolo che esclude l'operazione dal
            // campo IVA. La libreria non lo mappa in add_totali(): lo si
            // appende al nodo DatiRiepilogo appena creato, e la serializzazione
            // schema-driven lo mette nella posizione giusta. Attenzione a
            // non castare il nodo a (array): la copia spezza il riferimento
            // e la modifica andrebbe persa.
            if ($isCartaDocente) {
                $riepiloghi = &$fatturapa->get_node('FatturaElettronicaBody/DatiBeniServizi/DatiRiepilogo');

                if (is_array($riepiloghi)) {
                    foreach ($riepiloghi as &$riepilogo) {
                        $riepilogo['RiferimentoNormativo'] = 'Art. 2 DPR 633/72';
                    }
                    unset($riepilogo);
                }
            }

            // Importo che il cliente versa davvero. Con lo split payment
            // (esigibilita' S) la PA paga solo l'imponibile e versa l'IVA
            // all'Erario: in DatiPagamento va il netto, non il totale con
            // IVA. L'IVA e' quella del riepilogo appena calcolato, cosi'
            // l'importo torna con i numeri dello stesso tracciato. La
            // ritenuta, se c'e', la trattiene il cliente: si sottrae anche
            // quella, letta dal blocco DatiRitenuta gia' scritto.
            $importoPagamento = $totale;

            if (!$isCartaDocente) {
                $ivaRiepilogo = 0.0;

                foreach ((array) $fatturapa->get_node('FatturaElettronicaBody/DatiBeniServizi/DatiRiepilogo') as $riepilogo) {
                    $ivaRiepilogo += (float) ($riepilogo['Imposta'] ?? 0);
                }

                $importoPagamento = InvoiceMath::amountDue(
                    $totale,
                    $ivaRiepilogo,
                    $invoice['invoice_vat_exigibility'] ?? null,
                    (float) ($fatturapa->get_node('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/DatiRitenuta/ImportoRitenuta') ?? 0)
                );
            }

            if (!$isCartaDocente && ($invoice['invoice_stamp_mode'] ?? '') === 'si') {
                $fatturapa->set_node('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/DatiBollo', [
                    'BolloVirtuale' => 'SI',
                    'ImportoBollo'  => \App\Libraries\fatturapa\FatturaPA::dec($invoice['invoice_stamp_amount'] ?: 2),
                ]);
            }

            // Pagamento. Per la Carta del Docente è sempre bonifico (MP05)
            // verso il conto indicato: le linee guida dell'iniziativa lo
            // esigono, e una fattura senza IBAN viene rifiutata.
            if ($isCartaDocente) {
                $account = (new \App\Models\BankAccountModel())
                    ->forInvoice(isset($invoice['bank_account_id']) ? (int) $invoice['bank_account_id'] : null);

                $iban = $account['bank_account_iban'] ?? null;

                if (empty($iban) && !empty($invoice['user_iban'])) {
                    $iban = $invoice['user_iban'];
                }

                $modalita = [
                    'modalita' => 'MP05',
                    'totale'   => \App\Libraries\fatturapa\FatturaPA::dec($importoPagamento),
                    'scadenza' => $invoice['invoice_date_due'],
                ];

                if (!empty($iban)) {
                    $modalita['iban'] = \App\Models\BankAccountModel::cleanIban($iban);
                }

                $fatturapa->set_pagamento(['condizioni' => 'TP02'], $modalita);
            } elseif (!empty($invoice['payment_method'])) {
                $paymentMethodId = (int) $invoice['payment_method'];

                // La modalita' sta sull'anagrafica del metodo (Impostazioni >
                // Metodi di pagamento). Prima veniva letta solo da .env, dove
                // non era quasi mai impostata: senza codice il blocco
                // DatiPagamento non veniva creato, e la fattura partiva senza
                // modalita' ne' IBAN. sdiCodeFor() continua a leggere .env come
                // ripiego per le installazioni che l'avevano configurato.
                $method = (new \App\Models\PaymentMethodModel())->find($paymentMethodId)
                    ?: ['payment_method_id' => $paymentMethodId];
                $codiceModalita = \App\Models\PaymentMethodModel::sdiCodeFor($method);

                if ($codiceModalita) {
                    $modalita = [
                        'modalita' => $codiceModalita,
                        'totale'   => \App\Libraries\fatturapa\FatturaPA::dec($importoPagamento),
                        'scadenza' => $invoice['invoice_date_due'],
                    ];

                    // L'IBAN accompagna il pagamento quando il metodo si salda
                    // su conto corrente: lo dice il flag del metodo, non piu' il
                    // solo MP05, perche' anche RiBa, SDD e MAV vogliono le
                    // coordinate. Viene dal conto di saldo indicato sulla
                    // fattura (o dal predefinito), con ripiego sull'IBAN del
                    // profilo utente per chi non ha ancora configurato i conti.
                    // db_bool(): su PostgreSQL il flag arriva come 't'/'f' e
                    // !empty('f') e' vero — l'IBAN finiva anche sui metodi che
                    // non lo prevedono, come la carta di credito (MP08).
                    if (db_bool($method['payment_method_requires_bank_account'] ?? false)) {
                        $account = (new \App\Models\BankAccountModel())
                            ->forInvoice(isset($invoice['bank_account_id']) ? (int) $invoice['bank_account_id'] : null);

                        $iban = $account['bank_account_iban'] ?? null;

                        if (empty($iban) && !empty($invoice['user_iban'])) {
                            $iban = $invoice['user_iban'];
                        }

                        if (!empty($iban)) {
                            $modalita['iban'] = \App\Models\BankAccountModel::cleanIban($iban);
                        }
                    }

                    $fatturapa->set_pagamento(['condizioni' => 'TP02'], $modalita);
                }
            }

            // Progressivo: dal campo custom o dal numero fattura. Qui si LEGGE
            // soltanto.
            //
            // L'avanzamento stava sotto la condizione $stream, che e' vera per
            // il semplice download via GET di invoices/generate_xml/{id}:
            // bastava aprire quell'indirizzo -- un click ripetuto, un refresh,
            // un retry di rete -- per bruciare un progressivo senza che nessuna
            // trasmissione fosse avvenuta, lasciando un buco nella sequenza dei
            // nomi file. Il commento che c'era ("avanza solo per una
            // trasmissione vera") descriveva l'intenzione, non il codice.
            //
            // L'unico punto che deve avanzarlo e' SdiSender::advanceProgressive(),
            // che scatta a esito positivo; ci passa anche il canale manuale,
            // quindi qui non si perde nessun avanzamento legittimo -- se ne
            // toglie uno doppio.
            if (!empty($customs['utente_progr_xml'])) {
                $progr = $customs['utente_progr_xml'];
            } else {
                $num = preg_replace('/[^0-9]/', '', (string) $invoice['invoice_number']);
                $progr = strtoupper(base_convert($num, 10, 36));
            }

            $filename = $fatturapa->filename($progr);
            $xml      = $fatturapa->get_xml();
        } catch (\Throwable $e) {
            // Il fallback esiste per non lasciare l'utente senza file, ma
            // finora inghiottiva l'errore in silenzio: l'XML minimale non
            // passa la validazione XSD e sembrava un difetto del tracciato
            // invece della conseguenza di un'eccezione a monte.
            log_message('error', 'Generazione XML fattura ' . $invoiceId . ' fallita: '
                . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');

            // Fallback: XML minimale con mittente/destinatario/righe
            $xml = $this->fallbackXml($invoice, (new ItemModel())->getByInvoice($invoiceId));
            $progr = '1';
            $filename = 'Fattura_' . $invoiceId . '_' . $progr . '.xml';
        }

        return ['xml' => $xml, 'filename' => $filename];
    }

    private function fallbackXml(array $invoice, array $items): string
    {
        $rows = '';
        foreach ($items as $i => $item) {
            $rows .= '<DettaglioLinee>' .
                '<NumeroLinea>' . ($i + 1) . '</NumeroLinea>' .
                '<Descrizione>' . htmlspecialchars($item['item_name'], ENT_XML1, 'UTF-8') . '</Descrizione>' .
                '<PrezzoUnitario>' . number_format((float) $item['item_price'], 2, '.', '') . '</PrezzoUnitario>' .
                '<Quantita>' . number_format((float) $item['item_quantity'], 2, '.', '') . '</Quantita>' .
                '<PrezzoTotale>' . number_format((float) $item['item_subtotal'], 2, '.', '') . '</PrezzoTotale>' .
                '<AliquotaIVA>' . number_format((float) ($item['item_tax_rate_percent'] ?? 0), 2, '.', '') . '</AliquotaIVA>' .
                '</DettaglioLinee>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">' .
            '<FatturaElettronicaHeader>' .
            '<DatiTrasmissione>' .
            '<IdTrasmittente><IdPaese>' . htmlspecialchars($invoice['user_country'] ?: 'IT') . '</IdPaese><IdCodice>' .
            htmlspecialchars($invoice['emitter_vat_id'] ?: $invoice['emitter_tax_code'] ?: '') . '</IdCodice></IdTrasmittente>' .
            '<ProgressivoInvio>1</ProgressivoInvio><FormatoTrasmissione>FPR12</FormatoTrasmissione>' .
            '<CodiceDestinatario>' . htmlspecialchars(env('IT_CLIENTE_SDI_CODICE_ID') ? '0000000' : '0000000') . '</CodiceDestinatario>' .
            '</DatiTrasmissione>' .
            '<CedentePrestatore><DatiAnagrafici><Anagrafica><Denominazione>' .
            htmlspecialchars($invoice['user_name']) . '</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore>' .
            '<CessionarioCommittente><DatiAnagrafici><Anagrafica><Denominazione>' .
            htmlspecialchars($invoice['client_name']) . '</Denominazione></Anagrafica></DatiAnagrafici></CessionarioCommittente>' .
            '</FatturaElettronicaHeader>' .
            '<FatturaElettronicaBody>' .
            '<DatiGenerali><DatiGeneraliDocumento>' .
            '<TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa>' .
            '<Data>' . htmlspecialchars($invoice['invoice_date_created']) . '</Data>' .
            '<Numero>' . htmlspecialchars($invoice['invoice_number']) . '</Numero>' .
            '</DatiGeneraliDocumento></DatiGenerali>' .
            '<DatiBeniServizi>' . $rows . '</DatiBeniServizi>' .
            '</FatturaElettronicaBody>' .
            '</p:FatturaElettronica>';
    }

    private function addEinvoiceReferences(\App\Libraries\fatturapa\FatturaPA $fatturapa, array $invoice, array $summary, int $flip = 1): void
    {
        $cig = trim((string) ($invoice['invoice_cig'] ?? ''));
        $cup = trim((string) ($invoice['invoice_cup'] ?? ''));

        $blocks = [
            'DatiOrdineAcquisto' => ['invoice_order_number', 'invoice_order_date'],
            'DatiContratto'      => ['invoice_contract_number', 'invoice_contract_date'],
            'DatiConvenzione'    => ['invoice_convention_number', 'invoice_convention_date'],
            'DatiRicezione'      => ['invoice_receipt_number', 'invoice_receipt_date'],
        ];

        $emitted = false;

        foreach ($blocks as $node => [$numberField, $dateField]) {
            $number = trim((string) ($invoice[$numberField] ?? ''));

            if ($number === '') {
                continue;
            }

            $data = ['IdDocumento' => $number];

            if (!empty($invoice[$dateField])) {
                $data['Data'] = $invoice[$dateField];
            }

            // La riga dell'ordine e' significativa solo per l'ordine stesso.
            if ($node === 'DatiOrdineAcquisto' && !empty($invoice['invoice_order_line'])) {
                $data['RiferimentoNumeroLinea'] = $invoice['invoice_order_line'];
            }

            if ($cig !== '') {
                $data['CodiceCIG'] = $cig;
            }
            if ($cup !== '') {
                $data['CodiceCUP'] = $cup;
            }

            $fatturapa->add_node('FatturaElettronicaBody/DatiGenerali/' . $node, $data);
            $emitted = true;
        }

        // CIG/CUP senza alcun riferimento: un DatiOrdineAcquisto con
        // IdDocumento "ND" invece del numero della fattura stessa, che la PA
        // leggeva come un ordine inesistente (vedi EinvoiceReferences). La
        // scheda fattura avvisa finché il numero d'ordine o di determina non
        // viene indicato. La nota di credito rettificativa porta già i codici
        // in DatiFattureCollegate e non ne ha bisogno.
        if (!$emitted && EinvoiceReferences::missingOrderReference($invoice)) {
            $data = ['IdDocumento' => EinvoiceReferences::FALLBACK_ID];

            if ($cig !== '') {
                $data['CodiceCIG'] = $cig;
            }
            if ($cup !== '') {
                $data['CodiceCUP'] = $cup;
            }

            $fatturapa->add_node('FatturaElettronicaBody/DatiGenerali/DatiOrdineAcquisto', $data);
        }

        // Codice con cui l'amministrazione committente identifica il fornitore.
        // Nel tracciato sta sotto CedentePrestatore (1.2.6), non nei dati
        // generali del documento: messo altrove viene semplicemente scartato
        // dall'ordinamento sullo schema e sparisce senza segnalazione.
        if (!empty($invoice['invoice_admin_reference'])) {
            $fatturapa->set_node(
                'FatturaElettronicaHeader/CedentePrestatore/RiferimentoAmministrazione',
                $invoice['invoice_admin_reference']
            );
        }

        // Ritenuta d'acconto e cassa previdenziale: importi dal riepilogo di
        // InvoiceMath::documentSummary(), lo stesso da cui InvoiceAmountModel
        // ricava il totale salvato — imponibile al netto degli sconti di riga
        // e di documento. $flip li rende positivi sulle note di credito TD04:
        // ImportoRitenuta e gli importi della cassa non ammettono il segno.
        if (!empty($invoice['invoice_withholding_type']) && (float) $invoice['invoice_withholding_rate'] > 0) {
            $rate = (float) $invoice['invoice_withholding_rate'];

            $fatturapa->set_node('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/DatiRitenuta', [
                'TipoRitenuta'     => $invoice['invoice_withholding_type'],
                'ImportoRitenuta'  => \App\Libraries\fatturapa\FatturaPA::dec($summary['withholding'] * $flip),
                'AliquotaRitenuta' => \App\Libraries\fatturapa\FatturaPA::dec($rate),
                'CausalePagamento' => $invoice['invoice_withholding_reason'] ?: 'A',
            ]);
        }

        if ($summary['fund_bucket'] !== null) {
            $bucket = $summary['buckets'][$summary['fund_bucket']];

            // AliquotaIVA e' obbligatoria nel blocco: senza, lo schema lo
            // rifiuta anche quando tutto il resto e' corretto. Il contributo
            // segue l'aliquota (o la Natura) della prestazione a cui e'
            // sommato nel riepilogo: AliquotaIVA 0 senza Natura e' lo
            // scarto 00413.
            $cassa = [
                'TipoCassa'              => $invoice['invoice_fund_type'],
                'AlCassa'                => \App\Libraries\fatturapa\FatturaPA::dec((float) $invoice['invoice_fund_rate']),
                'ImportoContributoCassa' => \App\Libraries\fatturapa\FatturaPA::dec($summary['fund'] * $flip),
                'ImponibileCassa'        => \App\Libraries\fatturapa\FatturaPA::dec($summary['fund_base'] * $flip),
                'AliquotaIVA'            => \App\Libraries\fatturapa\FatturaPA::dec($bucket['rate']),
            ];

            // Rivalsa INPS: e' parte del compenso e subisce la ritenuta
            // (vedi InvoiceMath::fundSubjectToWithholding).
            if (!empty($invoice['invoice_withholding_type'])
                && InvoiceMath::fundSubjectToWithholding($invoice['invoice_fund_type'])) {
                $cassa['Ritenuta'] = 'SI';
            }

            if ($bucket['natura'] !== null) {
                $cassa['Natura'] = $bucket['natura'];
            }

            $fatturapa->add_node('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/DatiCassaPrevidenziale', $cassa);
        }
    }

    private function addCartaDocenteBody(\App\Libraries\fatturapa\FatturaPA $fatturapa, array $invoice): void
    {
        $codiceEsercente = trim((string) ($invoice['emitter_carta_docente_code'] ?? ''));

        if ($codiceEsercente === '') {
            $codiceEsercente = trim((string) ($invoice['invoice_admin_reference'] ?? ''));
        }

        if ($codiceEsercente !== '') {
            $fatturapa->set_node(
                'FatturaElettronicaHeader/CedentePrestatore/RiferimentoAmministrazione',
                $codiceEsercente
            );
        }

        $voucher = trim((string) ($invoice['invoice_voucher_code'] ?? ''));
        $totale  = (float) $invoice['invoice_total'];

        $fatturapa->add_node('FatturaElettronicaBody/DatiBeniServizi/DettaglioLinee', [
            'NumeroLinea'   => 1,
            'CodiceArticolo' => [
                'CodiceTipo'   => 'CARTADELDOCENTE',
                'CodiceValore' => $voucher,
            ],
            'Descrizione'   => 'Pagamento Buono',
            'Quantita'      => \App\Libraries\fatturapa\FatturaPA::dec(1),
            'PrezzoUnitario' => \App\Libraries\fatturapa\FatturaPA::dec($totale),
            'PrezzoTotale'  => \App\Libraries\fatturapa\FatturaPA::dec($totale),
            'AliquotaIVA'   => '0.00',
            'Natura'        => 'N2',
        ]);
    }
}
