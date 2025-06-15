<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'gemini_api.php';

function sanitizeName($name) {
    $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
    $name = str_replace(' ', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_') ?: 'untitled';
}

function detectDocumentType($fileName) {
    // Everything is treated as a receipt
    return 'receipt';
}

function processReceiptFile($filePath, $fileName) {
    try {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream'
        };
        
        $prompt = "Analyze this receipt image and extract the following information in JSON format:
{
  \"date\": \"YYYY-MM-DD\",
  \"merchant\": \"merchant name\",
  \"amount\": 0.00,
  \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
  \"note\": \"brief description\"
}

Extract the transaction date, merchant/business name, total amount, and categorize the expense. If any information is unclear, make reasonable assumptions. For the category, choose the most appropriate from the list provided.";

        $result = callGeminiVisionAPI($prompt, $filePath);
        
        if ($result && $result['success']) {
            $content = $result['content'];
            
            // Try to extract JSON from the response
            $expense = null;
            
            // First try direct JSON decode
            $expense = json_decode($content, true);
            
            // If that fails, try to find JSON within the response
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
                    'category' => $expense['category'] ?? 'Other',
                    'note' => $expense['note'] ?? 'Processed receipt'
                ];
            }
        }
        
        // Fallback if processing fails
        return [
            'date' => date('Y-m-d'),
            'merchant' => 'Unknown Merchant',
            'amount' => 0.00,
            'category' => 'Other',
            'note' => 'Failed to process receipt automatically'
        ];
        
    } catch (Exception $e) {
        error_log("Receipt processing error: " . $e->getMessage());
        return null;
    }
}

function processTravelDocument($filePath, $fileName) {
    try {
        $prompt = "Analyze this travel document and extract trip information. Look for:
- Destination city (AUS = Austin TX, LAX = Los Angeles, JFK/LGA = New York, etc.)
- Travel dates (departure and return dates)
- Trip details and purpose

Important: 
- Extract the actual destination city name, not airport codes
- Create a proper trip name using the destination
- Ensure dates are in YYYY-MM-DD format
- If you see Austin, Texas or AUS airport code, the destination is \"Austin, TX\"

Return ONLY a valid JSON object with these exact fields:
{
    \"destination\": \"destination city, state (e.g. Austin, TX)\",
    \"trip_name\": \"destination without state (e.g. Austin)\",
    \"start_date\": \"YYYY-MM-DD\",
    \"end_date\": \"YYYY-MM-DD\",
    \"departure_date\": \"YYYY-MM-DD\",
    \"return_date\": \"YYYY-MM-DD\",
    \"notes\": \"brief summary\"
}

For dates: Look for departure/arrival times and convert to YYYY-MM-DD format. If AUS appears, destination is Austin. Return only the JSON, no other text.";

        $result = callGeminiVisionAPI($prompt, $filePath);
        
        if ($result && $result['success']) {
            $content = $result['content'];
            
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
            
            if ($tripDetails && is_array($tripDetails)) {
                // Create proper trip name from destination
                $tripName = $tripDetails['trip_name'] ?? 'Trip';
                if (isset($tripDetails['destination'])) {
                    $tripName = explode(',', $tripDetails['destination'])[0];
                    $tripName = trim($tripName);
                }
                
                return [
                    'destination' => $tripDetails['destination'] ?? $tripName,
                    'trip_name' => $tripName,
                    'start_date' => $tripDetails['start_date'] ?? $tripDetails['departure_date'] ?? '',
                    'end_date' => $tripDetails['end_date'] ?? $tripDetails['return_date'] ?? '',
                    'notes' => $tripDetails['notes'] ?? 'Travel itinerary'
                ];
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Travel document processing error: " . $e->getMessage());
        return null;
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $tripName = $input['tripName'] ?? '';
    
    if (empty($tripName)) {
        throw new Exception('Trip name is required');
    }
    
    $tripDir = 'data/trips/' . sanitizeName($tripName);
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip directory not found');
    }
    
    $receiptsDir = $tripDir . '/receipts';
    $travelDocsDir = $tripDir . '/travel_documents';
    $expensesPath = $tripDir . '/expenses.json';
    $metadataPath = $tripDir . '/metadata.json';
    
    $expenses = [];
    $tripMetadata = ['name' => $tripName];
    $travelDocumentFound = false;
    
    // Process travel documents first to extract trip details
    if (is_dir($travelDocsDir)) {
        $travelFiles = glob($travelDocsDir . '/*');
        foreach ($travelFiles as $file) {
            if (is_file($file)) {
                $fileName = basename($file);
                $tripDetails = processTravelDocument($file, $fileName);
                
                if ($tripDetails) {
                    $tripMetadata = array_merge($tripMetadata, $tripDetails);
                    $travelDocumentFound = true;
                    break; // Use first travel document found
                }
            }
        }
    }
    
    // Process receipts
    if (is_dir($receiptsDir)) {
        $receiptFiles = glob($receiptsDir . '/*');
        foreach ($receiptFiles as $file) {
            if (is_file($file)) {
                $fileName = basename($file);
                $documentType = detectDocumentType($fileName);
                
                if ($documentType === 'travel_document') {
                    // Move misplaced travel document to correct directory
                    if (!is_dir($travelDocsDir)) {
                        mkdir($travelDocsDir, 0755, true);
                    }
                    $newPath = $travelDocsDir . '/' . $fileName;
                    rename($file, $newPath);
                    
                    // Process as travel document if we haven't found one yet
                    if (!$travelDocumentFound) {
                        $tripDetails = processTravelDocument($newPath, $fileName);
                        if ($tripDetails) {
                            $tripMetadata = array_merge($tripMetadata, $tripDetails);
                            $travelDocumentFound = true;
                        }
                    }
                } else {
                    // Process as receipt
                    $expenseData = processReceiptFile($file, $fileName);
                    
                    if ($expenseData) {
                        $expense = array_merge($expenseData, [
                            'id' => uniqid(),
                            'source' => 'receipts/' . $fileName,
                            'is_travel_document' => false
                        ]);
                        
                        $expenses[] = $expense;
                    }
                }
            }
        }
    }
    
    // If trip was renamed, handle directory move
    if ($travelDocumentFound && isset($tripMetadata['trip_name']) && $tripMetadata['trip_name'] !== $tripName) {
        $newTripName = sanitizeName($tripMetadata['trip_name']);
        $newTripDir = 'data/trips/' . $newTripName;
        
        if (!is_dir($newTripDir) && $newTripName !== $tripName) {
            rename($tripDir, $newTripDir);
            $tripDir = $newTripDir;
            $expensesPath = $tripDir . '/expenses.json';
            $metadataPath = $tripDir . '/metadata.json';
            $tripMetadata['name'] = $newTripName;
        }
    }
    
    // Save expenses and metadata
    file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT));
    file_put_contents($metadataPath, json_encode($tripMetadata, JSON_PRETTY_PRINT));
    
    echo json_encode([
        'success' => true,
        'tripName' => $tripMetadata['name'],
        'expenseCount' => count($expenses),
        'travelDocumentFound' => $travelDocumentFound,
        'tripDetails' => $tripMetadata
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>