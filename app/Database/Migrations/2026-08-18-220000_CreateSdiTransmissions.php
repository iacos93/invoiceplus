<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registro delle trasmissioni allo SdI (Sistema di Interscambio).
 *
 * Ogni invio di fattura elettronica produce una riga: canale usato
 * (PEC, webservice accreditato, download manuale), nome file FatturaPA
 * (IdTrasmittente_Progressivo.xml), copia dell'XML trasmesso e l'esito
 * della trasmissione. Gli esiti arrivano dallo SdI come ricevute
 * (RC/NS/NE/EC/MC/DT): con il canale PEC li legge il polling IMAP
 * (Cron::sdiPoll), con il webservice arrivano dalla risposta API, in
 * assenza di automazione si aggiornano a mano dalla pagina fattura.
 *
 * Una fattura puo' avere piu' trasmissioni (es. reinvio dopo uno scarto):
 * la riga piu' recente e' quella che conta per il badge di stato.
 */
class CreateSdiTransmissions extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('ip_sdi_transmissions')) {
            return;
        }

        $this->forge->addField([
            'sdi_transmission_id' => [
                'type'           => 'INT',
                'auto_increment' => true,
            ],
            'invoice_id'   => ['type' => 'INT'],
            'filename'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'channel'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'manual'],
            'esito'        => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'messaggio'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'xml'          => ['type' => 'TEXT', 'null' => true],
            'sent_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('sdi_transmission_id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addKey('filename');
        $this->forge->createTable('ip_sdi_transmissions');
    }

    public function down()
    {
        $this->forge->dropTable('ip_sdi_transmissions', true);
    }
}