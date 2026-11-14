<?php

namespace App\Libraries;

/**
 * Sostituzione dei tag {{tag}} nei testi delle email.
 *
 * Prima questa logica stava nel controller Mailer e inseriva i dati cosi'
 * com'erano: un cliente che si chiama `<b>Rossi</b>` (o che ha scritto cosi'
 * il proprio nome) finiva come markup nel corpo HTML dell'email. Non e' una
 * XSS nel browser dell'utente -- il messaggio parte verso altri -- ma e' HTML
 * che il destinatario non ha scritto, e con un `<a href>` o un'immagine di
 * tracciamento permette di costruire contenuti che sembrano dell'app.
 *
 * Due sintassi, come nei template esistenti:
 *  - `{{tag}}`   il valore va escapato: e' testo dentro l'HTML;
 *  - `{{{tag}}}` il valore va inserito grezzo: serve ai template che
 *    costruiscono markup (es. un link gia' pronto).
 *
 * Sta fuori dal controller anche per poter essere provata senza database:
 * l'unica dipendenza e' l'helper esc() del framework.
 */
final class MailTemplate
{
    /**
     * Tre graffe o due, stesso nome: cattura solo il nome del tag.
     */
    private const TAG_PATTERN = '/\{\{\{?([a-z0-9_]+)\}?\}\}/i';

    /**
     * @param array<string, scalar|null> $data
     * @param bool                       $escape false per i campi che non sono
     *                                           HTML (oggetto, indirizzi,
     *                                           nome mittente): li' un
     *                                           `&amp;` resterebbe scritto
     *                                           cosi' nel messaggio.
     */
    public static function render(string $text, array $data, bool $escape = true): string
    {
        if (!preg_match_all(self::TAG_PATTERN, $text, $templateVars)) {
            return $text;
        }

        foreach (array_unique($templateVars[1]) as $var) {
            if (!array_key_exists($var, $data)) {
                // Tag sconosciuto: resta com'è (visibile) invece di essere
                // sostituito col suo nome, che distrugge silenziosamente il
                // contenuto del template.
                continue;
            }

            $raw = (string) $data[$var];

            // Prima le tre graffe, poi le due: "{{tag}}" e' una sottostringa
            // di "{{{tag}}}", quindi invertendo l'ordine resterebbero le
            // graffe esterne.
            $text = str_replace('{{{' . $var . '}}}', $raw, $text);
            $text = str_replace('{{' . $var . '}}', $escape ? esc($raw) : $raw, $text);
        }

        return $text;
    }
}
