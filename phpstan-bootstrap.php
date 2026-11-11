<?php

/**
 * Costanti di percorso di CodeIgniter per PHPStan.
 *
 * A runtime le definisce il bootstrap dell'applicazione (public/index.php o
 * spark); PHPStan analizza i file uno per uno e senza queste righe segnala
 * come "constant not found" ogni uso di WRITEPATH, APPPATH e simili — che
 * sono invece definite ovunque l'applicazione giri davvero.
 *
 * Non viene incluso dall'applicazione: serve solo a `vendor/bin/phpstan`.
 */
foreach ([
    'APPPATH'     => __DIR__ . '/app/',
    'FCPATH'      => __DIR__ . '/public/',
    'ROOTPATH'    => __DIR__ . '/',
    'SYSTEMPATH'  => __DIR__ . '/vendor/codeigniter4/framework/system/',
    'TESTPATH'    => __DIR__ . '/tests/',
    'WRITEPATH'   => __DIR__ . '/writable/',
    'ENVIRONMENT' => 'development',
] as $name => $path) {
    if (! defined($name)) {
        define($name, $path);
    }
}
