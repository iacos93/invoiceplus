<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('dashboard', '', 'Bacheca')); ?>
<?= $this->include('layout/headerbar', ['title' => trans('dashboard', '', 'Bacheca')]) ?>

<div id="content">
    <div class="row">

        <div class="col-12 col-sm-6 col-md-3">
            <div class="card">
                <div class="card-header"><?= trans('open_invoices', '', 'Fatture aperte') ?></div>
                <div class="card-body">
                    <div class="count"><?= (int) $open_invoices ?></div>
                    <a class="btn btn-secondary btn-sm" href="<?= site_url('guest/invoices/status/open') ?>">
                        <?= trans('view_invoices', '', 'Vedi fatture') ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
            <div class="card">
                <div class="card-header"><?= trans('overdue_invoices', '', 'Fatture scadute') ?></div>
                <div class="card-body">
                    <div class="count"><?= (int) $overdue_invoices ?></div>
                    <a class="btn btn-secondary btn-sm" href="<?= site_url('guest/invoices/status/overdue') ?>">
                        <?= trans('view_invoices', '', 'Vedi fatture') ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
            <div class="card">
                <div class="card-header"><?= trans('quotes_requiring_approval', '', 'Preventivi da approvare') ?></div>
                <div class="card-body">
                    <div class="count"><?= (int) $open_quotes ?></div>
                    <a class="btn btn-secondary btn-sm" href="<?= site_url('guest/quotes/status/open') ?>">
                        <?= trans('view_quotes', '', 'Vedi preventivi') ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
            <div class="card">
                <div class="card-header"><?= trans('payments', '', 'Pagamenti') ?></div>
                <div class="card-body">
                    <div class="count"><?= (int) $payments ?></div>
                    <a class="btn btn-secondary btn-sm" href="<?= site_url('guest/payments') ?>">
                        <?= trans('view_payments', '', 'Vedi pagamenti') ?>
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
