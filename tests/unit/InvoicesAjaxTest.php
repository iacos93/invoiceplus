<?php

use App\Controllers\InvoicesAjax;
use App\Models\ClientModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\TaxRateModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le azioni di InvoicesAjax che leggono o spostano dati: la riga restituita in
 * JSON, il riordino delle righe e il cambio cliente.
 *
 * Model e richiesta arrivano dall'esterno, quindi qui sono finti e non serve un
 * database: è il motivo per cui il controller ha smesso di creare i model da
 * sé e di leggere il servizio di richiesta globale. Senza questo, l'unico modo
 * di provare un controllo di appartenenza era guardarlo.
 *
 * Le due forme non sono intercambiabili: dove la chiamata deve avvenire — la
 * fattura va cercata, il cliente va aggiornato — c'è un expect con once() e
 * with(), così se il controller smette di farlo il test cade. Dove il model
 * serve solo a restituire un valore c'è uno stub, senza with(). Il motivo è
 * che dalla PHPUnit 13 un mock senza expect è un avviso e with() senza
 * expects() è deprecato: mescolare le due cose rende il test rumoroso e
 * nasconde il caso in cui una chiamata importante sparisce.
 *
 * @internal
 */
final class InvoicesAjaxTest extends CIUnitTestCase
{
    public function testLaRigaArrivaConLaSuaAliquota(): void
    {
        $item = $this->createMock(ItemModel::class);
        $item->expects($this->once())->method('find')->with(5)->willReturn([
            'item_id'          => 5,
            'invoice_id'       => 7,
            'item_tax_rate_id' => 2,
        ]);

        $invoice = $this->createMock(InvoiceModel::class);
        $invoice->expects($this->once())->method('findScoped')->with(7)->willReturn(['invoice_id' => 7]);

        $tax = $this->createMock(TaxRateModel::class);
        $tax->expects($this->once())->method('find')->with(2)->willReturn(['tax_rate_percent' => '22.00']);

        $json = json_decode($this->controller(
            ['item_id' => 5],
            [
                ItemModel::class    => $item,
                InvoiceModel::class => $invoice,
                TaxRateModel::class => $tax,
            ]
        )->getItem()->getBody(), true);

        $this->assertTrue($json['success']);
        $this->assertSame('22.00', $json['item']['item_tax_rate_percent']);
    }

    public function testLaRigaDiUnAltraAziendaNonEsce(): void
    {
        $item = $this->createMock(ItemModel::class);
        $item->expects($this->once())->method('find')->with(5)->willReturn(['item_id' => 5, 'invoice_id' => 99]);

        $invoice = $this->createMock(InvoiceModel::class);
        $invoice->expects($this->once())->method('findScoped')->with(99)->willReturn(null);

        // L'aliquota non va nemmeno cercata: la riga non è nostra.
        $tax = $this->createMock(TaxRateModel::class);
        $tax->expects($this->never())->method('find');

        $json = json_decode($this->controller(
            ['item_id' => 5],
            [
                ItemModel::class    => $item,
                InvoiceModel::class => $invoice,
                TaxRateModel::class => $tax,
            ]
        )->getItem()->getBody(), true);

        $this->assertFalse($json['success']);
        $this->assertArrayNotHasKey('item', $json);
    }

    public function testIlRiordinoSaltaLeRigheDiAltreFatture(): void
    {
        $righe = [
            1 => ['item_id' => 1, 'invoice_id' => 7],
            2 => ['item_id' => 2, 'invoice_id' => 99],
            3 => ['item_id' => 3, 'invoice_id' => 7],
        ];

        // Le righe sono diverse per id, quindi lo stub risponde in base
        // all'argomento: l'asserzione su cosa è stato toccato resta in fondo,
        // dove si vede l'effetto del riordino.
        $item = $this->createStub(ItemModel::class);
        $item->method('find')->willReturnCallback(static fn (int $id) => $righe[$id] ?? null);

        $aggiornati = [];
        $item->method('update')->willReturnCallback(static function (int $id, array $data) use (&$aggiornati): bool {
            $aggiornati[$id] = $data;

            return true;
        });

        $invoice = $this->createMock(InvoiceModel::class);
        $invoice->expects($this->once())->method('findScoped')->with(7)->willReturn(['invoice_id' => 7]);

        $json = json_decode($this->controller(
            ['invoice_id' => 7, 'item_ids' => '[1,2,3]'],
            [
                ItemModel::class    => $item,
                InvoiceModel::class => $invoice,
            ]
        )->reorderItems()->getBody(), true);

        $this->assertSame(1, $json['success']);
        $this->assertSame(
            [1 => ['item_order' => 1], 3 => ['item_order' => 2]],
            $aggiornati,
            'La riga 2 appartiene a un\'altra fattura: non va toccata'
        );
    }

    public function testCambioClienteRifiutaUnClienteDiUnAltraAzienda(): void
    {
        $invoice = $this->createMock(InvoiceModel::class);
        $invoice->expects($this->once())->method('findScoped')->with(7)->willReturn(['invoice_id' => 7]);
        $invoice->expects($this->never())->method('update');

        $client = $this->createMock(ClientModel::class);
        $client->expects($this->once())->method('findScoped')->with(3)->willReturn(null);

        $amount = $this->createMock(InvoiceAmountModel::class);
        $amount->expects($this->never())->method('calculate');

        $json = json_decode($this->controller(
            ['invoice_id' => 7, 'client_id' => 3],
            [
                InvoiceModel::class       => $invoice,
                ClientModel::class        => $client,
                InvoiceAmountModel::class => $amount,
            ]
        )->changeClient()->getBody(), true);

        $this->assertFalse($json['success']);
    }

    public function testCambioClienteAggiornaERicalcola(): void
    {
        $invoice = $this->createMock(InvoiceModel::class);
        $invoice->expects($this->once())->method('findScoped')->with(7)->willReturn(['invoice_id' => 7]);
        $invoice->expects($this->once())->method('update')->with(7, ['client_id' => 3]);

        $client = $this->createMock(ClientModel::class);
        $client->expects($this->once())->method('findScoped')->with(3)->willReturn(['client_id' => 3]);

        $amount = $this->createMock(InvoiceAmountModel::class);
        $amount->expects($this->once())->method('calculate')->with(7);

        $json = json_decode($this->controller(
            ['invoice_id' => 7, 'client_id' => 3],
            [
                InvoiceModel::class       => $invoice,
                ClientModel::class        => $client,
                InvoiceAmountModel::class => $amount,
            ]
        )->changeClient()->getBody(), true);

        $this->assertTrue($json['success']);
    }

    /**
     * Il controller con i soli pezzi che gli servono: richiesta finta (per il
     * metodo e il POST), risposta vera per il JSON, model passati.
     */
    private function controller(array $post, array $models): InvoicesAjax
    {
        $controller = new InvoicesAjax($models);

        $ref = new ReflectionClass(\CodeIgniter\Controller::class);
        $ref->getProperty('request')->setValue($controller, $this->richiestaPost($post));
        $ref->getProperty('response')->setValue($controller, service('response'));
        $ref->getProperty('logger')->setValue($controller, service('logger'));

        return $controller;
    }

    private function richiestaPost(array $post): IncomingRequest
    {
        // Stub e non mock: qui la richiesta non ha nulla da pretendere, deve
        // solo rispondere "POST" e restituire i campi passati.
        $request = $this->createStub(IncomingRequest::class);

        $request->method('getMethod')->willReturn('POST');
        $request->method('getPost')->willReturnCallback(
            static fn (?string $key = null) => $key === null ? $post : ($post[$key] ?? null)
        );

        return $request;
    }
}
