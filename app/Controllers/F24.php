<?php

namespace App\Controllers;

use App\Models\BankAccountModel;
use App\Libraries\F24ReceiptParser;
use App\Models\F24Model;

/**
 * F24 controller — deleghe di pagamento delle imposte.
 */
class F24 extends BaseController
{
    protected F24Model $model;

    public function __construct()
    {
        $this->model = new F24Model();
    }

    public function index()
    {
        $request = service('request');
        $state   = (string) ($request->getGet('state') ?? '');
        $state   = in_array($state, ['open', 'paid'], true) ? $state : '';

        $items = $this->model->getAll(selected_year(), $state);

        $total = 0.0;
        $open  = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['f24_amount'];
            if (empty($item['f24_paid_date'])) {
                $open += (float) $item['f24_amount'];
            }
        }

        return view('f24/index', [
            'items'         => $items,
            'state'         => $state,
            'period_totals' => ['count' => count($items), 'total' => $total, 'balance' => $open],
        ]);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->model->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            $post['f24_amount']    = standardize_amount($post['f24_amount'] ?? 0) ?: 0;
            $post['f24_due_date']  = date_to_mysql($post['f24_due_date'] ?? '');
            $post['f24_paid_date'] = !empty($post['f24_paid_date']) ? date_to_mysql($post['f24_paid_date']) : null;

            if ($this->model->validate($post)) {
                $dbArray = $this->model->dbArrayFromPost($post);

                if (!$id) {
                    $dbArray['f24_date_created'] = date('Y-m-d H:i:s');
                }

                // Il modello F24 è un documento da conservare: si allega il
                // file ricevuto, non si ridigitano i tributi riga per riga.
                $file = $request->getFile('f24_file');

                if ($file && $file->isValid() && !$file->hasMoved()) {
                    $dbArray['f24_file'] = $this->storeUpload($file);
                }

                // Quietanza caricata a mano: è la strada per quelle che
                // l'importazione non ha saputo abbinare.
                $receipt = $request->getFile('f24_receipt_file');

                if ($receipt && $receipt->isValid() && !$receipt->hasMoved()) {
                    $dbArray['f24_receipt_file'] = $this->storeUpload($receipt);
                }

                $this->model->saveRecord($id, $dbArray);
                session()->setFlashdata('alert_success', 'F24 salvato correttamente');

                return redirect()->to('/f24');
            }

            $this->model->formValues = $post;

