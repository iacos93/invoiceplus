<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('reports')); ?>
<?= $this->include('layout/headerbar', ['title' => trans('reports')]) ?>

<div id="content">

    <?php
    // Hub dei report: griglia di card cliccabili, una per report.
    // La descrizione dice cosa si ottiene, non come e' costruito il report:
    // chi apre questa pagina sta scegliendo una domanda, non una query.
    // Lo stile e' in reports.css (.ip-report-hub).
    $ip_reports = [
        [
            'slug'  => 'sales_by_client',
            'icon'  => 'fa-solid fa-users',
            'label' => trans('sales_by_client'),
            'desc'  => trans('sales_by_client_desc', '', 'Vendite e numero fatture per cliente nel periodo selezionato.'),
        ],
        [
            'slug'  => 'payment_history',
            'icon'  => 'fa-solid fa-credit-card',
            'label' => trans('payment_history'),
            'desc'  => trans('payment_history_desc', '', 'Storico dei pagamenti registrati nel periodo selezionato.'),
        ],
        [
            'slug'  => 'invoice_aging',
            'icon'  => 'fa-solid fa-hourglass-half',
            'label' => trans('invoice_aging'),
            'desc'  => trans('invoice_aging_desc', '', 'Fatture scadute classificate per fascia di ritardo.'),
        ],
        [
            'slug'  => 'sales_by_year',
            'icon'  => 'fa-solid fa-calendar',
            'label' => trans('sales_by_date'),
            'desc'  => trans('sales_by_year_desc', '', 'Vendite per anno e trimestre, con o senza imposte.'),
        ],
    ];
    ?>

    <div class="ip-report-hub">
        <?php foreach ($ip_reports as $report): ?>
            <a href="<?= site_url('reports/' . $report['slug']) ?>" class="ip-report-card">
                <div class="ip-report-card__head">
                    <span class="ip-report-card__icon">
                        <i class="fa <?= esc($report['icon'], 'attr') ?>" aria-hidden="true"></i>
                    </span>
                    <span class="ip-report-card__title"><?= esc($report['label']) ?></span>
                    <span class="ip-report-card__go" aria-hidden="true"><i class="fa fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                </div>
                <p class="ip-report-card__desc"><?= esc($report['desc']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?= $this->endSection() ?>
