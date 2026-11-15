<?php
/**
 * Partial: evidenziatore e comandi di un blocco XML.
 *
 * Le due schermate che mostrano un tracciato — l'anteprima (scheda "XML
 * grezzo") e la verifica dello schema FatturaPA — mostravano lo stesso XML in
 * due modi diversi: solo la prima lo colorava, con lo script scritto dentro la
 * vista. Qui il codice sta in un posto solo e colora qualsiasi <code> dentro
 * [data-ip-xml-block].
 *
 * I due comandi sono quelli che servono davvero su un tracciato lungo:
 * copiare tutto (per incollarlo in un validator esterno) e andare a capo
 * invece di scorrere in orizzontale — la riga del namespace, da sola, è più
 * larga di qualunque schermo.
 *
 * L'anteprima tiene la scheda nascosta all'apertura: lì l'evidenziazione la
 * chiede la vista quando mostra la scheda, con window.ipHighlightXml().
 */

?>
<script>
(function () {
    if (window.ipHighlightXml) {
        return;
    }

    window.ipHighlightXml = function (scope) {
        (scope || document).querySelectorAll('[data-ip-xml-block] code').forEach(function (code) {
            if (code.dataset.highlighted) {
                return;
            }

            const escaped = code.textContent.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            code.innerHTML = escaped
                .replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<span class="xml-comment">$1</span>')
                .replace(/(&lt;\?xml[\s\S]*?\?&gt;)/gi, '<span class="xml-prolog">$1</span>')
                .replace(/(&lt;\/?[A-Za-z_:][^&]*?)(\s+[A-Za-z_:][\w:.-]*)(=)(&quot;[^&]*?&quot;)/g, '<span class="xml-tag">$1</span><span class="xml-attr">$2</span>$3<span class="xml-value">$4</span>')
                .replace(/(&lt;\/?[A-Za-z_:][\w:.-]*)(?=[\s&gt;])/g, '<span class="xml-tag">$1</span>');

            code.dataset.highlighted = '1';
        });
    };

    // L'API Clipboard richiede un contesto sicuro (https o localhost): in
    // http, o su un browser vecchio, si ripiega sulla selezione temporanea.
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.top = '-1000px';
            document.body.appendChild(area);
            area.select();

            const done = document.execCommand && document.execCommand('copy');
            document.body.removeChild(area);

            done ? resolve() : reject(new Error('copia non disponibile'));
        });
    }

    function init() {
        document.querySelectorAll('[data-ip-xml-block]').forEach(function (block) {
            if (block.dataset.ipXmlReady) {
                return;
            }

            block.dataset.ipXmlReady = '1';

            const code = block.querySelector('code') || block.querySelector('pre');

            block.addEventListener('click', function (event) {
                const copy = event.target.closest('[data-ip-xml-copy]');
                const wrap = event.target.closest('[data-ip-xml-wrap]');

                if (copy && code) {
                    const label = copy.dataset.label || copy.textContent;

                    copyText(code.textContent).then(function () {
                        copy.dataset.label = label;
                        copy.textContent = copy.dataset.copiedLabel || 'Copiato';
                        copy.classList.add('is-done');

                        setTimeout(function () {
                            copy.textContent = label;
                            copy.classList.remove('is-done');
                        }, 1600);
                    }).catch(function () {
                        // Niente clipboard: il testo resta selezionabile a mano,
                        // un messaggio d'errore qui non aggiungerebbe nulla.
                    });
                }

                if (wrap) {
                    const on = block.classList.toggle('is-wrapped');
                    wrap.setAttribute('aria-pressed', on ? 'true' : 'false');
                }
            });
        });

        window.ipHighlightXml();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
