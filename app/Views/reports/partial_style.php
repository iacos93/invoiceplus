<?php
/**
 * Foglio dei report, caricato solo dove serve.
 *
 * Non sta in layout/base.php perché lo usano cinque schermate su un centinaio:
 * 34 KB (9 KB compressi) su ogni pagina per un prospetto che nessuno sta
 * guardando non si giustificano.
 *
 * Non sta nemmeno nell'<head>: la navigazione parziale (ip-pjax.js) sostituisce
 * solo #main-content, quindi un foglio linkato nella testa non arriverebbe
 * aprendo un report dalla sidebar — resterebbe la pagina senza stile fino al
 * primo ricaricamento completo. Qui viaggia col contenuto, che è l'unica parte
 * che il pjax scambia; `<link rel="stylesheet">` dentro il body è valido in
 * HTML e il browser lo applica appena lo incontra.
 */
?>
<link rel="stylesheet" href="<?php _theme_asset('css/reports.css'); ?>">
