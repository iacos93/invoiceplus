<?php

namespace App\Controllers;

use App\Libraries\BankReconciler;
use App\Libraries\StatementImporter;
use App\Models\BankAccountModel;
use App\Models\BankTransactionModel;
use App\Models\ExpenseModel;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;
use App\Services\InvoiceMath;

/**
 * Reconciliation controller — movimenti bancari e loro abbinamento.
 *
 * I movimenti entrano in due modi:
 *   - import dell'estratto conto (CSV o CAMT.053), che funziona con qualunque
 *     banca senza accordi
 *   - sincronizzazione automatica tramite aggregatore PSD2, quando il conto è
 *     collegato (vedi BankSync)
 *
 * L'abbinamento propone, non decide: assegnare un incasso alla fattura
 * sbagliata la fa risultare pagata quando non lo è, ed è un errore che si
 * scopre tardi e si corregge a mano.
 */
class Reconciliation extends BaseController
{
    protected BankTransactionModel $transactionModel;

    public function __construct()
    {
        $this->transactionModel = new BankTransactionModel();
    }

    public function index()
    {
        $request = service('request');

        $state = (string) ($request->getGet('state') ?? 'open');
        $state = in_array($state, ['open', 'done', ''], true) ? $state : 'open';

        $transactions = $this->transactionModel->getAll($state, selected_year());
        $reconciler   = new BankReconciler();

        // I candidati si calcolano solo per i movimenti da abbinare: su quelli
        // già chiusi sarebbe lavoro sprecato.
        foreach ($transactions as &$transaction) {
            $transaction['candidates'] = !db_bool($transaction['is_reconciled'])
                ? $reconciler->candidatesFor($transaction)
                : [];
        }
        unset($transaction);

        return view('reconciliation/index', [
            'transactions' => $transactions,
            'state'        => $state,
            'accounts'     => (new BankAccountModel())->getAllOrdered(),
        ]);
    }

    /* ------------------------------------------------------ import file */

    public function import()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $file      = $request->getFile('statement');
        $accountId = (int) $request->getPost('bank_account_id') ?: null;

        if (!$file || !$file->isValid()) {
            session()->setFlashdata('alert_error', 'Nessun file valido selezionato.');

            return redirect()->to('/reconciliation');
        }

        $movements = (new StatementImporter())->parse($file->getTempName(), $file->getClientName());

        if (!$movements) {
            session()->setFlashdata('alert_error',
                'Nessun movimento riconosciuto. Sono supportati CSV con intestazione (Data, Descrizione, Importo oppure Entrate/Uscite) e CAMT.053.');

            return redirect()->to('/reconciliation');
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($movements as $movement) {
            $hash = $this->transactionModel->hashFor(
                $movement['date'], $movement['amount'], $movement['description'], $accountId);

            // Estratti conto che si sovrappongono nel periodo sono la norma:
            // senza l'impronta ogni reimport duplicherebbe i movimenti.
            if ($this->transactionModel->existsByHash($hash)) {
                $skipped++;
                continue;
            }

            $this->transactionModel->insert([
                'bank_account_id'         => $accountId,
                'transaction_date'        => $movement['date'],
                'transaction_amount'      => $movement['amount'],
                'transaction_description' => $movement['description'],
                'transaction_hash'        => $hash,
                'is_reconciled'           => false,
                // insert() non passa da saveRecord(), che è il punto in cui
                // l'azienda verrebbe assegnata da sola: senza questa riga i
                // movimenti importati nascevano con company_id NULL e, per la
                // regola che rende visibili le righe senza azienda, comparivano
                // nella riconciliazione di tutte le società.
                'company_id'              => active_company_id(),
            ]);

            $imported++;
        }

        $message = $imported . ' movimenti importati';

        if ($skipped) {
            $message .= ', ' . $skipped . ' già presenti (saltati)';
        }

        session()->setFlashdata($imported ? 'alert_success' : 'alert_info', $message);

        return redirect()->to('/reconciliation');
    }

    /* -------------------------------------------------------- abbinamento */

    public function match()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $transactionId = (int) $request->getPost('transaction_id');
        $target        = (string) $request->getPost('target');

