<?php

namespace App\Services;

/**
 * Primo avvio: cosa controllare prima di creare l'account e cosa fare dopo.
 *
 * La parte di valutazione (`steps()`, `blocking()`) è pura — riceve i fatti e
 * restituisce l'elenco — così si prova senza database; `facts()` e
 * `environment()` sono le uniche che toccano disco e database.
 */
class SetupGuide
{
    /**
     * Controlli dell'ambiente, nell'ordine in cui conviene risolverli.
     *
     * Il database non si configura da qui, e di proposito: vedi
     * `sessions/session_setup_env`. Qui si verifica soltanto che chi ha
     * scritto il .env abbia fatto un lavoro che funziona.
     *
     * @return list<array{key: string, label: string, state: string, detail: string, hint: string}>
     */
    public static function environment(): array
    {
        $service = SystemStatus::fromApp();
        $checks  = [$service->phpCheck(), $service->databaseCheck()];

        // Senza database non si può sapere se lo schema è aggiornato, e il
        // controllo lo direbbe in modo fuorviante: si salta.
        if ($checks[1]['state'] === SystemStatus::OK) {
            $checks[] = $service->migrationCheck();
        }

        $checks[] = $service->writableCheck();
        $checks[] = $service->environmentCheck();

        return $checks;
    }

