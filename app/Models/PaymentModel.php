<?php

namespace App\Models;

use App\Services\InvoiceMath;

/**
 * PaymentModel
 *
 * Porting CI4 di Mdl_Payments (CI3): pagamenti (tabella ip_payments)
 * con join su metodo di pagamento, fattura, cliente e importi fattura;
 * liste per area admin e per utenti guest (clienti assegnati) e
 * ricerca per parole chiave (modulo filter).
 */
class PaymentModel extends IpBaseModel
{
    protected $table            = 'ip_payments';
    protected $primaryKey       = 'payment_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'payment_date',
        'payment_amount',
        'payment_method_id',
        'payment_note',
        'payment_metadate',
        'payment_metaoriginal',
        'payment_metaerror',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'invoice_id'   => 'required',
            'payment_date' => 'required',
            'payment_amount' => 'required',
            // Facoltativi, ma vanno elencati: dbArrayFromPost() tiene solo le
            // chiavi presenti qui, e senza queste metodo e nota del pagamento
            // si perdevano a ogni salvataggio dal form.
            'payment_method_id' => 'permit_empty',
            'payment_note'      => 'permit_empty',
        ];
    }

    /**
     * Validazione base + regola custom sull'importo rispetto al saldo
     * (equivalente di callback_validate_payment_amount CI3).
     */
    public function validate($row): bool
    {
        $data      = is_array($row) ? $row : (array) $row;
        $paymentId = (int) ($data['payment_id'] ?? 0);
        $valid     = parent::validate($row);

        if ($valid && !$this->validatePaymentAmount($data, $paymentId)) {
            return false;
        }

        return $valid;
    }

    /**
     * Select di base con i join (metodo, fattura, cliente, importi),
     * equivalente di default_select() + default_join() CI3.
     */
    private function withJoins(): static
    {
        $this->select("ip_payment_methods.payment_method_name, ip_invoice_amounts.*,
            ip_clients.client_name, ip_clients.client_surname, ip_clients.client_id,
            ip_invoices.invoice_number, ip_invoices.invoice_date_created,
            ip_invoices.invoice_date_due, ip_invoices.invoice_url_key, ip_payments.*", false)
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_payments.invoice_id')
            ->join('ip_clients', 'ip_clients.client_id = ip_invoices.client_id')
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left')
            ->join('ip_payment_methods', 'ip_payment_methods.payment_method_id = ip_payments.payment_method_id', 'left');

        // ip_payments non ha company_id: l'azienda e' quella della fattura
        // pagata, che e' gia' in join.
        $this->scopeToCompany(null, 'ip_invoices');

        return $this;
    }

    /**
     * Pagamento dell'azienda attiva, o null se appartiene a un'altra.
     *
     * Override necessario: ip_payments non ha company_id, quindi il
     * findScoped() generico di IpBaseModel qui farebbe da no-op e tornerebbe
     * i pagamenti di tutte le aziende. L'azienda si legge dalla fattura
     * pagata, come gia' fa withJoins().
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->select('ip_payments.*')
                    ->join('ip_invoices', 'ip_invoices.invoice_id = ip_payments.invoice_id')
                    ->scopeToCompany(null, 'ip_invoices')
                    ->where('ip_payments.payment_id', $id)
                    ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Tutti i pagamenti ordinati per data (equivalente di result() CI3).
     */
    public function getAllOrdered(): array
    {
        return $this->withJoins()
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->orderBy('ip_payments.payment_id', 'DESC')
            ->findAll();
    }

    /**
     * Pagamenti incassati nell'intervallo indicato, estremi inclusi, in ordine
     * cronologico; con entrambi gli estremi a null ritorna tutto lo storico.
     *
     * Come per fatture e preventivi: l'esportazione leggeva tutti i pagamenti
     * e poi scartava in PHP quelli fuori periodo.
     */
    public function getInPeriod(?string $from = null, ?string $to = null): array
    {
        $this->withJoins();

        if ($from !== null) {
            $this->where('ip_payments.payment_date >=', $from);
        }

        if ($to !== null) {
            $this->where('ip_payments.payment_date <=', $to);
        }

        return $this->orderBy('ip_payments.payment_date', 'ASC')
                    ->orderBy('ip_payments.payment_id', 'ASC')
                    ->findAll();
    }

    /**
     * Alias per compatibilità con il modulo payments (admin).
     */
    public function getAllWithJoins(): array
    {
        return $this->getAllOrdered();
    }

    /**
     * Pagamenti di una specifica fattura.
     */
    public function getByInvoice(int $invoiceId): array
    {
        return $this->withJoins()
            ->where('ip_payments.invoice_id', $invoiceId)
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->findAll();
    }

    /**
     * Pagamenti di un cliente.
     */
    public function byClient(int $clientId): array
    {
        return $this->withJoins()
            ->where('ip_invoices.client_id', $clientId)
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->findAll();
    }

    /**
     * db_array con normalizzazione importo e data
     * (equivalente di db_array() in Mdl_Payments CI3).
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        if (!empty($dbArray['payment_date'])) {
            $dbArray['payment_date'] = date_to_mysql($dbArray['payment_date']);
        }

        if (!empty($dbArray['payment_amount'])) {
            $dbArray['payment_amount'] = standardize_amount($dbArray['payment_amount']);
        }

        return $dbArray;
    }

    /**
     * Salva (insert/update) e ricalcola gli importi della fattura
     * (equivalente di Mdl_Payments::save() CI3).
     */
    public function saveRecord(?int $id, array $dbArray)
    {
        $invoiceId = $dbArray['invoice_id'] ?? null;

        // In modifica il form permette di spostare il pagamento su un'altra
        // fattura aperta: va ricalcolata anche quella di partenza, che
        // altrimenti continuava a contare l'incasso nel proprio pagato e
        // restava saldata (o con un saldo troppo basso) senza più averlo.
        $previousInvoiceId = $id ? (int) ($this->find($id)['invoice_id'] ?? 0) : 0;

        // insert()/update() del Model CI4 rieseguono validate() sui dati gia'
        // normalizzati da dbArrayFromPost(): validatePaymentAmount() ripassava
        // "1742.98" da standardize_amount(), che legge il punto come separatore
        // delle migliaia (174298), e l'insert falliva in silenzio dietro un
        // "Pagamento creato correttamente". La validazione spetta al chiamante,
        // sull'input grezzo (Payments::form, PaymentsAjax::add).
        $this->skipValidation(true);

        try {
            $paymentId = parent::saveRecord($id, $dbArray);
        } finally {
            $this->skipValidation(false);
        }

        if ($previousInvoiceId && $previousInvoiceId !== (int) $invoiceId) {
            (new InvoiceAmountModel())->calculate($previousInvoiceId);
        }

        if ($invoiceId) {
            (new InvoiceAmountModel())->calculate((int) $invoiceId);
            // Il pagamento cambia invoice_paid/invoice_balance, che alimentano
            // il report "Utile e imposte": senza invalidare la cache quello
            // resterebbe fermo all'incasso precedente per un'ora.
            if (function_exists('invalidate_finance_cache')) {
                invalidate_finance_cache();
            }
        }

        return $paymentId;
    }

    /**
     * Elimina e ricalcola gli importi della fattura; se era l'ultimo
     * pagamento riporta la fattura a "inviata" (equivalente di
     * Mdl_Payments::delete() CI3).
     */
    public function delete($id = null, bool $purge = false)
    {
        $payment = $this->find($id);
        $result  = parent::delete($id, $purge);

        if ($result && $payment) {
            $invoiceId = (int) $payment['invoice_id'];

            $remaining = $this->where('invoice_id', $invoiceId)->countAllResults();
            if ($remaining === 0) {
                $this->db->table('ip_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('invoice_status_id', 4)
                    ->update(['invoice_status_id' => 2]);
            }

            (new InvoiceAmountModel())->calculate($invoiceId);

            // Stesso motivo di saveRecord: il saldato cambia i totali del
            // report Finanza, va invalidata la cache.
            if (function_exists('invalidate_finance_cache')) {
                invalidate_finance_cache();
            }
        }

        return $result;
    }

    /**
     * Errori di validazione registrati con setError() (il Model CI4 non
     * espone piu' il metodo che il porting CI3 usava: senza questo override
     * un pagamento oltre il saldo crashava invece di mostrare l'errore).
     *
     * @var array<string, string>
     */
    private array $legacyErrors = [];

    /**
     * Registra un errore di validazione (equivalente di set_error() CI3).
     */
    protected function setError(string $field, string $message): void
    {
        $this->legacyErrors[$field] = $message;
    }

    /**
     * Espone gli errori registrati; in assenza si ripiega su quelli del DB.
     */
    protected function doErrors(): array
    {
        return $this->legacyErrors !== [] ? $this->legacyErrors : parent::doErrors();
    }

    /**
     * Valida l'importo del pagamento: non deve superare il saldo della
     * fattura (equivalente di callback_validate_payment_amount CI3).
     * Ritorna true se valido, altrimenti registra l'errore e false.
     */
    public function validatePaymentAmount(array $post, int $paymentId = 0): bool
    {
        $invoiceId   = (int) ($post['invoice_id'] ?? 0);
        $amount      = (float) standardize_amount((string) ($post['payment_amount'] ?? 0));

        $invoiceAmount = (new InvoiceAmountModel())->where('invoice_id', $invoiceId)->first();
        $balance       = (float) ($invoiceAmount['invoice_balance'] ?? 0);
        $total         = (float) ($invoiceAmount['invoice_total'] ?? 0);

        // In modifica, il pagamento corrente fa già parte del saldo — ma solo
        // del saldo della sua fattura. Il form permette di spostarlo su
        // un'altra: sommarlo anche lì gonfiava il saldo della fattura di
        // arrivo, che accettava un incasso superiore al dovuto.
        if ($paymentId) {
            $current = $this->find($paymentId);
            if ($current && (int) $current['invoice_id'] === $invoiceId) {
                $balance += (float) $current['payment_amount'];
            }
        }

        // Nel verso del documento: su una nota di credito il confronto
        // "importo > saldo" era rovesciato (vedi InvoiceMath::exceedsBalance).
        if (InvoiceMath::exceedsBalance($total, $balance, $amount)) {
            $this->setError('payment_amount', $total < 0
                ? 'Il rimborso non può superare il residuo della nota di credito (' . format_currency(abs($balance)) . ')'
                : 'L\'importo del pagamento non può superare il saldo della fattura (' . format_currency($balance) . ')');
            return false;
        }

        return true;
    }

    /**
     * Mette sull'importo del POST il segno del documento.
     *
     * Su una nota di credito (totale negativo) il pagamento è un rimborso e
     * va registrato negativo, ma nel form lo si scrive in positivo, come
     * compare sull'estratto conto: chi scriveva "1.049,00" su una nota da
     * -1.049 la portava a -2.098 di saldo. Si lavora sulla stringa e non sul
     * numero perché l'importo è ancora nel formato locale: convertirlo qui e
     * ripassarlo da standardize_amount() leggerebbe il punto decimale come
     * separatore delle migliaia (vedi saveRecord()).
     *
     * Le fatture ordinarie restano come scritte: un pagamento negativo su
     * una fattura (restituzione di un incasso doppio) è legittimo.
     */
    public function applyDocumentSign(array $post): array
    {
        $amount = trim((string) ($post['payment_amount'] ?? ''));

        if ($amount === '' || empty($post['invoice_id'])) {
            return $post;
        }

        $invoiceAmount = (new InvoiceAmountModel())->where('invoice_id', (int) $post['invoice_id'])->first();

        if ($invoiceAmount && InvoiceMath::documentSign((float) $invoiceAmount['invoice_total']) < 0) {
            $post['payment_amount'] = '-' . ltrim($amount, '+- ');
        }

        return $post;
    }

    /**
     * Pagamenti relativi a fatture dei clienti assegnati a un utente guest
     * (equivalente della where nel controller Payments CI3 guest).
     */
    public function getForGuestClients(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        return $this->withJoins()
            ->whereIn('ip_invoices.client_id', $clientIds)
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->orderBy('ip_payments.payment_id', 'DESC')
            ->findAll();
    }

    /**
     * Quanti pagamenti vedrebbe getForGuestClients(): stessi join (da li'
     * arriva il perimetro azienda), ma senza caricare le righe.
     */
    public function countForGuestClients(array $clientIds): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        return $this->withJoins()
            ->whereIn('ip_invoices.client_id', $clientIds)
            ->countAllResults();
    }

    /**
     * Ricerca per parole chiave su data, numero fattura, cliente, importo,
     * metodo e nota (equivalente di filter_payments() CI3).
     */
    public function filterByKeywords(array $keywords): array
    {
        $this->withJoins();

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $keyword = strtolower($keyword);
            $this->likeExpression(
                "CONCAT_WS('^', ip_payments.payment_date, LOWER(ip_invoices.invoice_number),
                    LOWER(CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname)),
                    ip_payments.payment_amount, LOWER(COALESCE(ip_payment_methods.payment_method_name, '')),
                    LOWER(COALESCE(ip_payments.payment_note, '')))",
                $keyword
            );
        }

        return $this->orderBy('ip_payments.payment_date', 'DESC')
            ->orderBy('ip_payments.payment_id', 'DESC')
            ->findAll();
    }
    
}