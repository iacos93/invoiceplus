<?php

namespace App\Libraries;

/**
 * Lettura delle quietanze F24.
 *
 * Le quietanze arrivano da fonti diverse (Entratel/Fisconline, home banking,
 * intermediari) e nessuna ha un tracciato fisso: l'unico punto in comune è
 * il testo, con poche etichette ricorrenti. Il parser è puro — riceve testo e
 * restituisce dati — così si può collaudare senza PDF né database; l'estrazione
 * del testo dal PDF sta in textFromPdf(), separata e opzionale.
 *
 * Volutamente prudente: l'importo si prende solo accanto a un'etichetta
 * riconosciuta, mai "il numero più grande della pagina". Una quietanza
 * abbinata all'F24 sbagliato segna pagato un debito che non lo è, ed è un
 * errore che non si vede; meglio lasciarla da abbinare a mano.
 */
class F24ReceiptParser
{
    private const AMOUNT_LABELS = [
        'saldo\s+finale',
        'saldo\s+delega',
        'importo\s+addebitato',
        'totale\s+addebitato',
        'importo\s+versato',
        'totale\s+versato',
        'importo\s+pagato',
        'importo\s+delega',
        'importo\s+totale',
        'totale\s+delega',
    ];

    /**
     * Distanza massima fra scadenza della delega e data del versamento perché
     * l'abbinamento valga. Un anno basta a coprire i ravvedimenti; oltre, una
     * delega vecchia rimasta aperta per dimenticanza si prenderebbe il
     * versamento di quella dell'anno, di pari importo.
     */
    private const MAX_DAYS_FROM_DUE = 366;

    private const DATE_LABELS = [
        'data\s+(?:del\s+)?versamento',
        'data\s+(?:di\s+|del\s+)?pagamento',
        'data\s+(?:di\s+|dell\')?addebito',
        'data\s+(?:dell\')?operazione',
        'data\s+esecuzione',
        'pagato\s+il',
        'addebitat[oa]\s+il',
    ];

    /**
     * @return array{amount: ?float, date: ?string, protocol: ?string}
     *               date in formato Y-m-d
     */
    public function parse(string $text): array
    {
        // pdftotext spezza le etichette su più spazi e righe: si normalizza
        // tutto a spazi singoli prima di cercare.
        $flat = preg_replace('/\s+/u', ' ', $text) ?? '';

        return [
            'amount'   => $this->findAmount($flat),
            'date'     => $this->findDate($flat),
            'protocol' => $this->findProtocol($flat),
        ];
    }

    private function findAmount(string $text): ?float
    {
        // Quietanza dell'Agenzia (anche quella scaricata da Fatture in
        // Cloud): il saldo sta in caselle e pdftotext lo restituisce come
        // "2.099 ,,2 4" -- virgola raddoppiata, decimali spaziati. Va letto
        // prima delle etichette generiche, che lì troverebbero la prima riga
        // dei tributi invece del saldo.
        if (preg_match('/saldo\s+delega\s+(\d{1,3}(?:\.\d{3})*|\d+)\s*,+\s*(\d)\s*(\d)/iu', $text, $m)) {
            return self::toFloat($m[1] . ',' . $m[2] . $m[3]);
        }

        // Le etichette sono in ordine di specificità: "importo" da solo è
        // l'ultima spiaggia perché compare anche sulle righe dei tributi.
        $number = '[^0-9]{0,40}?(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+,\d{2}|\d+\.\d{2}(?!\d))';

        foreach (self::AMOUNT_LABELS as $label) {
            if (preg_match('/\b' . $label . $number . '/iu', $text, $m)) {
                return self::toFloat($m[1]);
            }
        }

        // «Importo» da solo compare anche sulle righe dei singoli tributi: lo
        // si prende solo se nella pagina ce n'è uno, perché allora non può
        // che essere il totale. Con più occorrenze la prima era la prima riga
        // dei tributi, e la quietanza finiva sulla delega di quell'importo.
        if (preg_match_all('/\bimporto\b' . $number . '/iu', $text, $all) === 1) {
            return self::toFloat($all[1][0]);
        }

        return null;
    }

