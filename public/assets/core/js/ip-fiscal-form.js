/* Parametri fiscali: scala progressiva IRPEF.
   La scala e' la firma della schermata: ogni scaglione diventa una barra
   la cui altezza e' l'aliquota, cosi' la natura "a gradini" dell'imposta si
   legge prima dei numeri. */

(function () {
  /* --- Scala progressiva IRPEF: ridisegna le barre dagli scaglioni --- */
  var table = document.querySelector('.ip-brackets');
  var ladder = document.getElementById('ip-ladder');
  if (table && ladder) {
    function render() {
      var rates = Array.prototype.slice.call(
        table.querySelectorAll('input[name="bracket_rate[]"]')
      ).map(function (i) { return parseFloat(i.value) || 0; });
      var limits = Array.prototype.slice.call(
        table.querySelectorAll('input[name="bracket_limit[]"]')
      ).map(function (i) { return i.value.trim() === '' ? null : parseFloat(i.value); });
      var max = rates.reduce(function (m, r) { return Math.max(m, r); }, 0) || 1;

      ladder.innerHTML = '';
      rates.forEach(function (rate, idx) {
        var col = document.createElement('div');
        col.className = 'ip-ladder__col';

        var bar = document.createElement('div');
        bar.className = 'ip-ladder__bar';
        bar.style.height = (rate / max * 100) + '%';
        // Intensita' proporzionale: gli scaglioni piu' alti pesano di piu'.
        bar.style.opacity = rate > 0 ? (0.45 + 0.55 * (rate / max)).toFixed(2) : '0.15';

        var rateLabel = document.createElement('span');
        rateLabel.className = 'ip-ladder__rate';
        rateLabel.textContent = rate > 0 ? rate + '%' : '';

        var limitLabel = document.createElement('span');
        limitLabel.className = 'ip-ladder__limit';
        if (limits[idx] === null) {
          limitLabel.textContent = '∞';
          limitLabel.title = 'nessun tetto';
        } else {
          limitLabel.textContent = limits[idx] > 0 ? '≤' + limits[idx] : '';
        }

        col.appendChild(bar);
        col.appendChild(rateLabel);
        col.appendChild(limitLabel);
        ladder.appendChild(col);
      });
    }

    table.addEventListener('input', render);
    render();
  }
})();
