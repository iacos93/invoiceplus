<?php
/**
 * Partial headerbar — standardizza la barra di intestazione di ogni schermata.
 *
 * Elimina il boilerplate <div id="headerbar"><h1>...</h1><div class="...">...</div></div>
 * ripetuto in 40+ view, e garantisce gerarchia/spacing coerenti.
 *
 * Uso (in una view che estende layout/base o layout/guest):
 *
 *   <?= $this->extend('layout/base') ?>
 *   <?= $this->section('content') ?>
 *   <?php
 *   $actions = '<a class="btn btn-sm btn-primary" href="' . site_url('payments/form') . '">'
 *             . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('enter_payment') . '</a>';
 *   $filters = '<div class="btn-group btn-group-sm index-options">...</div>'; // opzionale
 *   ?>
 *   <?= $this->include('layout/headerbar', [
 *       'title'   => trans('payments'),
 *       'actions' => $actions,   // markup HTML (già strapped di esc() dove serve)
 *       'filters' => $filters ?? '',   // opzionale: filtri visibili solo su desktop
 *       'meta'    => $meta ?? '',      // opzionale: metadata sotto il titolo
 *   ]) ?>
 *
 *   <div id="content">...</div>
 *   <?= $this->endSection() ?>
 *
 * Solo `title` e' obbligatorio. `actions` e `filters` sono markup HTML gia'
 * composto dalla view che chiama: il partial non esegue esc() per non rompere
 * link/icone. `meta` e' testo/HTML per breadcrumb/contesto (es. numero fattura).
 */
$_hb     = (array) ($this->renderVars['options'] ?? []);
$title   = $_hb['title']   ?? ($title   ?? '');
$actions = $_hb['actions'] ?? ($actions ?? '');
$filters = $_hb['filters'] ?? ($filters ?? '');
$meta    = $_hb['meta']    ?? ($meta    ?? '');
?>
<div id="headerbar">
    <h1 class="headerbar-title"><?= $title ?></h1>

    <?php if (!empty($filters)): ?>
        <div class="headerbar-item float-end d-none d-lg-block">
            <?= $filters ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($actions)): ?>
        <div class="headerbar-item float-end">
            <?= $actions ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($meta)): ?>
        <div class="headerbar-meta">
            <?= $meta ?>
        </div>
    <?php endif; ?>
</div>
