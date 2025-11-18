<?php
/**
 * Router para PHP Built-in Server
 * Permite que o servidor embutido funcione com Slim Framework
 */

// Se for um arquivo estático, retornar false para servir o arquivo
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $_SERVER['REQUEST_URI'];
    if (is_file($file)) {
        return false;
    }
}

// Caso contrário, processar com index.php
require __DIR__ . '/index.php';