        // ip_bank_transactions ha company_id, quindi il findScoped() generico
        // di IpBaseModel basta: il movimento da abbinare arriva dal POST.
        $transaction = $this->transactionModel->findScoped($transactionId);

        if (!$transaction) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Un movimento si abbina una volta sola. Senza questo controllo un
        // doppio clic, o Indietro e reinvio del form, registrava lo stesso
        // incasso due volte sulla fattura (o raddoppiava il pagato della
        // spesa): la fattura risultava saldata due volte e il doppione si
        // scopriva solo alla quadratura. Per riabbinarlo si annulla prima.
        if (db_bool($transaction['is_reconciled'])) {
            session()->setFlashdata('alert_error',
                'Movimento già abbinato: annulla l\'abbinamento prima di collegarlo a un altro documento.');

            return redirect()->to('/reconciliation');
        }

        // "tipo:id" — un solo campo invece di due che potrebbero discordare.
        [$type, $id] = array_pad(explode(':', $target, 2), 2, null);
        $id          = (int) $id;

        if ($type === 'invoice' && $id) {
            $attached = $this->attachInvoice($transaction, $id);
        } elseif ($type === 'expense' && $id) {
            $attached = $this->attachExpense($transaction, $id);
        } else {
            session()->setFlashdata('alert_error', 'Documento da abbinare non valido.');

            return redirect()->to('/reconciliation');
        }

        // I due metodi rinunciavano in silenzio quando il documento non era nel
        // perimetro, ma il messaggio di successo veniva stampato lo stesso: chi
        // riconcilia leggeva "abbinato correttamente" su un movimento rimasto
        // aperto. In riconciliazione bancaria un falso positivo del genere e'
        // peggio di un errore, perche' si scopre solo alla quadratura.
        if (!$attached) {
            session()->setFlashdata('alert_error',
                'Documento da abbinare non trovato in questa azienda: abbinamento non eseguito.');

            return redirect()->to('/reconciliation');
        }

        session()->setFlashdata('alert_success', 'Movimento abbinato correttamente.');

