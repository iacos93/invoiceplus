<?php

namespace App\Services;

/**
 * Regola comune delle decisioni su un preventivo (approvazione, rifiuto).
 *
 * Gli stessi stati (2 inviato, 3 visto) e gli stessi messaggi d'errore erano
 * ripetuti in quattro punti: portale guest e link pubblico, approvazione e
 * rifiuto. La regola è una sola: si decide solo su un preventivo ancora
 * aperto, altrimenti il messaggio racconta una decisione mai avvenuta
 * (pagina vecchia, doppio clic, altro tab).
 */
final class QuoteDecision
{
    public const APPROVE = 'approve';
    public const REJECT  = 'reject';

    /** Stati dai quali una decisione è ancora possibile. */
    private const OPEN_STATUSES = [2, 3];

    /**
     * Messaggio d'errore quando la decisione non è possibile, null se lo è.
     */
    public static function error(array $quote, string $decision): ?string
    {
        if (in_array((int) ($quote['quote_status_id'] ?? 0), self::OPEN_STATUSES, true)) {
            return null;
        }

        return $decision === self::APPROVE
            ? trans('quote_not_approvable', '', 'Il preventivo non e piu in uno stato approvabile.')
            : trans('quote_not_rejectable', '', 'Il preventivo non e piu in uno stato rifiutabile.');
    }
}
