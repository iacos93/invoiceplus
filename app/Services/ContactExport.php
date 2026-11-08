<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\SupplierModel;

/**
 * ContactExport — colonne e righe del CSV di clienti e fornitori.
 *
 * La stessa anagrafica si scarica da due punti: il pulsante "Esporta"
 * sull'elenco e il tipo "Clienti"/"Fornitori" della pagina Esportazioni. Le due
 * definizioni erano scritte due volte e avevano già divergito: una usava le
 * etichette tradotte, l'altra le aveva in italiano fisso, quindi in interfaccia
 * inglese lo stesso export usciva con intestazioni diverse. Da qui le leggono
 * entrambe, e i modelli CSV scaricabili usano le stesse intestazioni con una
 * riga di esempio: un file esportato si può riprendere e reimportare.
 *
 * Le etichette passano da trans() e restano riconosciute in lettura perché
 * ContactImporter accetta sia l'italiano sia l'inglese.
 */
class ContactExport
{
    /**
     * Intestazioni e righe dell'anagrafica clienti.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function clients(): array
    {
        $rows = [];

        foreach ((new ClientModel())->getAllOrdered() as $client) {
            $rows[] = [
                format_client($client),
                $client['client_surname'] ?? '',
                $client['client_vat_id'] ?? '',
                $client['client_tax_code'] ?? '',
                $client['client_address_1'] ?? '',
                $client['client_city'] ?? '',
                $client['client_zip'] ?? '',
                $client['client_state'] ?? '',
                $client['client_country'] ?? '',
                $client['client_email'] ?? '',
                $client['client_phone'] ?? '',
                $client['client_mobile'] ?? '',
                $client['client_iban'] ?? '',
                $client['client_sdi_code'] ?? '',
                $client['client_sdi_pec'] ?? '',
            ];
        }

        return [$this->clientHeaders(), $rows];
    }

    /**
     * Intestazioni e righe dell'anagrafica fornitori.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function suppliers(): array
    {
        $rows = [];

        foreach ((new SupplierModel())->getAllOrdered() as $supplier) {
            $rows[] = [
                $supplier['supplier_name'] ?? '',
                $supplier['supplier_vat_id'] ?? '',
                $supplier['supplier_tax_code'] ?? '',
                $supplier['supplier_address_1'] ?? '',
                $supplier['supplier_city'] ?? '',
                $supplier['supplier_zip'] ?? '',
                $supplier['supplier_state'] ?? '',
                $supplier['supplier_country'] ?? '',
                $supplier['supplier_email'] ?? '',
                $supplier['supplier_phone'] ?? '',
                $supplier['supplier_iban'] ?? '',
                $supplier['supplier_sdi_code'] ?? '',
                $supplier['supplier_sdi_pec'] ?? '',
            ];
        }

        return [$this->supplierHeaders(), $rows];
    }

    /** @return list<string> */
    public function clientHeaders(): array
    {
        return [
            trans('client', '', 'Nome / Ragione Sociale'),
            trans('surname', '', 'Cognome'),
            trans('vat_id', '', 'Partita IVA'),
            trans('tax_code', '', 'Codice Fiscale'),
            trans('address', '', 'Indirizzo'),
            trans('city', '', 'Città'),
            trans('zip', '', 'CAP'),
            trans('state', '', 'Provincia'),
            trans('country', '', 'Paese'),
            trans('email', '', 'Email'),
            trans('phone', '', 'Telefono'),
            trans('mobile', '', 'Cellulare'),
            trans('iban', '', 'IBAN'),
            trans('sdi_code', '', 'Codice SDI'),
            trans('sdi_pec', '', 'PEC'),
        ];
    }

    /** @return list<string> */
    public function supplierHeaders(): array
    {
        return [
            trans('supplier', '', 'Fornitore / Ragione Sociale'),
            trans('vat_id', '', 'Partita IVA'),
            trans('tax_code', '', 'Codice Fiscale'),
            trans('address', '', 'Indirizzo'),
            trans('city', '', 'Città'),
            trans('zip', '', 'CAP'),
            trans('state', '', 'Provincia'),
            trans('country', '', 'Paese'),
            trans('email', '', 'Email'),
            trans('phone', '', 'Telefono'),
            trans('iban', '', 'IBAN'),
            trans('sdi_code', '', 'Codice SDI'),
            trans('sdi_pec', '', 'PEC'),
        ];
    }

    /**
     * Riga di esempio per il modello scaricabile.
     *
     * @return list<string>
     */
    public function clientSample(): array
    {
        return [
            'Acme S.r.l.', '', 'IT12345678901', '12345678901',
            'Via Roma 10', 'Milano', '20121', 'MI', 'IT',
            'amministrazione@acme.it', '021234567', '3401234567',
            'IT60X0542811101000000123456', 'M5UXCR1', 'acme@pec.it',
        ];
    }

    /** @return list<string> */
    public function supplierSample(): array
    {
        return [
            'Forniture Generali S.p.A.', 'IT98765432109', '98765432109',
            'Corso Italia 42', 'Torino', '10121', 'TO', 'IT',
            'ordini@forniture.it', '011987654',
            'IT02L1234512345000000654321', 'SUBM77N', 'forniture@pec.it',
        ];
    }
}
