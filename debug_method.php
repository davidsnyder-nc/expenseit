<?php
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'get_data' => $_GET,
    'server_vars' => [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
        'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? ''
    ]
]);
?>