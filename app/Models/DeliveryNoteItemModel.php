<?php

namespace App\Models;

/**
 * DeliveryNoteItemModel — righe merce del DDT.
 *
 * Non hanno prezzo: il DDT descrive cosa viaggia, non quanto costa. Il valore
 * compare in fattura, che può raggruppare più consegne.
 */
class DeliveryNoteItemModel extends IpBaseModel
{
    protected $table            = 'ip_delivery_note_items';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'delivery_note_id', 'item_name', 'item_description',
        'item_quantity', 'item_unit', 'item_order',
    ];

    protected $validationRules = [
        'delivery_note_id' => 'required',
        'item_name'        => 'permit_empty',
        'item_description' => 'permit_empty',
        'item_quantity'    => 'permit_empty',
        'item_unit'        => 'permit_empty',
        'item_order'       => 'permit_empty',
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getByNote(int $noteId): array
    {
        return $this->where('delivery_note_id', $noteId)
                    ->orderBy('item_order', 'ASC')
                    ->orderBy('item_id', 'ASC')
                    ->findAll();
    }

    public function replaceItems(int $noteId, array $items): void
    {
        // Sostituzione integrale: le righe del DDT si modificano in blocco dal
        // form, e un merge per id complicherebbe senza dare nulla in cambio.
        $this->where('delivery_note_id', $noteId)->delete();

        $order = 1;

        foreach ($items as $item) {
            $name = trim((string) ($item['item_name'] ?? ''));
            $desc = trim((string) ($item['item_description'] ?? ''));

            if ($name === '' && $desc === '') {
                continue;
            }

            $this->insert([
                'delivery_note_id' => $noteId,
                'item_name'        => $name,
                'item_description' => $desc,
                'item_quantity'    => !empty($item['item_quantity']) ? standardize_amount($item['item_quantity']) : 0,
                'item_unit'        => trim((string) ($item['item_unit'] ?? '')),
                'item_order'       => $order++,
            ]);
        }
    }
}