    private function findDate(string $text): ?string
    {
        // Formato a caselle dell'Agenzia: "DATA DEL VERSAMENTO 1 7 0 2 2 0 2 5"
        // (giorno, mese, anno una cifra per casella).
        if (preg_match('/data\s+del\s+versamento\s+(\d)\s*(\d)\s+(\d)\s*(\d)\s+(\d)\s*(\d)\s*(\d)\s*(\d)/iu', $text, $m)) {
            $date = self::toDate($m[1] . $m[2], $m[3] . $m[4], $m[5] . $m[6] . $m[7] . $m[8]);
            if ($date) {
                return $date;
            }
        }

        foreach (self::DATE_LABELS as $label) {
            if (preg_match('/' . $label . '[^0-9]{0,20}(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/iu', $text, $m)) {
                $date = self::toDate($m[1], $m[2], $m[3]);
                if ($date) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function findProtocol(string $text): ?string
    {
        // Protocollo dell'Agenzia stampato in due caselle (17 cifre +
        // progressivo di 6): si ricompone nella forma canonica col trattino,
        // altrimenti il controllo dei doppioni vedrebbe solo metà codice.
        if (preg_match('/protocollo\s+telematico\s+(\d{17})\s*-?\s*(\d{6})/iu', $text, $m)) {
            return $m[1] . '-' . $m[2];
        }

        // Il protocollo telematico dell'Agenzia è numerico con trattini
        // (es. 24051612345678901-000001); le banche usano codici alfanumerici
        // (CRO/TRN). Si accetta entrambi purché lunghi abbastanza da non
        // scambiare per protocollo un numero di pagina. Le etichette vanno
        // cercate come parole intere: senza \b «Microsoftware» dava il
        // protocollo SOFTWARE, uguale per ogni quietanza di quella banca, e
        // dalla seconda in poi l'importazione le scartava come già importate.
        if (preg_match('/\b(?:protocollo(?:\s+telematico)?|n\.?\s*protocollo|cro|trn|riferimento\s+operazione)\b[\s:n°.]*([A-Z0-9][A-Z0-9\-\/]{7,58})/iu', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Sceglie l'F24 aperto a cui appartiene la quietanza, o dice perché non
     * si può.
     *
     * L'importo deve coincidere al centesimo: è l'unico dato che la quietanza
     * e la delega hanno sicuramente in comune. Se più deleghe hanno lo stesso
     * importo (capita con gli acconti a rate uguali) decide la scadenza più
     * vicina alla data di versamento.
     *
     * Tre esiti e non uno: «nessuna delega» e «più deleghe possibili» sono
     * situazioni diverse per chi importa. Con il solo null il chiamante creava
     * l'F24 mancante in entrambi i casi, e con le rate equidistanti ne nasceva
     * uno pagato in più mentre le due deleghe vere restavano aperte. Quando
     * non si può scegliere lo dice, e le candidate tornano al chiamante.
     *
     * @param array{amount: ?float, date: ?string} $receipt
     * @param array<int, array<string, mixed>>     $open
     *
     * @return array{status: 'matched'|'none'|'ambiguous', id: ?int, candidates: array<int, array<string, mixed>>}
     */
    public static function resolve(array $receipt, array $open): array
    {
        if ($receipt['amount'] === null) {
            return self::noCandidate();
        }

        $paidAt = !empty($receipt['date']) ? strtotime($receipt['date']) : false;

        $candidates = array_values(array_filter(
            $open,
            static fn ($f) => abs((float) $f['f24_amount'] - $receipt['amount']) < 0.005
                && ($paidAt === false
                    || abs(strtotime((string) $f['f24_due_date']) - $paidAt) <= self::MAX_DAYS_FROM_DUE * 86400)
        ));

        if ($candidates === []) {
            return self::noCandidate();
        }

        if (count($candidates) === 1) {
            return ['status' => 'matched', 'id' => (int) $candidates[0]['f24_id'], 'candidates' => $candidates];
        }

        // Senza data di versamento non c'è un criterio per scegliere fra le
        // rate: si restituiscono tutte, non la prima dell'elenco.
        if (empty($receipt['date'])) {
            return ['status' => 'ambiguous', 'id' => null, 'candidates' => $candidates];
        }

        $paid = strtotime($receipt['date']);
        usort($candidates, static fn ($a, $b) => abs(strtotime((string) $a['f24_due_date']) - $paid)
            <=> abs(strtotime((string) $b['f24_due_date']) - $paid));

        $first  = abs(strtotime((string) $candidates[0]['f24_due_date']) - $paid);
        $second = abs(strtotime((string) $candidates[1]['f24_due_date']) - $paid);

        return $first < $second
            ? ['status' => 'matched', 'id' => (int) $candidates[0]['f24_id'], 'candidates' => $candidates]
            : ['status' => 'ambiguous', 'id' => null, 'candidates' => $candidates];
    }

    /**
     * Nessuna delega con quell'importo: la quietanza può diventare un F24.
     *
     * @return array{status: 'none', id: null, candidates: array<int, array<string, mixed>>}
     */
    private static function noCandidate(): array
    {
        return ['status' => 'none', 'id' => null, 'candidates' => []];
    }

    public static function toFloat(string $raw): float
    {
        $raw = str_replace(' ', '', $raw);

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        return round((float) $raw, 2);
    }

    private static function toDate(string $d, string $m, string $y): ?string
    {
        return checkdate((int) $m, (int) $d, (int) $y)
            ? sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d)
            : null;
    }

    /**
     * Testo di un PDF tramite pdftotext (poppler). Se il binario o exec()
     * mancano restituisce stringa vuota: l'import non si blocca, la
     * quietanza resta semplicemente da abbinare a mano.
     */
    public static function textFromPdf(string $path): string
    {
        if (!function_exists('exec') || !is_file($path)) {
            return '';
        }

        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));

        if ($bin === '') {
            foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $candidate) {
                if (is_executable($candidate)) {
                    $bin = $candidate;
                    break;
                }
            }
        }

        if ($bin === '') {
            return '';
        }

        $out = [];
        exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null', $out, $code);

        return $code === 0 ? implode("\n", $out) : '';
    }
}
