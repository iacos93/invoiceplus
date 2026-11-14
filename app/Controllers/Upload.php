<?php

namespace App\Controllers;

use App\Libraries\UploadGuard;
use App\Models\ClientModel;
use App\Models\InvoiceModel;
use App\Models\QuoteModel;
use App\Models\UploadModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Upload controller
 *
 * Porting CI4 del controller Upload (CI3): upload di file allegati a
 * fatture/preventivi tramite url_key, elenco file del cliente,
 * eliminazione e download con whitelist delle estensioni.
 */
class Upload extends BaseController
{
    /** @var string directory di destinazione relativa a WRITEPATH */
    private const TARGET_DIR = 'uploads/customer_files/';

    /** @var array estensioni ammesse per il download */
    private const ALLOWED_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'pdf', 'txt', 'xml'];

    /** @var array content-type per estensione */
    private const CONTENT_TYPES = [
        'gif'  => 'image/gif',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'txt'  => 'text/plain',
        'xml'  => 'application/xml',
    ];

    /**
     * Estensioni ammesse in CARICAMENTO.
     *
     * Finora la whitelist esisteva solo in download: si poteva depositare
     * qualunque cosa e scoprirlo dopo. WRITEPATH sta fuori dal docroot, quindi
     * un .php non sarebbe eseguibile, ma la cartella resta un deposito e vale
     * la pena non riempirla di ciò che non si potrà nemmeno riscaricare.
     */
    private const ALLOWED_UPLOAD_EXTENSIONS = self::ALLOWED_EXTENSIONS;

    protected UploadModel $uploadModel;

    public function __construct()
    {
        $this->uploadModel = new UploadModel();
    }

    /**
     * Il cliente appartiene all'azienda attiva?
     *
     * Nessuno dei metodi di questo controller lo verificava: si potevano
     * allegare file al cliente di un'altra azienda, elencarne gli allegati e
     * cancellarli. Le rotte stanno dietro il filtro auth, quindi non e' una
     * superficie anonima, ma resta il confine fra soggetti giuridici.
     */
    private function clientInCompany(int $clientId): bool
    {
        return $clientId > 0 && (new ClientModel())->findScoped($clientId) !== null;
    }

    /**
     * L'url_key corrisponde a una fattura o a un preventivo dell'azienda?
     *
     * Il valore non è solo un pezzo del nome file: è il legame fra allegato e
     * documento. Prima veniva accettato qualunque url_key, quindi si potevano
     * creare allegati che non appartenevano a nessun documento (o a un
     * documento di un'altra azienda) con un nome scelto da chi chiama.
     *
     * Se il documento ha un cliente, deve essere quello indicato nell'URL:
     * l'allegato è del cliente, e un documento di un altro cliente nella
     * stessa azienda non è una destinazione valida.
     */
    private function documentForUrlKey(string $urlKey, int $customerId): ?array
    {
        $invoice = (new InvoiceModel())
            ->where('ip_invoices.invoice_url_key', $urlKey)
            ->scopeToCompany()
            ->first();

        if ($invoice !== null) {
            return $this->documentBelongsToClient($invoice, $customerId) ? $invoice : null;
        }

        $quote = (new QuoteModel())
            ->where('ip_quotes.quote_url_key', $urlKey)
            ->scopeToCompany()
            ->first();

        if ($quote !== null) {
            return $this->documentBelongsToClient($quote, $customerId) ? $quote : null;
        }

        return null;
    }

    /** Un documento senza cliente resta valido (dati legacy); con cliente deve combaciare. */
    private function documentBelongsToClient(array $document, int $customerId): bool
    {
        $documentClientId = (int) ($document['client_id'] ?? 0);

        return $documentClientId === 0 || $documentClientId === $customerId;
    }

    /**
     * Nome file ripulito e di lunghezza contenuta.
     *
     * sanitize_filename() toglie separatori di percorso e metacaratteri; il
     * taglio preserva l'estensione, che serve al download per scegliere il
     * content-type. Il prefisso url_key resta fuori da questo calcolo: il
     * formato "{urlKey}_{nome}" degli allegati esistenti non cambia.
     */
    private function safeOriginalName(string $name): ?string
    {
        helper('security');

        $name = basename(preg_replace('/\s+/', '_', $name) ?? $name);
        $name = sanitize_filename($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $max = 120;

        if (mb_strlen($name) <= $max) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base      = pathinfo($name, PATHINFO_FILENAME);
        $keep      = $max - ($extension !== '' ? mb_strlen($extension) + 1 : 0);

        return mb_substr($base, 0, max(1, $keep)) . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * Riceve un file (campo 'file') e lo salva come
     * "{urlKey}_{nomefile}" in writable/uploads/customer_files/
     * (equivalente di upload_file() CI3, in JSON).
     */
    public function uploadFile(int $customerId, string $urlKey): ResponseInterface
    {
        if (!$this->clientInCompany($customerId)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Cliente non valido',
            ]);
        }

        // L'url_key arriva dall'URL e diventa il prefisso del nome su disco.
        // Formato e documento vengono verificati prima di toccare il file.
        if (!UploadGuard::isValidUrlKey($urlKey)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Documento non valido',
            ]);
        }

        if ($this->documentForUrlKey($urlKey, $customerId) === null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Documento non trovato',
            ]);
        }

        $file = $this->request->getFile('file');

        if ($file === null || !$file->isValid() || $file->hasMoved()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Nessun file ricevuto o file non valido',
            ]);
        }

        $originalName = $this->safeOriginalName($file->getName());

        if ($originalName === null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Nome del file non valido',
            ]);
        }

        if (!in_array(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Tipo di file non ammesso',
            ]);
        }

        // Dimensione e MIME reali: l'estensione da sola non dice niente.
        $reason = UploadGuard::checkUploadedFile($file, UploadGuard::KIND_ATTACHMENT);

        if ($reason !== null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $reason,
            ]);
        }

        $targetName   = $urlKey . '_' . $originalName;
        $targetPath   = WRITEPATH . self::TARGET_DIR;

        if (!is_dir($targetPath)) {
            // 0755 e non 0777: la cartella non e' servita dal web, non deve
            // essere scrivibile da qualunque utente della macchina.
            mkdir($targetPath, 0755, true);
        }

        if (is_file($targetPath . $targetName)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Esiste gia\' un file con questo nome',
            ]);
        }

        $this->uploadModel->create([
            'client_id'          => $customerId,
            'url_key'            => $urlKey,
            'file_name_original' => $originalName,
            'file_name_new'      => $targetName,
            'uploaded_date'      => date('Y-m-d'),
        ]);

        $file->move($targetPath, $targetName);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'File caricato correttamente',
        ]);
    }

    /**
     * Elenco JSON dei file del cliente per un url_key
     * (equivalente di show_files() CI3).
     */
    public function showFiles(string $urlKey, int $customerId): ResponseInterface
    {
        if (!$this->clientInCompany($customerId)) {
            return $this->response->setJSON([]);
        }

        $files      = [];
        $targetPath = WRITEPATH . self::TARGET_DIR;

        foreach ($this->uploadModel->byClient($customerId) as $upload) {
            if (strpos($upload['file_name_new'], $urlKey . '_') !== 0) {
                continue;
            }

            $fullpath = $targetPath . $upload['file_name_new'];

            $files[] = [
                'name'     => $upload['file_name_original'],
                'fullname' => $upload['file_name_new'],
                'size'     => is_file($fullpath) ? filesize($fullpath) : 0,
                'fullpath' => $fullpath,
            ];
        }

        return $this->response->setJSON($files);
    }

    /**
     * Elimina file dal disco e record upload (equivalente di
     * delete_file() CI3). Il nome file originale arriva via POST.
     */
    public function deleteFile(string $urlKey): ResponseInterface
    {
        if ($this->request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $fileName   = (string) $this->request->getPost('name');
        $targetPath = WRITEPATH . self::TARGET_DIR;

        $uploads = $this->uploadModel->findByUrlKeyAndName($urlKey, $fileName);

        // Il perimetro si legge dal cliente a cui l'allegato e' appeso: senza,
        // bastava conoscere url key e nome file per cancellare l'allegato di
        // un'altra azienda, e una cancellazione non si annulla.
        foreach ($uploads as $upload) {
            if (!$this->clientInCompany((int) ($upload['client_id'] ?? 0))) {
                return $this->response->setJSON(['success' => false, 'message' => 'Allegato non trovato']);
            }
        }

        if (!$uploads) {
            return $this->response->setJSON(['success' => false, 'message' => 'Allegato non trovato']);
        }

        foreach ($uploads as $upload) {
            $diskFile = $targetPath . basename($upload['file_name_new']);

            if (is_file($diskFile)) {
                unlink($diskFile);
            }
        }

        $this->uploadModel->deleteFileByUrlKey($urlKey, $fileName);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Download di un file con whitelist delle estensioni e protezione
     * dal path traversal (equivalente di get_file() CI3).
     */
    public function getFile(string $filename): ResponseInterface
    {
        $filename = basename($filename);
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // L'allegato si scarica per nome file, che da solo non dice di chi e':
        // senza questo controllo qualunque utente autenticato scaricava gli
        // allegati di ogni azienda, sapendone o indovinandone il nome.
        $upload = $this->uploadModel->findByDiskName($filename);

        if (!$upload || !$this->clientInCompany((int) ($upload['client_id'] ?? 0))) {
            throw PageNotFoundException::forPageNotFound();
        }

        $filePath = WRITEPATH . self::TARGET_DIR . $filename;

        if (!is_file($filePath)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->download($filePath, null)
            ->setContentType(self::CONTENT_TYPES[$ext] ?? 'application/octet-stream');
    }
}