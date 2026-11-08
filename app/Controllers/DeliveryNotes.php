<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\DeliveryNoteItemModel;
use App\Models\DeliveryNoteModel;
use App\Models\InvoiceGroupModel;
use App\Models\UnitModel;

/**
 * DeliveryNotes — documenti di trasporto.
 *
 * Il DDT accompagna la merce e si emette prima della fattura: i campi
 * obbligatori (causale, aspetto dei beni, colli, vettore, porto, data e ora di
 * inizio trasporto) sono richiesti dalla normativa, non facoltativi.
 */
class DeliveryNotes extends BaseController
{
    protected DeliveryNoteModel $model;

    public function __construct()
    {
        $this->model = new DeliveryNoteModel();
    }

    public function index()
    {
        $request = service('request');
        $state   = (string) ($request->getGet('state') ?? '');
        $state   = in_array($state, ['open', 'invoiced'], true) ? $state : '';

        return view('delivery_notes/index', [
            'notes' => $this->model->getAll(selected_year(), $state),
            'state' => $state,
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

            $post['delivery_note_date'] = date_to_mysql($post['delivery_note_date'] ?? '');

            // Data e ora di inizio trasporto arrivano da due campi distinti:
            // il calendario non gestisce l'ora.
            $post['transport_datetime'] = !empty($post['transport_date'])
                ? date_to_mysql($post['transport_date']) . ' ' . (($post['transport_time'] ?? '') ?: '00:00') . ':00'
                : null;

            if ($this->model->validate($post)) {
                $dbArray = $this->model->dbArrayFromPost($post);

                if (!$id) {
                    $dbArray['delivery_note_date_created'] = date('Y-m-d H:i:s');
                    $dbArray['delivery_note_url_key']      = generate_key(32);

                    // Numero dalla serie scelta, come per fatture e preventivi.
                    if (empty($dbArray['delivery_note_number']) && !empty($dbArray['invoice_group_id'])) {
                        $dbArray['delivery_note_number'] =
                            (new InvoiceGroupModel())->generateInvoiceNumber((int) $dbArray['invoice_group_id']);
                    }
                }

                $noteId = (int) $this->model->saveRecord($id, $dbArray);

                (new DeliveryNoteItemModel())->replaceItems($noteId, $this->postedItems($post));

                session()->setFlashdata('alert_success', 'Documento di trasporto salvato correttamente');

                return redirect()->to('/delivery_notes');
            }

            $this->model->formValues = $post;

            return view('delivery_notes/form', $this->formData($id, $this->model->errors()));
        }

        // findScoped() prima di prepForm(): il record va cercato dentro il
        // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
        // in modifica la scheda di un altro soggetto giuridico -- e il
        // salvataggio successivo la riscrive senza spostarla, quindi la
        // modifica resta invisibile a chi la subisce.
        if ($id && (!$this->model->findScoped($id) || !$this->model->prepForm($id))) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('delivery_notes/form', $this->formData($id, []));
    }

    /**
     * Righe dal campo JSON prodotto dalla tabella dinamica del form.
     */
    private function postedItems(array $post): array
    {
        if (empty($post['items'])) {
            return [];
        }

        $decoded = json_decode((string) $post['items'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formData(?int $id, array $errors): array
    {
        return [
            'delivery_note_id' => $id,
            'errors'           => $errors,
            'model'            => $this->model,
            'clients'          => (new ClientModel())->getAllOrdered(),
            'invoice_groups'   => (new InvoiceGroupModel())->getAllOrdered(),
            'units'            => (new UnitModel())->getAllOrdered(),
            'items'            => $id ? (new DeliveryNoteItemModel())->getByNote($id) : [],
            'reasons'          => $this->model->transportReasons(),
            'charges'          => $this->model->transportCharges(),
        ];
    }

    public function view(int $id)
    {
        $note = $this->model->getById($id);

        if (!$note) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('delivery_notes/preview', [
            'note'  => $note,
            'items' => (new DeliveryNoteItemModel())->getByNote($id),
        ]);
    }

    public function generatePdf(int $id, bool $stream = true)
    {
        $note = $this->model->getById($id);

        if (!$note) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $html = view('delivery_note_templates/default.pdf.php', [
            'note'    => $note,
            'items'   => (new DeliveryNoteItemModel())->getByNote($id),
            'company' => active_company(),
        ]);

        $filename = 'DDT_' . str_replace(['\\', '/'], '_', (string) ($note['delivery_note_number'] ?: $id)) . '.pdf';

        return pdf_create($html, $filename, $stream);
    }


    /**
     * Anteprima HTML del foglio, per l'iframe della vista.
     */
    public function previewSheet(int $id)
    {
        $note = $this->model->getById($id);

        if (!$note) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il logo va referenziato via http: qui rende il browser, non dompdf.
        $GLOBALS['pdf_preview'] = true;

        $html = view('delivery_note_templates/default.pdf.php', [
            'note'    => $note,
            'items'   => (new DeliveryNoteItemModel())->getByNote($id),
            'company' => active_company(),
        ]);

        // Anteprima nell'iframe, non PDF: la classe tiene il foglio un A4 vero
        // e layout/preview_sheet.php lo divide in fogli e lo scala alla
        // larghezza del riquadro (vedi html.ip-sheet-embedded in
        // layout/pdf_styles.php).
        $html = str_replace('<html ', '<html class="ip-sheet-embedded" ', $html);

        return $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($html);
    }

    /**
     * Crea la fattura da un DDT, riportandone le righe.
     *
     * Le quantita' passano, i prezzi no: il DDT non li ha, e inventarli qui
     * significherebbe fatturare importi mai concordati. Restano da compilare.
     */
    public function toInvoice(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $note = $this->model->getById($id);

        if (!$note) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if (!empty($note['invoice_id'])) {
            session()->setFlashdata('alert_error', 'Questo DDT e\' gia\' collegato a una fattura.');

            return redirect()->to('/delivery_notes/view/' . $id);
        }

        $invoiceModel = new \App\Models\InvoiceModel();

        $invoiceId = $invoiceModel->create($invoiceModel->dbArrayFromPost([
            'user_id'              => session('user_id'),
            'client_id'            => $note['client_id'],
            // Stesso motivo della conversione preventivo → fattura: uno zero in
            // default_invoice_group non fa scattare il fallback di
            // company_setting(), e il DDT diventava una fattura su una serie
            // inesistente.
            'invoice_group_id'     => (new \App\Models\InvoiceGroupModel())
                ->defaultGroupId((int) ($note['invoice_group_id'] ?? 0), 'default_invoice_group'),
            'invoice_date_created' => date(date_format_setting()),
        ]));

        $itemModel = new \App\Models\ItemModel();

        foreach ((new DeliveryNoteItemModel())->getByNote($id) as $item) {
            $itemModel->saveItem(null, $itemModel->dbArrayFromItem([
                'invoice_id'       => $invoiceId,
                'item_name'        => $item['item_name'],
                'item_description' => $item['item_description'],
                'item_quantity'    => $item['item_quantity'],
                'item_price'       => 0,
                'item_order'       => $item['item_order'],
            ]));
        }

        $this->model->update($id, ['invoice_id' => $invoiceId]);

        session()->setFlashdata('alert_success',
            'Fattura creata dal DDT. I prezzi delle righe sono da compilare: il documento di trasporto non li contiene.');

        return redirect()->to('/invoices/edit/' . $invoiceId);
    }

    /**
     * Crea un DDT a partire da una fattura o un preventivo gia' esistente,
     * precompilando destinatario e righe.
     */
    public function fromDocument(string $type, int $documentId)
    {
        if ($type === 'invoice') {
            $document = (new \App\Models\InvoiceModel())->getByIdScoped($documentId);
            $rows     = (new \App\Models\ItemModel())->getByInvoice($documentId);
        } else {
            $document = (new \App\Models\QuoteModel())->getByIdScoped($documentId);
            $rows     = (new \App\Models\QuoteItemModel())->getByQuote($documentId);
        }

        if (!$document) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Si precompila il form invece di creare subito il documento: causale,
        // colli e vettore non stanno nella fattura e vanno comunque inseriti.
        $this->model->formValues = [
            'client_id'          => $document['client_id'],
            'delivery_note_date' => date('Y-m-d'),
            'invoice_group_id'   => $document['invoice_group_id'] ?? null,
            'invoice_id'         => $type === 'invoice' ? $documentId : null,
        ];

        $data = $this->formData(null, []);

        $data['items'] = array_map(static fn($r) => [
            'item_name'        => $r['item_name'] ?? '',
            'item_description' => $r['item_description'] ?? '',
            'item_quantity'    => $r['item_quantity'] ?? 1,
            'item_unit'        => $r['item_product_unit'] ?? '',
        ], $rows);

        return view('delivery_notes/form', $data);
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

        (new DeliveryNoteItemModel())->where('delivery_note_id', $id)->delete();
        $this->model->delete($id);

        session()->setFlashdata('alert_success', 'Documento di trasporto eliminato correttamente');

        return redirect()->to('/delivery_notes');
    }
}
