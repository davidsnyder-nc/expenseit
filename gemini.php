<?php
require_once 'gemini_api.php';

// Load environment variables immediately
if (function_exists('loadEnvFile')) {
    loadEnvFile();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['filePath']) || !isset($input['fileName'])) {
        throw new Exception('Missing required parameters');
    }
    
    $filePath = $input['filePath'];
    $fileName = $input['fileName'];
    
    // Validate file exists
    if (!file_exists($filePath)) {
        throw new Exception('File not found');
    }
    
    // Load environment variables and get API key
    if (function_exists('loadEnvFile')) {
        loadEnvFile();
    }
    $apiKey = getenv('GEMINI_API_KEY');
    
    if (!$apiKey || $apiKey === 'your_gemini_api_key_here') {
        throw new Exception('Gemini API key not configured');
    }
    
    // Process file based on type
    $mimeType = mime_content_type($filePath);
    $expense = processWithGemini($filePath, $fileName, $mimeType, $apiKey);
    
    echo json_encode([
        'success' => true,
        'expense' => $expense
    ]);
    
} catch (Exception $e) {
    error_log('Gemini processing error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


?>