            return view('f24/form', $this->formData($id, $this->model->errors()));
        }

        // findScoped() prima di prepForm(): il record va cercato dentro il
        // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
        // in modifica la scheda di un altro soggetto giuridico -- e il
        // salvataggio successivo la riscrive senza spostarla, quindi la
        // modifica resta invisibile a chi la subisce.
        if ($id && (!$this->model->findScoped($id) || !$this->model->prepForm($id))) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Creazione con importo e periodo già suggeriti: dalla stima imposte
        // di "Utile e imposte" si arriva qui con l'accantonamento dell'anno.
        // Sono solo valori di partenza — restano modificabili e il salvataggio
        // ripassa comunque dalla validazione. L'importo è accettato solo se
        // numerico: standardize_amount() qui interpreterebbe il punto come
        // separatore delle migliaia ("12345.67" diventerebbe 1234567).
        if ($id === null) {
            $amount = (string) ($request->getGet('amount') ?? '');
            $period = trim((string) ($request->getGet('period') ?? ''));

            if ($amount !== '' && is_numeric($amount)) {
                $this->model->formValues['f24_amount'] = (float) $amount;
            }

            if ($period !== '') {
                $this->model->formValues['f24_period'] = mb_substr($period, 0, 20);
            }
        }

        return view('f24/form', $this->formData($id, []));
    }

    private function formData(?int $id, array $errors): array
    {
        return [
            'f24_id'        => $id,
            'errors'        => $errors,
            'model'         => $this->model,
            'bank_accounts' => (new BankAccountModel())->getAllOrdered(),
        ];
    }

    /**
     * Scarica il modello allegato. Il file sta in writable/, non servito dal
     * web: passa da qui così resta dietro l'autenticazione.
     */
    public function download(int $id)
    {
        return $this->sendFile($id, 'f24_file');
    }

    public function receipt(int $id)
    {
        return $this->sendFile($id, 'f24_receipt_file');
    }

    private function sendFile(int $id, string $field)
    {
        // findScoped(): il file sta in writable/ ed e' servito da qui proprio
        // per restare dietro l'autenticazione, ma senza perimetro l'id
        // nell'URL scaricava l'F24 di un'altra azienda.
        $item = $this->model->findScoped($id);

        if (!$item || empty($item[$field])) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $path = WRITEPATH . 'uploads/f24/' . basename($item[$field]);

        if (!is_file($path)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $this->response->download($path, null);
    }

    /**
     * Importazione quietanze: si caricano uno o più PDF e ciascuno viene
     * letto, abbinato all'F24 aperto con lo stesso importo e registrato come
     * pagamento (data di versamento, protocollo, file allegato).
     *
     * Le quietanze non abbinate non vengono conservate: senza una delega a
     * cui appartengono resterebbero file orfani in writable/. L'esito le
     * elenca, e si allegano a mano dal form della delega giusta.
     */
    public function import()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            return view('f24/import', ['results' => null]);
        }

        $parser  = new F24ReceiptParser();
        $results = [];
        $files   = $request->getFileMultiple('receipts') ?? [];

        foreach ($files as $file) {
            if (!$file || !$file->isValid() || $file->hasMoved()) {
                continue;
            }

            $row = ['name' => $file->getClientName(), 'status' => 'unmatched', 'f24_id' => null];

            if (strtolower($file->getClientExtension()) !== 'pdf') {
                $results[] = $row + ['data' => null, 'reason' => 'Non è un PDF'];
                continue;
            }

            $data        = $parser->parse(F24ReceiptParser::textFromPdf($file->getTempName()));
            $row['data'] = $data;

            if ($data['protocol'] && $this->model->receiptProtocolExists($data['protocol'])) {
                $results[] = $row + ['status' => 'duplicate', 'reason' => 'Quietanza già importata'];
                continue;
            }

            if ($data['amount'] === null || $data['date'] === null) {
                $results[] = $row + ['reason' => 'Importo o data del versamento non riconosciuti nel PDF'];
                continue;
            }

            // Si ricarica l'elenco a ogni file: una quietanza appena
            // abbinata esce dall'insieme, e la successiva di pari importo
            // deve trovare la delega seguente, non la stessa.
            $candidates = $this->model->withoutReceipt();
            $f24Id      = F24ReceiptParser::match($data, $candidates);
            $stored     = $this->storeUpload($file);

            if ($f24Id !== null) {
                $current = array_values(array_filter($candidates, static fn ($c) => (int) $c['f24_id'] === $f24Id))[0];

                // Se il pagamento era già registrato (dall'estratto conto) la
                // sua data resta: la quietanza si allega, non la riscrive.
                $this->model->update($f24Id, [
                    'f24_paid_date'        => $current['f24_paid_date'] ?: $data['date'],
                    'f24_receipt_file'     => $stored,
                    'f24_receipt_protocol' => $data['protocol'],
                ]);

                $results[] = array_merge($row, ['status' => 'matched', 'f24_id' => $f24Id, 'reason' => null]);
                continue;
            }

            // Nessuna delega corrispondente: la quietanza è comunque la prova
            // di un versamento avvenuto, quindi si registra l'F24 a partire
            // da essa invece di scartarla. Succede con gli anni precedenti
            // all'adozione dell'app e con le deleghe a saldo zero
            // (interamente compensate), che in banca non lasciano traccia.
            $f24Id = (int) $this->model->saveRecord(null, [
                'f24_period'           => date('m/Y', strtotime($data['date'])),
                'f24_due_date'         => $data['date'],
                'f24_paid_date'        => $data['date'],
                'f24_amount'           => $data['amount'],
                'f24_note'             => 'Registrato da quietanza ' . ($data['protocol'] ?? $file->getClientName()),
                'f24_receipt_file'     => $stored,
                'f24_receipt_protocol' => $data['protocol'],
                'f24_date_created'     => date('Y-m-d H:i:s'),
            ]);

            $results[] = array_merge($row, ['status' => 'created', 'f24_id' => $f24Id, 'reason' => null]);
        }

        $matched = count(array_filter($results, static fn ($r) => in_array($r['status'], ['matched', 'created'], true)));

        if ($matched > 0) {
            invalidate_finance_cache();
        }

        return view('f24/import', ['results' => $results, 'matched' => $matched]);
    }

    private function storeUpload($file): string
    {
        $dir = WRITEPATH . 'uploads/f24';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = $file->getRandomName();
        $file->move($dir, $name);

        return $name;
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->model->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->model->delete($id);
        session()->setFlashdata('alert_success', 'F24 eliminato correttamente');

        return redirect()->to('/f24');
    }
}
