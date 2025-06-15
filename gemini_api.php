<?php
/**
 * Gemini API utility functions
 */

/**
 * Make a text-only API call to Gemini
 */
function callGeminiAPI($prompt) {
    $apiKey = getenv('GEMINI_API_KEY');
    
    if (!$apiKey) {
        throw new Exception('Gemini API key not configured');
    }
    
    $requestData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 256
        ]
    ];
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        throw new Exception('API request failed: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('API request failed with HTTP ' . $httpCode);
    }
    
    return json_decode($response, true);
}

/**
 * Call Gemini Vision API for travel document analysis
 */
function callGeminiVisionAPI($prompt, $filePath) {
    $apiKey = getenv('GEMINI_API_KEY');
    
    if (!$apiKey) {
        throw new Exception('Gemini API key not configured');
    }
    
    // Read and encode file
    $fileData = file_get_contents($filePath);
    if ($fileData === false) {
        throw new Exception('Failed to read file');
    }
    
    $base64Data = base64_encode($fileData);
    
    // Determine MIME type based on file extension
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeType = match($extension) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => 'application/octet-stream'
    };
    
    // Prepare API request
    $requestData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Data
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 1024
        ]
    ];
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        curl_close($ch);
        throw new Exception('API request failed: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Gemini API HTTP Error $httpCode: $response");
        return ['success' => false, 'error' => "API request failed with HTTP $httpCode"];
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Invalid Gemini API response: $response");
        return ['success' => false, 'error' => 'Invalid API response format'];
    }
    
    $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    return [
        'success' => true,
        'content' => $content
    ];
}

/**
 * Process file with Gemini Vision API
 */
function processWithGemini($filePath, $fileName, $mimeType, $apiKey) {
    // Read and encode file
    $fileData = file_get_contents($filePath);
    $base64Data = base64_encode($fileData);
    
    // Prepare the prompt
    $prompt = "Analyze this receipt image and extract the following information in JSON format:
{
  \"date\": \"YYYY-MM-DD\",
  \"merchant\": \"merchant name\",
  \"amount\": 0.00,
  \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
  \"note\": \"brief description\"
}

Extract the transaction date, merchant/business name, total amount, and categorize the expense. If any information is unclear, make reasonable assumptions. For the category, choose the most appropriate from the list provided.";
    
    // Prepare API request
    $requestData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Data
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 1024
        ]
    ];
    
    // Make API request
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        throw new Exception('API request failed: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('API request failed with HTTP ' . $httpCode);
    }
    
    // Parse response
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid API response format');
    }
    
    $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    // Extract JSON from response
    $expense = extractJsonFromText($generatedText);
    
    // Validate and clean up expense data
    $expense = validateAndCleanExpense($expense, $fileName);
    
    return $expense;
}

/**
 * Extract JSON from generated text
 */
function extractJsonFromText($text) {
    // Try to find JSON in the text
    $patterns = [
        '/\{[^}]*\}/', // Simple JSON pattern
        '/```json\s*(\{.*?\})\s*```/s', // JSON in code blocks
        '/```\s*(\{.*?\})\s*```/s' // JSON in code blocks without json specifier
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $jsonText = isset($matches[1]) ? $matches[1] : $matches[0];
            $decoded = json_decode($jsonText, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }
    
    // If no JSON found, try to parse the entire text as JSON
    $decoded = json_decode($text, true);
    if ($decoded !== null) {
        return $decoded;
    }
    
    throw new Exception('Could not extract valid JSON from API response');
}

/**
 * Validate and clean up expense data
 */
function validateAndCleanExpense($expense, $fileName) {
    // Set defaults for missing fields
    $cleanExpense = [
        'date' => $expense['date'] ?? date('Y-m-d'),
        'merchant' => $expense['merchant'] ?? 'Unknown',
        'amount' => floatval($expense['amount'] ?? 0),
        'category' => $expense['category'] ?? 'Other',
        'note' => $expense['note'] ?? ''
    ];
    
    // Validate date format
    if (!validateDate($cleanExpense['date'])) {
        $cleanExpense['date'] = date('Y-m-d');
    }
    
    // Validate category
    $validCategories = ['Meals', 'Transportation', 'Lodging', 'Entertainment', 'Groceries', 'Shopping', 'Gas', 'Other'];
    if (!in_array($cleanExpense['category'], $validCategories)) {
        $cleanExpense['category'] = 'Other';
    }
    
    // Ensure amount is positive
    if ($cleanExpense['amount'] <= 0) {
        $cleanExpense['amount'] = 0.01; // Minimum amount
    }
    
    // Clean up merchant name
    $cleanExpense['merchant'] = trim($cleanExpense['merchant']);
    if (empty($cleanExpense['merchant'])) {
        $cleanExpense['merchant'] = pathinfo($fileName, PATHINFO_FILENAME);
    }
    
    // Clean up note
    $cleanExpense['note'] = trim($cleanExpense['note']);
    
    return $cleanExpense;
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}
?>