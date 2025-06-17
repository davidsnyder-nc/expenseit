<?php

function loadEnvFile() {
    if (file_exists('.env')) {
        $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && !startsWith(trim($line), '#')) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (!getenv($key)) {
                    putenv("$key=$value");
                }
            }
        }
    }
}

function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function callGeminiVisionAPI($prompt, $filePath) {
    // Load environment variables from .env file
    loadEnvFile();
    
    $apiKey = getenv('GEMINI_API_KEY');
    
    if (!$apiKey || $apiKey === 'your_gemini_api_key_here') {
        throw new Exception('Gemini API key not configured');
    }
    
    try {
        // Prepare the file for upload
        $fileData = file_get_contents($filePath);
        $mimeType = mime_content_type($filePath);
        $base64Data = base64_encode($fileData);
        
        // Prepare the request payload
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ],
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
                'topK' => 32,
                'topP' => 1,
                'maxOutputTokens' => 4096
            ]
        ];
        
        // Make the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Curl error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('API request failed with status: ' . $httpCode . ' Response: ' . $response);
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid API response format');
        }
        
        return [
            'success' => true,
            'content' => $result['candidates'][0]['content']['parts'][0]['text']
        ];
        
    } catch (Exception $e) {
        error_log('Gemini API error: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function processWithGemini($filePath, $fileName, $mimeType, $apiKey) {
    $prompt = "Analyze this receipt and extract expense information. Return ONLY valid JSON in this exact format:
{
  \"date\": \"YYYY-MM-DD\",
  \"merchant\": \"merchant name\",
  \"amount\": 0.00,
  \"tax_amount\": 0.00,
  \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
  \"note\": \"brief description\"
}

Extract the transaction date, merchant name, total amount, tax amount (if shown), appropriate category, and brief description. For hotels, use category 'Lodging'. For restaurants/food, use 'Meals'. For gas stations, use 'Gas'. Return only the JSON, no additional text.";

    $result = callGeminiVisionAPI($prompt, $filePath);
    
    if ($result && $result['success']) {
        $content = $result['content'];
        
        // Try to extract JSON from the response
        $expense = json_decode($content, true);
        
        // If direct decode fails, try to find JSON within the response
        if (!$expense || json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $expense = json_decode($matches[0], true);
            }
        }
        
        if ($expense && is_array($expense)) {
            // Clean and validate expense data
            return [
                'date' => $expense['date'] ?? date('Y-m-d'),
                'merchant' => $expense['merchant'] ?? 'Unknown Merchant',
                'amount' => floatval($expense['amount'] ?? 0),
                'tax_amount' => floatval($expense['tax_amount'] ?? 0),
                'category' => $expense['category'] ?? 'Other',
                'note' => $expense['note'] ?? 'Processed receipt',
                'filename' => $fileName
            ];
        }
    }
    
    // Fallback if processing fails
    return [
        'date' => date('Y-m-d'),
        'merchant' => 'Unknown Merchant', 
        'amount' => 0.00,
        'tax_amount' => 0.00,
        'category' => 'Other',
        'note' => 'Failed to process automatically',
        'filename' => $fileName
    ];
}

function detectTravelDocument($filePath, $fileName) {
    // For now, everything is treated as a receipt
    return false;
}

function extractTripDetailsFromDocument($filePath, $fileName) {
    $prompt = "Analyze this travel document and extract trip information. Return ONLY valid JSON in this exact format:
{
  \"destination\": \"City, State\",
  \"trip_name\": \"City\", 
  \"start_date\": \"YYYY-MM-DD\",
  \"end_date\": \"YYYY-MM-DD\",
  \"notes\": \"travel details\"
}

Extract the destination city and state, suggested trip name (usually just the city), start date, end date, and any relevant notes. Return only the JSON, no additional text.";

    $result = callGeminiVisionAPI($prompt, $filePath);
    
    if ($result && $result['success']) {
        $content = $result['content'];
        
        $details = json_decode($content, true);
        
        if (!$details || json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $details = json_decode($matches[0], true);
            }
        }
        
        if ($details && is_array($details)) {
            return $details;
        }
    }
    
    return null;
}

