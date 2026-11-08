<?php

namespace App\Controllers;

use App\Models\FiscalBracketModel;
use App\Models\FiscalYearModel;

/**
 * FiscalYears — parametri fiscali per anno usati dalla stima in "Utile e imposte".
 *
 * Stavano in un file di configurazione: aggiornarli quando cambiava la legge
 * voleva dire toccare il codice. Qui si modificano dall'interfaccia, e il
 * salvataggio invalida da solo la cache del prospetto.
 */
class FiscalYears extends BaseController
{
    protected FiscalYearModel $model;

    public function __construct()
    {
        $this->model = new FiscalYearModel();
    }

    public function index()
    {
        $bracketsModel = new FiscalBracketModel();
        $years         = $this->model->getAllOrdered(list_per_page(), list_page('ip_fiscal_years'));

        // Gli scaglioni IRPEF stanno in una tabella a parte: senza allegarli
        // qui la lista mostrava solo IRES/IRAP e forfettario, oscurando
        // l'imposta personale piu' importante.
        foreach ($years as &$y) {
            $y['brackets'] = $bracketsModel->forYear(
                (int) $y['fiscal_year'],
                $y['company_id'] !== null ? (int) $y['company_id'] : null
            );
        }
        unset($y);

        return view('fiscal_years/index', [
            'years' => $years,
            'pager' => $this->model->pager,
            'group' => 'ip_fiscal_years',
        ]);
    }

    public function form(?int $id = null)
    {
        $request   = service('request');
        $brackets  = new FiscalBracketModel();
        $companyId = active_company_id();

        // Una riga di un'altra azienda non si apre nemmeno: l'id viene
        // dall'URL. Quelle condivise (company_id NULL) sì, ma non si
        // modificano sul posto — vedi sotto.
        $row = $id ? $this->model->findEffective($id) : null;
        if ($id && !$row) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();
            // Booleano nativo: PostgreSQL rifiuta gli interi su colonne boolean.
            $post['fiscal_is_projection'] = !empty($post['fiscal_is_projection']);

            if ($this->model->validate($post)) {
                $year = (int) $post['fiscal_year'];

                // Un anno non puo' comparire due volte: sarebbe ambiguo quale
                // dei due la stima debba usare.
                // Il confronto è con le righe dell'azienda: quella condivisa
                // dello stesso anno non è un duplicato, è proprio ciò che
                // una riga dell'azienda serve a sovrascrivere.
                $existing = $this->model->findOwnByYear($year, $companyId);
                if ($existing && (int) $existing['fiscal_id'] !== (int) $id) {
                    // L'errore va accanto al campo che lo causa, non solo in un
                    // avviso in alto: e' l'anno a essere sbagliato.
                    return $this->renderForm($id, $post, $this->collectBrackets($post), [
                        'fiscal_year' => 'L\'anno ' . $year . ' è già configurato: modifica quello esistente.',
                    ]);
                }

                // Modificare una riga condivisa da dentro un'azienda ne crea
                // una copia dell'azienda invece di riscriverla: la riga
                // condivisa è il punto di partenza di tutte le altre, e
                // cambiarla da qui spostava le stime di soggetti che non
                // c'entrano. Solo senza un'azienda attiva (installazione
                // mono-soggetto mai configurata) si aggiorna sul posto.
                $shared   = $row && $row['company_id'] === null;
                $targetId = ($shared && $companyId) ? null : $id;
                $dbArray  = $this->model->dbArrayFromPost($post);

                if ($targetId === null) {
                    $dbArray['company_id'] = $companyId ?: null;
                }

                $this->model->saveRecord($targetId, $dbArray);

                $bracketCompany = $targetId === null
                    ? ($companyId ?: null)
                    : ($row['company_id'] !== null ? (int) $row['company_id'] : null);
                $brackets->replaceForYear($year, $this->collectBrackets($post), $bracketCompany);

                session()->setFlashdata('alert_success', 'Parametri fiscali ' . $year . ' salvati correttamente');

                return redirect()->to('/fiscal_years');
            }

            return $this->renderForm($id, $post, $this->collectBrackets($post), $this->model->errors());
        }

        if ($id && !$this->model->prepForm($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $year    = (int) ($this->model->formValue('fiscal_year') ?: 0);
        $current = $year
            ? $brackets->forYear($year, $row && $row['company_id'] !== null ? (int) $row['company_id'] : null)
            : [];

        return $this->renderForm($id, null, array_map(
            static fn(array $b): array => ['limit' => $b[0], 'rate' => $b[1]],
            $current
        ));
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $row = $this->model->findEffective($id);

        if ($row && $row['company_id'] === null && active_company_id()) {
            // La riga condivisa vale anche per le altre aziende: toglierla da
            // qui lascerebbe loro la stima sull'anno precedente senza che
            // nessuno l'abbia deciso. Si può sovrascrivere, non cancellare.
            session()->setFlashdata('alert_error', 'I parametri ' . $row['fiscal_year']
                . ' sono condivisi fra le aziende: modificali per crearne una copia di questa azienda.');
        } elseif ($row) {
            (new FiscalBracketModel())
                ->ofCompany($row['company_id'] !== null ? (int) $row['company_id'] : null)
                ->where('fiscal_year', $row['fiscal_year'])
                ->delete();
            $this->model->delete($id);
            session()->setFlashdata('alert_success', 'Parametri fiscali ' . $row['fiscal_year'] . ' eliminati');
        }

        return redirect()->to('/fiscal_years');
    }

    /**
     * Scaglioni dal POST, scartando le righe lasciate vuote.
     *
     * Una soglia vuota su una riga compilata significa "ultimo scaglione,
     * nessun tetto": e' il modo in cui la si scrive, non un errore.
     *
     * @return list<array{limit: float|null, rate: float}>
     */
    private function collectBrackets(array $post): array
    {
        $limits = (array) ($post['bracket_limit'] ?? []);
        $rates  = (array) ($post['bracket_rate'] ?? []);
        $out    = [];

        foreach ($rates as $i => $rate) {
            if ($rate === '' || $rate === null) {
                continue;
            }

            $limit = $limits[$i] ?? '';

            $out[] = [
                'limit' => ($limit === '' || $limit === null) ? null : (float) $limit,
                'rate'  => (float) $rate,
            ];
        }

        // Ordine crescente per soglia, con lo scaglione senza tetto in fondo:
        // il calcolo scorre gli scaglioni in sequenza e si fida dell'ordine.
        usort($out, static function (array $a, array $b): int {
            if ($a['limit'] === null) {
                return 1;
            }
            if ($b['limit'] === null) {
                return -1;
            }

            return $a['limit'] <=> $b['limit'];
        });

        return $out;
    }

    private function renderForm(?int $id, ?array $post, array $brackets, array $errors = [])
    {
        if ($post !== null) {
            $this->model->formValues = $post;
        }

        return view('fiscal_years/form', [
            'fiscal_id' => $id,
            'errors'    => $errors,
            'model'     => $this->model,
            'brackets'  => $brackets,
        ]);
    }
}
