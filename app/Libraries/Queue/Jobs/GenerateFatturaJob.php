<?php

namespace App\Libraries\Queue\Jobs;

use App\Libraries\Queue\JobInterface;

/**
 * Job asincrono per la generazione del XML FatturaPA.
 *
 * Esempio d'uso:
 *   RedisQueue::instance()->push(new GenerateFatturaJob($invoiceId, $companyId), 'fattura');
 */
class GenerateFatturaJob implements JobInterface
{
    public function __construct(
        private int $invoiceId,
        private int $companyId,
    ) {}

    public function handle(): void
    {
        // Imposta il contesto aziendale per tutta la durata del job
        session()->set('active_company_id', $this->companyId);

        $fattura = new \App\Libraries\FatturaXml();
        $fattura->generate($this->invoiceId);

        log_message('info', 'FatturaPA generata per fattura #{id} (azienda {company})', [
            'id'      => $this->invoiceId,
            'company' => $this->companyId,
        ]);
    }
}
