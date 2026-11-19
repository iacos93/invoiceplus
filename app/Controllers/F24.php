<?php

namespace App\Controllers;

use App\Models\BankAccountModel;
use App\Libraries\F24ReceiptParser;
use App\Libraries\UploadGuard;
use App\Models\F24Model;
use CodeIgniter\HTTP\Files\UploadedFile;

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

            // L'importo vuoto resta vuoto, non diventa zero: la regola
            // required del modello lo rifiuta con un avviso sul campo, mentre
            // con lo `?: 0` di prima passava come delega a saldo zero. Lo zero
            // scritto davvero (0,00) è ancora un importo valido.
            $post['f24_amount']    = standardize_amount($post['f24_amount'] ?? '');
            $post['f24_due_date']  = date_to_mysql($post['f24_due_date'] ?? '');
            $post['f24_paid_date'] = !empty($post['f24_paid_date']) ? date_to_mysql($post['f24_paid_date']) : null;

            if ($this->model->validate($post)) {
                $dbArray = $this->model->dbArrayFromPost($post);

                if (!$id) {
                    $dbArray['f24_date_created'] = date('Y-m-d H:i:s');
                }

                // Il modello F24 è un documento da conservare: si allega il
                // file ricevuto, non si ridigitano i tributi riga per riga.
                // Il form accetta PDF e scansioni JPG/PNG, quindi il controllo
                // è quello degli allegati; un PDF resta comunque vincolato al
                // MIME application/pdf.
                $file = $request->getFile('f24_file');

                // Quietanza caricata a mano: è la strada per quelle che
                // l'importazione non ha saputo abbinare.
                $receipt = $request->getFile('f24_receipt_file');

                $uploadErrors = [];

                $fileReason = $this->uploadReason($file, UploadGuard::KIND_ATTACHMENT);

                if ($fileReason !== null) {
                    $uploadErrors['f24_file'] = 'Modello non valido: ' . $fileReason;
                }

                $receiptReason = $this->uploadReason($receipt, UploadGuard::KIND_ATTACHMENT);

                if ($receiptReason !== null) {
                    $uploadErrors['f24_receipt_file'] = 'Quietanza non valida: ' . $receiptReason;
                }

                if ($uploadErrors !== []) {
                    // La vista non mostra errori sui campi file: il messaggio
                    // passa dal banner di layout, che è l'unico punto visibile
                    // senza toccare il form.
                    session()->setFlashdata('alert_error', implode(' ', $uploadErrors));

                    return redirect()->to($id ? '/f24/form/' . $id : '/f24/form');
                }

                if ($file instanceof UploadedFile && $file->isValid() && !$file->hasMoved()) {
                    $dbArray['f24_file'] = $this->storeUpload($file);
                }

                if ($receipt instanceof UploadedFile && $receipt->isValid() && !$receipt->hasMoved()) {
                    $dbArray['f24_receipt_file'] = $this->storeUpload($receipt);
                }

                $savedId = $this->saveFormRecord($this->model, $id, $dbArray, [
                    'errore' => 'F24 non salvato',
                    'creato' => 'F24 salvato correttamente',
                ]);

                if ($savedId > 0) {
                    return redirect()->to('/f24');
                }
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
        $files   = $this->receiptFiles();

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $row = ['name' => $file->getClientName(), 'status' => 'unmatched', 'f24_id' => null];

            // Una quietanza è un PDF: dimensione e MIME vengono verificati
            // prima di passarne il contenuto al parser.
            $reason = UploadGuard::checkUploadedFile($file, UploadGuard::KIND_PDF);

            if ($reason !== null) {
                $results[] = $row + ['data' => null, 'reason' => $reason];
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
            $esito      = F24ReceiptParser::resolve($data, $candidates);

            // Più deleghe con lo stesso importo e nessuna più vicina alla data
            // del versamento: non si sceglie a caso e non si crea un F24 — ne
            // resterebbe uno pagato in più, con le deleghe vere ancora aperte.
            // Il file non si scrive nemmeno: la quietanza si allega a mano a
            // quella giusta, scegliendola dall'elenco che si mostra.
            if ($esito['status'] === 'ambiguous') {
                $results[] = array_merge($row, [
                    'reason'     => 'Più deleghe con lo stesso importo, nessuna più vicina alla data del versamento',
                    'candidates' => $this->candidateLabels($esito['candidates']),
                ]);
                continue;
            }

            $stored = $this->storeUpload($file);

            if ($esito['id'] !== null) {
                $f24Id   = (int) $esito['id'];
                $current = $esito['candidates'][0];

                // Se il pagamento era già registrato (dall'estratto conto) la
                // sua data resta: la quietanza si allega, non la riscrive.
                //
                // Solo i campi che cambiano: le regole valgono sui soli campi
                // che riceve (cleanValidationRules), quindi la scadenza
                // obbligatoria non entra in gioco e non serve ripassarla.
                //
                // attachReceipt() scrive solo se la delega è ancora senza
                // quietanza: un'altra importazione può aver scelto la stessa
                // delega nello stesso momento.
                $scritto = $this->model->attachReceipt($f24Id, [
                    'f24_paid_date'        => $current['f24_paid_date'] ?: $data['date'],
                    'f24_receipt_file'     => $stored,
                    'f24_receipt_protocol' => $data['protocol'],
                ]);

                // Nessuna scrittura — una regola non torna, il database
                // rifiuta, o la delega è stata presa nel frattempo: senza
                // guardare l'esito l'elenco direbbe «Abbinata» su una delega
                // rimasta senza la nostra quietanza. Il file appena scritto
                // resterebbe orfano: si cancella.
                if ($scritto !== 'written') {
                    @unlink(WRITEPATH . 'uploads/f24/' . $stored);

                    if ($scritto === 'taken') {
                        // Zero righe toccate non dice il perché: un'altra
                        // importazione può aver abbinato la delega, oppure la
                        // delega è stata eliminata fra la lettura e la
                        // scrittura. Il messaggio resta neutro; il
                        // collegamento c'è solo se la delega esiste ancora,
                        // altrimenti porterebbe a una pagina che non c'è.
                        $risultato = [
                            'reason' => 'La delega scelta non è più disponibile: abbinata da un\'altra importazione o eliminata nel frattempo',
                        ];

                        $viva = $this->model->findScoped($f24Id);

                        if ($viva !== null) {
                            $risultato['f24_id'] = $f24Id;
                            $risultato['delega'] = $this->delegaLabel($viva);
                        }

                        $results[] = array_merge($row, $risultato);
                    } else {
                        $results[] = $this->nonRegistrata($row, 'Abbinamento non registrato');
                    }

                    continue;
                }

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

            // saveRecord() torna 0 anche quando l'INSERT è rifiutato: senza
            // guardare l'esito l'elenco direbbe «F24 creato» con un
            // collegamento a un id 0, e l'allegato appena scritto resterebbe
            // orfano in writable/.
            if ($f24Id === 0) {
                @unlink(WRITEPATH . 'uploads/f24/' . $stored);

                $results[] = $this->nonRegistrata($row, 'F24 non creato dalla quietanza');
                continue;
            }

            $results[] = array_merge($row, ['status' => 'created', 'f24_id' => $f24Id, 'reason' => null]);
        }

        $matched = count(array_filter($results, static fn ($r) => in_array($r['status'], ['matched', 'created'], true)));

        if ($matched > 0) {
            invalidate_finance_cache();
        }

        return view('f24/import', ['results' => $results, 'matched' => $matched]);
    }

    /**
     * I file allegati al POST.
     *
     * Estratto perché l'importazione si possa provare con un PDF vero: in CLI
     * is_uploaded_file() risponde sempre no, quindi un upload HTTP non si può
     * simulare. Il test sostituisce solo questa lettura; parser, abbinamento e
     * scrittura restano quelli veri.
     *
     * mixed e non UploadedFile: il controllo instanceof nel chiamante resta,
     * perché è quello che protegge dai dati della richiesta.
     *
     * @return array<int, mixed>
     */
    protected function receiptFiles(): array
    {
        return service('request')->getFileMultiple('receipts') ?? [];
    }

    /**
     * Le deleghe fra cui scegliere, come vanno mostrate sotto la quietanza da
     * abbinare: la scadenza distingue le rate di pari importo, il periodo
     * aiuta quando le scadenze sono vicine.
     *
     * @param array<int, array<string, mixed>> $candidati
     *
     * @return array<int, array{id: int, label: string}>
     */
    private function candidateLabels(array $candidati): array
    {
        return array_map(fn (array $candidato): array => [
            'id'    => (int) $candidato['f24_id'],
            'label' => $this->delegaLabel($candidato),
        ], $candidati);
    }

    /**
     * La delega in una riga: la scadenza, e il periodo quando c'è.
     *
     * La stessa forma sia per le candidate dell'abbinamento ambiguo sia per
     * la delega persa per un conflitto: chi legge l'esito deve poterle
     * riconoscere e aprire con un clic.
     *
     * @param array<string, mixed> $delega
     */
    private function delegaLabel(array $delega): string
    {
        $etichetta = 'scadenza ' . date_from_mysql((string) $delega['f24_due_date']);

        if (!empty($delega['f24_period'])) {
            $etichetta .= ' · ' . $delega['f24_period'];
        }

        return $etichetta;
    }

    /**
     * Esito di una quietanza che non si è potuta registrare, con il motivo
     * del rifiuto.
     *
     * Il motivo è il riepilogo del modello, lo stesso che i form mettono in
     * flashdata: le regole che hanno bloccato la riga se ci sono, altrimenti
     * il rifiuto del database. Composto qui perché le due strade che non
     * scrivono — abbinamento e creazione — devono dirlo allo stesso modo.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function nonRegistrata(array $row, string $premessa): array
    {
        return array_merge($row, [
            'reason' => $premessa . ': ' . $this->model->saveErrorSummary(),
        ]);
    }

    /**
     * Motivo per cui un allegato non è accettabile, null se va bene o se il
     * campo è vuoto.
     *
     * getError() === UPLOAD_ERR_NO_FILE distingue "nessun file" da "file
     * rifiutato dal server": solo il secondo va segnalato.
     */
    private function uploadReason(mixed $file, string $kind): ?string
    {
        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return UploadGuard::checkUploadedFile($file, $kind);
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