    /**
     * Il modulo di creazione dell'account si mostra solo se nessun controllo
     * è guasto. Gli avvisi (ambiente di sviluppo) non bloccano: chi prova
     * l'app in locale è nel suo diritto.
     *
     * @param list<array{state: string}> $checks
     */
    public static function blocking(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['state'] === SystemStatus::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fatti sull'installazione da cui dipendono i primi passi.
     *
     * @return array<string, mixed>
     */
    public static function facts(): array
    {
        $company = active_company();
        $service = SystemStatus::fromApp();
        $backup  = $service->latestBackup();

        $taxRates = 0;
        try {
            $taxRates = (int) \Config\Database::connect()->table('ip_tax_rates')->countAllResults();
        } catch (\Throwable $e) {
            // Senza la tabella il passo resta "da fare": è la risposta giusta.
        }

        $mfa = false;
        try {
            $userId = (int) session('user_id');
            $user   = (new \App\Models\UserModel())->find($userId);

            // db_bool e non un cast a int: su PostgreSQL il booleano arriva
            // come 't'/true e (int)'t' vale 0, quindi il passo risultava
            // "da fare" anche con il TOTP attivo. Una passkey registrata conta
            // quanto il TOTP: è il secondo fattore che la pagina Sicurezza
            // offre in alternativa.
            $mfa = db_bool($user['user_mfa_enabled'] ?? false)
                || (new \App\Models\PasskeyModel())->countForUser($userId) > 0;
        } catch (\Throwable $e) {
        }

        return [
            'company_id'    => (int) ($company['company_id'] ?? 0),
            'company_name'  => (string) ($company['company_name'] ?? ''),
            'company_vat'   => (string) ($company['company_vat_id'] ?? ''),
            'company_addr'  => (string) ($company['company_address_1'] ?? ''),
            'company_city'  => (string) ($company['company_city'] ?? ''),
            'logo'          => trim((string) ($company['invoice_logo'] ?? '')) !== '' || trim((string) get_setting('invoice_logo', '')) !== '',
            'footer'        => trim((string) ($company['pdf_invoice_footer'] ?? '')) !== '' || trim((string) get_setting('pdf_invoice_footer', '')) !== '',
            'tax_rates'     => $taxRates,
            'sdi_chosen'    => trim((string) get_setting('sdi_channel', '')) !== '',
            'cron_key'      => trim((string) get_setting('cron_key', '')) !== '',
            'backup'        => $backup !== null,
            'mfa'           => $mfa,
            // Stessa regola del controllo "Ambiente" di Stato del sistema.
            'production'    => $service->environmentCheck()['state'] === SystemStatus::OK,
        ];
    }

    /**
     * I passi, con lo stato di ciascuno.
     *
     * Ogni passo rimanda alla pagina che già esiste per farlo: la procedura
     * non duplica moduli (due posti dove cambiare la stessa cosa finiscono per
     * disallinearsi) e l'utente impara dove stanno le impostazioni, che è ciò
     * che gli servirà dopo.
     *
     * @param array<string, mixed> $f
     *
     * @return list<array{key: string, group: string, label: string, why: string, url: string, done: bool}>
     */
    public static function steps(array $f): array
    {
        $companyUrl = 'companies/form' . (! empty($f['company_id']) ? '/' . (int) $f['company_id'] : '');

        $companyDone = trim((string) ($f['company_name'] ?? '')) !== ''
            && strcasecmp(trim((string) $f['company_name']), 'Azienda') !== 0
            && trim((string) ($f['company_vat'] ?? '')) !== ''
            && trim((string) ($f['company_addr'] ?? '')) !== ''
            && trim((string) ($f['company_city'] ?? '')) !== '';

        return [
            [
                'key' => 'company', 'group' => 'Azienda',
                'label' => 'Dati dell\'azienda',
                'why' => 'Ragione sociale, partita IVA e sede finiscono su ogni fattura come dati dell\'emittente; il nome provvisorio «Azienda» non va su un documento vero.',
                'url' => $companyUrl, 'done' => $companyDone,
            ],
            [
                'key' => 'logo', 'group' => 'Azienda',
                'label' => 'Logo',
                'why' => 'Esteso nell\'intestazione e solo segno nel piè di pagina di fatture, preventivi e report.',
                'url' => $companyUrl, 'done' => ! empty($f['logo']),
            ],
            [
                'key' => 'footer', 'group' => 'Azienda',
                'label' => 'Piè di pagina dei documenti',
                'why' => 'Contatti e diciture di legge (per una S.r.l. sede, Registro imprese, capitale sociale).',
                'url' => $companyUrl, 'done' => ! empty($f['footer']),
            ],
            [
                'key' => 'tax_rates', 'group' => 'Fiscale',
                'label' => 'Aliquote IVA',
                'why' => 'Le aliquote che userai nelle righe dei documenti (22%, 10%, esenti…).',
                'url' => 'tax_rates', 'done' => (int) ($f['tax_rates'] ?? 0) > 0,
            ],
            [
                'key' => 'sdi', 'group' => 'Fiscale',
                'label' => 'Invio allo SdI',
                'why' => 'Scegli come trasmettere le fatture elettroniche: a mano, via PEC o tramite un intermediario.',
                'url' => 'sdi/settings', 'done' => ! empty($f['sdi_chosen']),
            ],
            [
                'key' => 'cron', 'group' => 'Automazioni',
                'label' => 'Chiave per le automazioni',
                'why' => 'Fatture ricorrenti, ricevute dello SdI, promemoria e backup partono da un cron che si autentica con questa chiave.',
                'url' => 'settings', 'done' => ! empty($f['cron_key']),
            ],
            [
                'key' => 'backup', 'group' => 'Automazioni',
                'label' => 'Primo backup',
                'why' => 'Finché non esiste un dump del database, un guasto al disco è un guasto ai dati.',
                'url' => 'system/status', 'done' => ! empty($f['backup']),
            ],
            [
                'key' => 'mfa', 'group' => 'Sicurezza',
                'label' => 'Verifica in due passaggi',
                'why' => 'Un\'app con dati fiscali merita più della sola password: codice a tempo o passkey.',
                'url' => 'security', 'done' => ! empty($f['mfa']),
            ],
            [
                'key' => 'production', 'group' => 'Sicurezza',
                'label' => 'Ambiente di produzione',
                'why' => 'In sviluppo gli errori mostrano percorsi e query: imposta CI_ENVIRONMENT = production nel .env prima di usare dati veri.',
                'url' => 'system/status', 'done' => ! empty($f['production']),
            ],
        ];
    }

    /**
     * @param list<array{done: bool}> $steps
     *
     * @return array{done: int, total: int}
     */
    public static function progress(array $steps): array
    {
        return [
            'done'  => count(array_filter($steps, static fn (array $s): bool => $s['done'])),
            'total' => count($steps),
        ];
    }
}
