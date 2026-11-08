<?php

namespace App\Services;

/**
 * FinanceExport — struttura i dati del prospetto per l'esportazione.
 *
 * Le stesse righe servono a due chiamanti — la pagina Esportazioni (CSV) e il
 * bottone di esportazione su "Utile e imposte" (CSV e PDF) — e scritte due
 * volte sarebbero divergite alla prima modifica, con l'export che mostra
 * numeri diversi dalla pagina che dice di esportare. Qui stanno le colonne e
 * l'ordine delle voci: il prospetto esportato racconta la stessa storia di
 * quello a schermo, perché parte dalla stessa fonte (FinanceReport).
 */
class FinanceExport
{
    /** Separatore CSV: Excel in locale italiano si aspetta il punto e virgola. */
    private const CSV_DELIMITER = ';';

    /**
     * Il prospetto scalare, nello stesso ordine in cui lo si legge a schermo:
     * ricavi, costi, utile, imposte con le loro componenti, netto. Le voci di
     * dettaglio sono rientrate con un trattino, perché in un CSV la gerarchia
     * non ha altro modo di sopravvivere.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function statementRows(array $report): array
    {
        $taxes = $report['taxes'];
        $rows  = [
            [
                trans('income', '', 'Ricavi'),
                $this->number($report['income']['net']),
                trans('collected_gross', '', 'incassato, IVA inclusa') . ' ' . $this->number($report['income']['collected']),
            ],
            [
                trans('expenses', '', 'Costi'),
                '-' . $this->number($report['expenses']['amount']),
                trans('of_which_deductible', '', 'di cui deducibili') . ' ' . $this->number($report['expenses']['deductible']),
            ],
            [trans('profit', '', 'Utile lordo'), $this->number($report['profit']), ''],
            [trans('estimated_taxes', '', 'Imposte stimate'), '-' . $this->number($taxes['total']), ''],
        ];

        foreach ($taxes['lines'] as $line) {
            $rows[] = ['- ' . $line['label'], $this->number($line['amount']), ''];

            foreach ($line['children'] ?? [] as $child) {
                $rows[] = ['- - ' . $child['label'], $this->number($child['amount']), ''];
            }
        }

        $rows[] = [trans('net_after_tax', '', 'Netto stimato'), $this->number($taxes['net']), ''];

        // La stima non si legge senza sapere con quale regime e quali parametri
        // è stata fatta: un CSV che gira per email deve portarseli dietro.
        $rows[] = ['', '', ''];
        $rows[] = [trans('company', '', 'Azienda'), '', (string) ($report['company']['company_name'] ?? '')];
        $rows[] = [trans('company_form', '', 'Forma aziendale'), '', $report['forms'][$report['form']]['label']];
        $rows[] = [trans('taxable', '', 'Imponibile'), $this->number($taxes['taxable']), ''];
        $rows[] = [
            trans('fiscal_params_year', '', 'Parametri fiscali'),
            (string) $report['fiscal_year'],
            $report['fiscal_projection'] ? trans('projection', '', 'Proiezione') : trans('in_force', '', 'In vigore'),
        ];

        if (!$report['fiscal_exact']) {
            $rows[] = ['', '', sprintf(
                trans('fiscal_year_fallback', '', 'Per il %d non sono definiti parametri propri: la stima usa quelli del %d.'),
                $report['year'],
                $report['fiscal_year']
            )];
        }

        foreach ($taxes['warnings'] ?? [] as $warning) {
            $rows[] = ['', '', $warning];
        }

        $rows[] = ['', '', trans('tax_disclaimer', '', 'Stima gestionale, non calcolo fiscale definitivo.')];

        return [[
            trans('item', '', 'Voce'),
            trans('amount', '', 'Importo'),
            trans('details', '', 'Dettaglio'),
        ], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    public function monthsRows(array $report): array
    {
        $months = FinanceService::monthNames(service('request')->getLocale());
        $rows   = [];
        $income = 0.0;
        $costs  = 0.0;

        foreach ($report['by_month'] as $m => $row) {
            $income += (float) $row['income'];
            $costs  += (float) $row['expense'];

            $rows[] = [
                $months[$m] ?? (string) $m,
                $this->number($row['income']),
                $this->number($row['expense']),
                $this->number((float) $row['income'] - (float) $row['expense']),
            ];
        }

        $rows[] = [trans('total', '', 'Totale'), $this->number($income), $this->number($costs), $this->number($income - $costs)];

        return [[
            trans('month', '', 'Mese'), trans('income', '', 'Ricavi'),
            trans('expenses', '', 'Costi'), trans('balance', '', 'Saldo'),
        ], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    public function categoriesRows(array $report): array
    {
        $total = array_sum(array_column($report['by_category'], 'amount'));
        $rows  = [];

        foreach ($report['by_category'] as $row) {
            $rows[] = [
                (string) $row['category'],
                (string) (int) $row['documents'],
                $this->number($row['amount']),
                $total > 0 ? $this->number((float) $row['amount'] / $total * 100) : '',
            ];
        }

        return [[
            trans('category', '', 'Categoria'), trans('documents', '', 'Documenti'),
            trans('amount', '', 'Importo'), trans('share', '', 'Quota %'),
        ], $rows];
    }

    /**
     * CSV con BOM UTF-8 e punto e virgola: senza BOM Excel aprirebbe il file
     * come ASCII e accenti e simboli di valuta arriverebbero illeggibili.
     */
    public function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $headers, self::CSV_DELIMITER, '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $row, self::CSV_DELIMITER, '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Importo con la virgola decimale: il CSV è destinato a Excel in locale
     * italiano, dove "1234.56" verrebbe letto come testo o come 123456.
     */
    private function number($amount): string
    {
        return number_format((float) $amount, 2, ',', '');
    }
}