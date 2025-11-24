<?php
/**
 * Router para PHP Built-in Server
 * Permite que o servidor embutido funcione com Slim Framework
 */

// Configurar CORS headers antes de qualquer processamento
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 3600');

// Responder a preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Se for um arquivo estático, retornar false para servir o arquivo
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $_SERVER['REQUEST_URI'];
    if (is_file($file)) {
        return false;
    }
}

// Caso contrário, processar com index.php
require __DIR__ . '/index.php';
