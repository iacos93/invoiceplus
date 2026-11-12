<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Gli invii tracciati di fatture e preventivi.
 *
 * La riga si crea prima dell'invio (serve il token da mettere nel messaggio),
 * riceve `track_sent_at` solo quando il server SMTP accetta il messaggio e
 * viene eliminata se l'invio fallisce: così la pagina del documento non mostra
 * email mai partite.
 */
class EmailTrackModel extends Model
{
    protected $table            = 'ip_email_tracks';
    protected $primaryKey       = 'track_id';
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'track_token',
        'track_document_type',
        'track_document_id',
        'track_company_id',
        'track_recipient',
        'track_subject',
        'track_sent_at',
        'track_opened_at',
        'track_open_count',
        'track_last_ip',
        'track_user_agent',
        'track_created_at',
    ];

    /** Invii effettivamente partiti per un documento, dal più recente. */
    public function latestFor(string $type, int $documentId, int $limit = 10): array
    {
        return $this->where('track_document_type', $type)
            ->where('track_document_id', $documentId)
            ->where('track_sent_at IS NOT NULL')
            ->orderBy('track_id', 'DESC')
            ->findAll($limit);
    }

    public function findByToken(string $token): ?array
    {
        return $this->where('track_token', $token)->first();
    }
}
