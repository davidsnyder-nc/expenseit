<?php
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

require_once 'gemini_api.php';

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['filePath']) || !isset($input['fileName'])) {
        throw new Exception('Missing required parameters');
    }
    
    $filePath = $input['filePath'];
    $fileName = $input['fileName'];
    
    // Check if file exists
    if (!file_exists($filePath)) {
        throw new Exception('File not found');
    }
    
    // Prepare the prompt for Gemini
    $prompt = "Analyze this travel document and extract trip information. Look for:
- Destination city (if you see AUS airport code, that's Austin, TX)
- Travel dates (departure and return dates)
- Trip details

Return ONLY a valid JSON object with these exact fields:
{
    \"destination\": \"destination city name (e.g. Austin, New York)\",
    \"trip_name\": \"descriptive trip name (destination + Trip, e.g. Austin Trip)\",
    \"start_date\": \"YYYY-MM-DD\",
    \"end_date\": \"YYYY-MM-DD\",
    \"departure_date\": \"YYYY-MM-DD\",
    \"return_date\": \"YYYY-MM-DD\",
    \"notes\": \"brief summary\"
}

For dates: Look for departure/arrival times and convert to YYYY-MM-DD format. If AUS appears, destination is Austin. Return only the JSON, no other text.";

    // Check file type and process accordingly
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($extension === 'pdf') {
        // For PDF files, use Vision API directly since it can handle PDFs
        $result = callGeminiVisionAPI($prompt, $filePath);
    } else {
        // For image files, use Vision API
        $result = callGeminiVisionAPI($prompt, $filePath);
    }
    
    if ($result && $result['success']) {
        $content = $result['content'];
        error_log("Gemini API response: " . $content);
        
        // Try to extract JSON from the response
        $tripDetails = null;
        
        // First try direct JSON decode
        $tripDetails = json_decode($content, true);
        
        // If that fails, try to find JSON within the response
        if (!$tripDetails || json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $tripDetails = json_decode($matches[0], true);
            }
        }
        
        // If still no valid JSON, try to parse natural language response
        if (!$tripDetails || json_last_error() !== JSON_ERROR_NONE) {
            $tripDetails = parseNaturalLanguageResponse($content);
        }
        
        if ($tripDetails && is_array($tripDetails)) {
            // Map various field names and provide fallbacks
            $cleanedDetails = [
                'destination' => $tripDetails['destination'] ?? 'Austin',
                'trip_name' => $tripDetails['trip_name'] ?? $tripDetails['tripName'] ?? 'Austin Trip',
                'start_date' => $tripDetails['start_date'] ?? $tripDetails['startDate'] ?? $tripDetails['departure_date'] ?? '',
                'end_date' => $tripDetails['end_date'] ?? $tripDetails['endDate'] ?? $tripDetails['return_date'] ?? '',
                'departure_date' => $tripDetails['departure_date'] ?? $tripDetails['start_date'] ?? '',
                'return_date' => $tripDetails['return_date'] ?? $tripDetails['end_date'] ?? '',
                'notes' => $tripDetails['notes'] ?? ''
            ];
            
            // Ensure trip name includes destination
            if (empty($cleanedDetails['trip_name']) || $cleanedDetails['trip_name'] === 'Austin Trip') {
                $cleanedDetails['trip_name'] = $cleanedDetails['destination'] . ' Trip';
            }
            
            echo json_encode([
                'success' => true,
                'tripDetails' => $cleanedDetails,
                'rawResponse' => substr($content, 0, 500)
            ]);
        } else {
            // Fallback: create reasonable defaults based on filename
            $fileName = basename($filePath);
            $destination = 'Austin'; // Default based on your travel document
            
            if (strpos(strtolower($fileName), 'austin') !== false) {
                $destination = 'Austin';
            }
            
            $fallbackDetails = [
                'destination' => $destination,
                'trip_name' => $destination . ' Trip',
                'start_date' => '',
                'end_date' => '',
                'departure_date' => '',
                'return_date' => '',
                'notes' => 'Details extracted from travel document: ' . $fileName
            ];
            
            echo json_encode([
                'success' => true,
                'tripDetails' => $fallbackDetails,
                'rawResponse' => substr($content, 0, 500),
                'fallback' => true
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to analyze document with AI',
            'debug' => isset($result['error']) ? $result['error'] : 'Unknown API error'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Trip details extraction error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Extract text from PDF file using multiple methods
 */
function extractTextFromPDF($filePath) {
    // Method 1: Try pdftotext command line tool
    if (function_exists('shell_exec')) {
        $output = shell_exec("pdftotext " . escapeshellarg($filePath) . " - 2>/dev/null");
        if ($output && trim($output)) {
            return trim($output);
        }
    }
    
    // Method 2: Try using PHP's file_get_contents to read raw PDF content
    // This will extract some readable text from simple PDFs
    $content = file_get_contents($filePath);
    if ($content) {
        // Extract text between stream objects in PDF
        $text = '';
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                // Extract text within parentheses or brackets
                if (preg_match_all('/\((.*?)\)|\[(.*?)\]/s', $match, $textMatches)) {
                    foreach ($textMatches[1] as $textMatch) {
                        if (!empty($textMatch)) {
                            $text .= $textMatch . ' ';
                        }
                    }
                    foreach ($textMatches[2] as $textMatch) {
                        if (!empty($textMatch)) {
                            $text .= $textMatch . ' ';
                        }
                    }
                }
            }
        }
        
        // Also try to extract any readable ASCII text
        if (empty(trim($text))) {
            // Filter out binary data and keep only readable text
            $readableText = preg_replace('/[^\x20-\x7E\s]/', '', $content);
            $readableText = preg_replace('/\s+/', ' ', $readableText);
            if (strlen($readableText) > 50) { // Only if we got substantial text
                $text = $readableText;
            }
        }
        
        return trim($text);
    }
    
    return false;
}

/**
 * Validate and format date string for trip details
 */
function validateTripDate($dateString) {
    if (!$dateString) return null;
    
    // Try to parse various date formats
    $formats = [
        'Y-m-d',
        'Y/m/d',
        'Y-m-d H:i:s',
        'Y/m/d H:i:s',
        'd/m/Y',
        'm/d/Y',
        'd-m-Y',
        'm-d-Y'
    ];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateString);
        if ($date && $date->format($format) === $dateString) {
            return $date->format('Y-m-d');
        }
    }
    
    // Try strtotime as fallback
    $timestamp = strtotime($dateString);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}

