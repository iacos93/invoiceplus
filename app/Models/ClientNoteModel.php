<?php

namespace App\Models;

/**
 * ClientNoteModel
 *
 * Porting CI4 del modulo clients (CI3: Mdl_Client_Notes):
 * note dei clienti con data odierna automatica e ordinamento
 * per data decrescente (default_order_by in CI3).
 */
class ClientNoteModel extends IpBaseModel
{
    protected $table            = 'ip_client_notes';
    protected $primaryKey       = 'client_note_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'client_id',
        'client_note_date',
        'client_note',
    ];

    protected $validationRules = [
        'client_id'   => 'required',
        'client_note' => 'required',
    ];

    protected $validationMessages = [
        'client_id' => [
            'required' => 'Il cliente e\' obbligatorio',
        ],
        'client_note' => [
            'required' => 'La nota e\' obbligatoria',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Aggiunge la data odierna alla nota (equivalente di db_array() in CI3).
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray                    = parent::dbArrayFromPost($post);
        $dbArray['client_note_date'] = date('Y-m-d');

        return $dbArray;
    }

    /**
     * Note di un cliente ordinate per data decrescente
     * (equivalente di default_order_by() in CI3).
     */
    public function getAllForClient(int $clientId): array
    {
        return $this->where('client_id', $clientId)
            ->orderBy('client_note_date', 'DESC')
            ->orderBy('client_note_id', 'DESC')
            ->findAll();
    }
}