<?php

namespace App\Models;

class UploadModel extends IpBaseModel
{
    protected $table            = 'ip_uploads';
    protected $primaryKey       = 'upload_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'client_id',
        'url_key',
        'file_name_original',
        'file_name_new',
        'uploaded_date',
    ];

    /**
     * Crea un nuovo record di upload.
     */
    public function create(array $dbArray): int
    {
        if (empty($dbArray['uploaded_date'])) {
            $dbArray['uploaded_date'] = date('Y-m-d');
        }

        return (int) $this->insert($dbArray, true);
    }

    /**
     * Upload di un cliente, ordinati per upload_id crescente.
     */
    public function byClient(int $clientId): array
    {
        return $this->where('ip_uploads.client_id', $clientId)
            ->orderBy('ip_uploads.upload_id', 'ASC')
            ->findAll();
    }

    /**
     * Allegati di un documento (fattura o preventivo), legati dal suo
     * url_key come in CI3 (get_invoice_uploads / get_quote_uploads).
     */
    public function byUrlKey(string $urlKey): array
    {
        if ($urlKey === '') {
            return [];
        }

        return $this->where('url_key', $urlKey)->orderBy('upload_id', 'ASC')->findAll();
    }

    /**
     * Upload di un url_key con un dato nome file originale.
     */
    public function findByUrlKeyAndName(string $urlKey, string $fileName): array
    {
        return $this->where('url_key', $urlKey)
            ->where('file_name_original', $fileName)
            ->findAll();
    }

    /**
     * Riga upload dal nome del file su disco.
     *
     * Serve a risalire dall'allegato al cliente, e quindi all'azienda: il
     * download avviene per nome file, che da solo non dice a chi appartiene.
     */
    public function findByDiskName(string $fileNameNew): ?array
    {
        return $this->where('file_name_new', $fileNameNew)->first();
    }

    /**
     * Elimina le righe upload di un url_key con un dato nome file originale.
     */
    public function deleteFileByUrlKey(string $urlKey, string $fileName): void
    {
        $this->where('url_key', $urlKey)
            ->where('file_name_original', $fileName)
            ->delete();
    }
}