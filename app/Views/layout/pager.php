<?php
/**
 * Pager condiviso per le pagine elenco.
 *
 * Iniettato da $pager->links($group, 'ip_pager'): qui $pager e' un
 * CodeIgniter\Pager\PagerRenderer. Usa il query string ?page= quindi
 * preserva il path e gli altri parametri GET (lo stato filtro, la
 * ricerca live, ecc.). Bootstrap 5.
 */

use CodeIgniter\Pager\PagerRenderer;

/** @var PagerRenderer $pager */
if ($pager->getPageCount() <= 1) {
    return;
}

$pager->setSurroundCount(2);
?>

<nav class="ip-pager" aria-label="<?= esc(trans('page_navigation', '', 'Navigazione pagine')) ?>">
    <span class="ip-pager-info">
        <?= esc(sprintf(trans('page_of', '', 'Pagina %s di %s'), $pager->getCurrentPageNumber(), $pager->getPageCount())) ?>
    </span>
    <ul class="pagination pagination-sm mb-0">
        <?php if ($pager->hasPrevious()): ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="<?= esc(trans('first', '', 'Prima')) ?>">
                    <i class="fa fa-solid fa-angle-double-left" aria-hidden="true"></i>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getPrevious() ?>" aria-label="<?= esc(trans('previous', '', 'Precedente')) ?>">
                    <i class="fa fa-solid fa-angle-left" aria-hidden="true"></i>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-solid fa-angle-double-left" aria-hidden="true"></i></span></li>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-solid fa-angle-left" aria-hidden="true"></i></span></li>
        <?php endif; ?>

        <?php foreach ($pager->links() as $link): ?>
            <li class="page-item <?= $link['active'] ? 'active' : '' ?>">
                <a class="page-link" href="<?= $link['uri'] ?>"><?= $link['title'] ?></a>
            </li>
        <?php endforeach; ?>

        <?php if ($pager->hasNext()): ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getNext() ?>" aria-label="<?= esc(trans('next', '', 'Successiva')) ?>">
                    <i class="fa fa-solid fa-angle-right" aria-hidden="true"></i>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getLast() ?>" aria-label="<?= esc(trans('last', '', 'Ultima')) ?>">
                    <i class="fa fa-solid fa-angle-double-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-solid fa-angle-right" aria-hidden="true"></i></span></li>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-solid fa-angle-double-right" aria-hidden="true"></i></span></li>
        <?php endif; ?>
    </ul>
</nav>
