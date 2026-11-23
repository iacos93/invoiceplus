<script>
/* Copia negli appunti i codici della tessera anagrafica (cliente e fornitore):
   utile per incollarli in FatturaPA, nel cassetto fiscale o in un bonifico
   senza ricopiarli a mano. I pulsanti li crea fisc_code_block(). */
(function () {
    document.querySelectorAll('.ip-fisc-code__copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var value = btn.getAttribute('data-copy-code');
            if (!value || !navigator.clipboard) { return; }
            navigator.clipboard.writeText(value).then(function () {
                var icon = btn.querySelector('i');
                if (!icon) { return; }
                var prev = icon.className;
                icon.className = 'fa fa-check';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    icon.className = prev;
                    btn.classList.remove('is-copied');
                }, 1200);
            });
        });
    });
})();
</script>
