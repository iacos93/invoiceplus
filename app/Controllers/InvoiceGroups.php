<?php

namespace App\Controllers;

use App\Models\InvoiceGroupModel;

/**
 * InvoiceGroups controller
 *
 * Porting CI4 del controller Invoice_Groups (CI3).
 * In creazione, default next_id=1 e left_pad=0 come nell'originale.
 */
class InvoiceGroups extends BaseController
{
    protected InvoiceGroupModel $invoiceGroupModel;

    public function __construct()
    {
        $this->invoiceGroupModel = new InvoiceGroupModel();
    }

    public function index()
    {
        $data = [
            'invoice_groups' => $this->invoiceGroupModel->getAllOrdered(list_per_page(), list_page('ip_invoice_groups')),
            'pager'          => $this->invoiceGroupModel->pager,
            'group'          => 'ip_invoice_groups',
        ];

        return view('invoice_groups/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/invoice_groups');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->invoiceGroupModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            if ($this->invoiceGroupModel->validate($post)) {
                $dbArray = $this->invoiceGroupModel->dbArrayFromPost($post);
                $this->invoiceGroupModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Gruppo fatture aggiornato correttamente'
                    : 'Gruppo fatture creato correttamente');

                return redirect()->to('/invoice_groups');
            }

            $this->invoiceGroupModel->formValues = $post;

            $data = [
                'invoice_group_id' => $id,
                'errors'           => $this->invoiceGroupModel->errors(),
                'model'            => $this->invoiceGroupModel,
            ];

            return view('invoice_groups/form', $data);
        }

        if ($id) {
            // findScoped() prima di prepForm(): il record va cercato dentro il
            // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
            // in modifica la scheda di un altro soggetto giuridico -- e il
            // salvataggio successivo la riscrive senza spostarla, quindi la
            // modifica resta invisibile a chi la subisce.
            if (!$this->invoiceGroupModel->findScoped($id) || !$this->invoiceGroupModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        } else {
            // Default in creazione, come nel controller CI3 originale
            $this->invoiceGroupModel->formValues = [
                'invoice_group_left_pad' => 0,
                'invoice_group_next_id'  => 1,
            ];
        }

        $data = [
            'invoice_group_id' => $id,
            'errors'           => [],
            'model'            => $this->invoiceGroupModel,
        ];

        return view('invoice_groups/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Prima dell'appartenenza non si guarda nient'altro: il conteggio qui
        // sotto produce un messaggio che rivelerebbe quanti documenti usa una
        // serie di un'altra azienda.
        if (!$this->invoiceGroupModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Una serie ancora agganciata a dei documenti non si cancella: finora
        // lo faceva, e fatture e preventivi restavano con un invoice_group_id
        // che non esisteva più. In modifica la tendina della serie appariva
        // vuota e il primo salvataggio spostava il documento su un'altra
        // numerazione senza che nessuno lo avesse chiesto.
        $inUse = $this->invoiceGroupModel->documentCount($id);

        if ($inUse > 0) {
            session()->setFlashdata('alert_error',
                'Serie non eliminabile: ci sono ancora ' . $inUse . ' documenti che la usano.');

            return redirect()->to('/invoice_groups');
        }

        $this->invoiceGroupModel->delete($id);
        session()->setFlashdata('alert_success', 'Gruppo fatture eliminato correttamente');

        return redirect()->to('/invoice_groups');
    }
}