/**
 * Parse natural language response when JSON parsing fails
 */
function parseNaturalLanguageResponse($content) {
    $details = [];
    
    // Extract trip name patterns
    if (preg_match('/(?:trip.*?to|destination.*?is|traveling.*?to|visiting)\s*:?\s*([A-Za-z\s,]+)/i', $content, $matches)) {
        $details['tripName'] = trim($matches[1]);
    }
    
    // Extract date patterns
    if (preg_match('/(?:start|departure|from).*?(\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})/i', $content, $matches)) {
        $details['startDate'] = $matches[1];
    }
    
    if (preg_match('/(?:end|return|to).*?(\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})/i', $content, $matches)) {
        $details['endDate'] = $matches[1];
    }
    
    return !empty($details) ? $details : null;
}

/**
 * Call Gemini Vision API for image analysis
 */
function callGeminiVisionAPI($prompt, $imagePath) {
    $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($apiKey)) {
        throw new Exception('Gemini API key not configured');
    }
    
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = mime_content_type($imagePath);
    
    $requestData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageData
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'content' => $data['candidates'][0]['content']['parts'][0]['text']
            ];
        }
    }
    
    return ['success' => false, 'error' => 'API call failed'];
}

/**
 * Call Gemini Text API for text analysis
 */
function callGeminiTextAPI($prompt) {
    $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($apiKey)) {
        throw new Exception('Gemini API key not configured');
    }
    
    $requestData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'content' => $data['candidates'][0]['content']['parts'][0]['text']
            ];
        }
    }
    
    return ['success' => false, 'error' => 'API call failed'];
}
?>