        return redirect()->to('/reconciliation');
    }

    /**
     * Incasso: si registra un pagamento sulla fattura, così il saldo e i report
     * restano quelli di sempre invece di avere una seconda verità.
     */
    private function attachInvoice(array $transaction, int $invoiceId): bool
    {
        $invoice = (new InvoiceModel())->getByIdScoped($invoiceId);

        if (!$invoice) {
            return false;
        }

        // saveRecord() (non insert()) perché è lì che PaymentModel ricalcola
        // gli importi della fattura: con insert() il saldo resterebbe quello
        // di prima e la fattura apparirebbe ancora da incassare.
        (new PaymentModel())->saveRecord(null, [
            'invoice_id'        => $invoiceId,
            'payment_date'      => $transaction['transaction_date'],
            // Col segno del documento: il movimento che salda una nota di
            // credito è un rimborso e la riporta a zero solo se registrato
            // negativo, come fa il form pagamenti (InvoiceMath::documentSign).
            'payment_amount'    => abs((float) $transaction['transaction_amount'])
                * InvoiceMath::documentSign((float) $invoice['invoice_total']),
            'payment_note'      => 'Riconciliazione bancaria: ' . mb_substr((string) $transaction['transaction_description'], 0, 180),
            'payment_method_id' => (int) ($invoice['payment_method'] ?? 0),
        ]);

        $this->transactionModel->update($transaction['transaction_id'], [
            'invoice_id'    => $invoiceId,
            'expense_id'    => null,
            'is_reconciled' => true,
        ]);

        return true;
    }

    /**
     * Pagamento di una spesa: aumenta il pagato, senza superare il totale.
     */
    private function attachExpense(array $transaction, int $expenseId): bool
    {
        $expenseModel = new ExpenseModel();
        // find() non applicava il perimetro: l'expense_id arriva dal POST dentro
        // "expense:{id}", quindi si incrementava il pagato di una spesa di
        // un'altra azienda.
        $expense      = $expenseModel->findScoped($expenseId);

        if (!$expense) {
            return false;
        }

        // Una nota di credito ricevuta (totale negativo) si "paga" con un
        // rimborso del fornitore: il pagato scende verso il totale invece di
        // salire, e il tetto è il totale nel verso del documento — con min()
        // una nota da -100 risultava saldata al primo abbinamento.
        $total = (float) $expense['expense_total'];
        $sign  = InvoiceMath::documentSign($total);
        $paid  = (float) $expense['expense_paid'] + abs((float) $transaction['transaction_amount']) * $sign;

        $expenseModel->update($expenseId, [
            'expense_paid' => $sign < 0 ? max($paid, $total) : min($paid, $total),
        ]);

        $this->transactionModel->update($transaction['transaction_id'], [
            'expense_id'    => $expenseId,
            'invoice_id'    => null,
            'is_reconciled' => true,
        ]);

        return true;
    }

    public function unmatch(int $transactionId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Nessuna lettura precedeva la update: con l'id nell'URL si slegava il
        // movimento gia' riconciliato di un'altra azienda, che tornava fra
        // quelli da abbinare mentre il pagamento sulla fattura restava.
        $transaction = $this->transactionModel->findScoped($transactionId);
        if (!$transaction) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Annullare disfa quello che l'abbinamento ha fatto. Prima si slegava
        // solo il movimento e il pagamento restava: chi correggeva un
        // abbinamento sbagliato e rifaceva quello giusto si ritrovava la
        // prima fattura incassata due volte (o una spesa "pagata" che nessun
        // form permette di riportare indietro), scoperto solo in quadratura.
        $reverted = false;
        if (!empty($transaction['invoice_id'])) {
            $reverted = $this->detachInvoice($transaction);
        } elseif (!empty($transaction['expense_id'])) {
            $reverted = $this->detachExpense($transaction);
        }

        $this->transactionModel->update($transactionId, [
            'invoice_id'    => null,
            'expense_id'    => null,
            'is_reconciled' => false,
        ]);

        session()->setFlashdata('alert_info', $reverted
            ? 'Abbinamento annullato e pagamento collegato rimosso.'
            : 'Abbinamento annullato. Nessun pagamento automatico da rimuovere: se ne era stato registrato uno a mano, resta sulla fattura.');

        return redirect()->to('/reconciliation');
    }

    /**
     * Toglie il pagamento creato da attachInvoice() per questo movimento.
     * Lo si riconosce da fattura, data, importo e dalla nota automatica
     * "Riconciliazione bancaria:": un incasso inserito a mano non ha quella
     * nota e non viene mai toccato. delete() di PaymentModel ricalcola il
     * saldo e riapre la fattura se era l'ultimo pagamento.
     */
    private function detachInvoice(array $transaction): bool
    {
        $paymentModel = new PaymentModel();
        $amount       = abs((float) $transaction['transaction_amount']);
        $payment      = null;

        // L'importo si confronta in PHP: il segno dipende dal documento (una
        // nota di credito si salda con un pagamento negativo).
        foreach ($paymentModel
            ->where('invoice_id', (int) $transaction['invoice_id'])
            ->where('payment_date', $transaction['transaction_date'])
            ->like('payment_note', 'Riconciliazione bancaria:', 'after')
            ->orderBy('payment_id', 'DESC')
            ->findAll() as $candidate) {
            if (abs(abs((float) $candidate['payment_amount']) - $amount) < 0.005) {
                $payment = $candidate;
                break;
            }
        }

        if (!$payment) {
            return false;
        }

        $paymentModel->delete((int) $payment['payment_id']);

        return true;
    }

    /** Inverso di attachExpense(): il pagato torna indietro dell'importo del movimento. */
    private function detachExpense(array $transaction): bool
    {
        $expenseModel = new ExpenseModel();
        $expense      = $expenseModel->findScoped((int) $transaction['expense_id']);

        if (!$expense) {
            return false;
        }

        $sign = InvoiceMath::documentSign((float) $expense['expense_total']);
        $paid = (float) $expense['expense_paid'] - abs((float) $transaction['transaction_amount']) * $sign;

        // Mai oltre lo zero: se il pagato era stato corretto a mano nel
        // frattempo, si torna al massimo a "non pagato".
        $expenseModel->update((int) $expense['expense_id'], [
            'expense_paid' => $sign < 0 ? min($paid, 0) : max($paid, 0),
        ]);

        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return true;
    }
}
