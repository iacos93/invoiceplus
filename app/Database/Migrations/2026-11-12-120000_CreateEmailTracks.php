<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tracciamento delle aperture delle email inviate.
 *
 * Ogni invio di fattura o preventivo riceve un token casuale; il messaggio
 * porta un'immagine di 1×1 che punta alla rotta pubblica
 * `t/open/<token>.gif`. Quando il programma di posta carica le immagini, la
 * rotta registra data e ora della prima apertura e quante volte è stato
 * caricato.
 *
 * Una riga per invio, non una per documento: lo stesso preventivo può essere
 * inviato più volte (correzioni, solleciti) e la storia serve a capire cosa ha
 * visto il cliente e quando.
 *
 * Nessun contenuto del messaggio viene salvato: restano destinatario, oggetto
 * e momenti, il minimo per leggere la riga nella pagina del documento.
 */
class CreateEmailTracks extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('ip_email_tracks')) {
            return;
        }

        $this->forge->addField([
            'track_id'            => ['type' => 'INT', 'auto_increment' => true],
            // 48 caratteri esadecimali: non si indovina, e il token non
            // contiene l'id del documento (un URL leggibile direbbe a chi lo
            // riceve quale fattura sta aprendo e quale azienda la emette).
            'track_token'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'track_document_type' => ['type' => 'VARCHAR', 'constraint' => 10],
            'track_document_id'   => ['type' => 'INT'],
            'track_company_id'    => ['type' => 'INT', 'null' => true],
            'track_recipient'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'track_subject'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            // NULL finché l'invio non è riuscito: una riga senza data non è
            // un'email partita e non compare nella pagina del documento.
            'track_sent_at'       => ['type' => 'DATETIME', 'null' => true],
            'track_opened_at'     => ['type' => 'DATETIME', 'null' => true],
            'track_open_count'    => ['type' => 'INT', 'default' => 0],
            'track_last_ip'       => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'track_user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'track_created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('track_id', true);
        $this->forge->addUniqueKey('track_token', 'email_tracks_token');
        $this->forge->addKey(['track_document_type', 'track_document_id'], false, false, 'email_tracks_document');
        $this->forge->createTable('ip_email_tracks', true);
    }

    public function down()
    {
        $this->forge->dropTable('ip_email_tracks', true);
    }
}