function analyzeFileWithGemini($filePath, $fileName) {
    try {
        // Since everything is treated as receipts, use receipt analysis
        $analysisPrompt = "Analyze this receipt and extract expense information. Return ONLY valid JSON in this exact format:
{
    \"type\": \"receipt\",
    \"date\": \"YYYY-MM-DD\",
    \"merchant\": \"merchant name\",
    \"amount\": 0.00,
    \"tax_amount\": 0.00,
    \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
    \"note\": \"description\",
    \"location\": \"City, State\",
    \"is_hotel_stay\": false,
    \"daily_breakdown\": []
}

CRITICAL LOCATION EXTRACTION (UNIVERSAL):
- ALWAYS extract ANY location information from the receipt
- Look for merchant addresses, store locations, city names, airport codes, country names
- Extract the most specific geographic information available
- Common patterns: \"Seattle, WA\", \"Portland, OR\", \"Denver, CO\", \"Boston, MA\"
- International: \"London, UK\", \"Paris, France\", \"Toronto, Canada\", \"Sydney, Australia\"
- Airports: Convert codes to cities (JFK=New York, LAX=Los Angeles, DEN=Denver, ATL=Atlanta, ORD=Chicago)
- Full addresses: Extract city/state from \"123 Main St, Chicago, IL 60601\"
- Even partial locations: \"Downtown Portland\", \"Times Square NYC\", \"Beverly Hills\"
- If you see ANY geographic reference, include it in the location field

HOTEL PROCESSING:
For hotel receipts with multiple nights, carefully extract each daily rate:
- Set is_hotel_stay to true
- Look for daily room rates, nightly charges, or per-night amounts
- Extract taxes for each night (room tax, city tax, etc.)
- Include each night as a separate entry in daily_breakdown array:
[
    {
        \"date\": \"YYYY-MM-DD\",
        \"room_rate\": 150.00,
        \"tax_rate\": 15.00,
        \"tax_percentage\": \"10.0%\",
        \"daily_total\": 165.00
    }
]

Pay special attention to addresses, location references, airport codes, and any geographic information on the receipt. Extract the actual dollar amounts for hotel daily rates, not zeros.";

        $result = callGeminiVisionAPI($analysisPrompt, $filePath);
        
        if ($result && $result['success']) {
            $content = $result['content'];
            
            // Extract JSON from response
            $analysis = json_decode($content, true);
            
            if (!$analysis || json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $analysis = json_decode($matches[0], true);
                }
            }
            
            if ($analysis && is_array($analysis)) {
                $analysis['filename'] = $fileName;
                $analysis['gemini_processed'] = true;
                
                // Ensure required fields are present
                $analysis['type'] = 'receipt';
                $analysis['date'] = $analysis['date'] ?? date('Y-m-d');
                $analysis['merchant'] = $analysis['merchant'] ?? 'Unknown Merchant';
                $analysis['amount'] = floatval($analysis['amount'] ?? 0);
                $analysis['tax_amount'] = floatval($analysis['tax_amount'] ?? 0);
                $analysis['category'] = $analysis['category'] ?? 'Other';
                $analysis['note'] = $analysis['note'] ?? 'Processed receipt';
                $analysis['location'] = $analysis['location'] ?? '';
                $analysis['is_hotel_stay'] = $analysis['is_hotel_stay'] ?? false;
                $analysis['daily_breakdown'] = $analysis['daily_breakdown'] ?? [];
                
                return $analysis;
            }
        }
        
        // Fallback if Gemini analysis fails
        return [
            'type' => 'receipt',
            'filename' => $fileName,
            'date' => date('Y-m-d'),
            'merchant' => 'Unknown',
            'amount' => 0,
            'tax_amount' => 0,
            'category' => 'Other',
            'note' => 'Failed to process with AI',
            'location' => '',
            'is_hotel_stay' => false,
            'daily_breakdown' => [],
            'gemini_processed' => false
        ];
        
    } catch (Exception $e) {
        error_log("Gemini analysis error for $fileName: " . $e->getMessage());
        return [
            'type' => 'receipt',
            'filename' => $fileName,
            'date' => date('Y-m-d'),
            'merchant' => 'Unknown',
            'amount' => 0,
            'tax_amount' => 0,
            'category' => 'Other',
            'note' => 'Error: ' . $e->getMessage(),
            'location' => '',
            'is_hotel_stay' => false,
            'daily_breakdown' => [],
            'gemini_processed' => false
        ];
    }
}

function processReceiptWithGemini($filePath) {
    try {
        $prompt = "Analyze this receipt and extract:
{
  \"date\": \"YYYY-MM-DD\",
  \"merchant\": \"merchant name\",
  \"amount\": 0.00,
  \"tax_amount\": 0.00,
  \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
  \"note\": \"brief description\"
}";

        $result = callGeminiVisionAPI($prompt, $filePath);
        
        if ($result && $result['success']) {
            $content = $result['content'];
            
            $expense = json_decode($content, true);
            
            if (!$expense || json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $expense = json_decode($matches[0], true);
                }
            }
            
            if ($expense && is_array($expense)) {
                return [
                    'date' => $expense['date'] ?? date('Y-m-d'),
                    'merchant' => $expense['merchant'] ?? 'Unknown',
                    'amount' => floatval($expense['amount'] ?? 0),
                    'tax_amount' => floatval($expense['tax_amount'] ?? 0),
                    'category' => $expense['category'] ?? 'Other',
                    'note' => $expense['note'] ?? 'Receipt'
                ];
            }
        }
        
        return [
            'date' => date('Y-m-d'),
            'merchant' => 'Unknown',
            'amount' => 0,
            'tax_amount' => 0,
            'category' => 'Other',
            'note' => 'Failed to process'
        ];
        
    } catch (Exception $e) {
        error_log("Receipt processing error: " . $e->getMessage());
        return null;
    }
}

?>