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

function detectTravelDocument($fileName) {
    $fileName = strtolower($fileName);
    $travelKeywords = [
        'itinerary', 'itenary', 'itenery', 'confirmation', 'boarding', 'flight', 
        'airline', 'travel', 'reservation', 'ticket', 'eticket', 'hotel', 
        'booking', 'gmail', 'fw_', 'trip'
    ];
    
    foreach ($travelKeywords as $keyword) {
        if (strpos($fileName, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function extractTripDetailsFromDocument($filePath) {
    try {
        $prompt = "Analyze this travel document and extract trip information. Look for:
- Destination city (AUS = Austin TX, LAX = Los Angeles, JFK/LGA = New York, etc.)
- Travel dates (departure and return dates)
- Trip details

Extract the actual destination city name, not airport codes. If you see Austin, Texas or AUS airport code, the destination is \"Austin, TX\".

Return ONLY a valid JSON object:
{
    \"destination\": \"Austin, TX\",
    \"trip_name\": \"Austin\",
    \"start_date\": \"2025-06-12\",
    \"end_date\": \"2025-06-16\",
    \"notes\": \"Trip details\"
}";

        $result = callGeminiVisionAPI($prompt, $filePath);
        
        if ($result && $result['success']) {
            $content = $result['content'];
            
            // Try to extract JSON
            $tripDetails = json_decode($content, true);
            
            if (!$tripDetails || json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $tripDetails = json_decode($matches[0], true);
                }
            }
            
            if ($tripDetails && is_array($tripDetails)) {
                return $tripDetails;
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Travel document processing error: " . $e->getMessage());
        return null;
    }
}

function processReceiptWithGemini($filePath) {
    try {
        $prompt = "Analyze this receipt and extract:
{
  \"date\": \"YYYY-MM-DD\",
  \"merchant\": \"merchant name\",
  \"amount\": 0.00,
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
                    'category' => $expense['category'] ?? 'Other',
                    'note' => $expense['note'] ?? 'Receipt'
                ];
            }
        }
        
        return [
            'date' => date('Y-m-d'),
            'merchant' => 'Unknown',
            'amount' => 0,
            'category' => 'Other',
            'note' => 'Failed to process'
        ];
        
    } catch (Exception $e) {
        error_log("Receipt processing error: " . $e->getMessage());
        return null;
    }
}

try {
    if (!isset($_POST['tripName'])) {
        throw new Exception('Trip name is required');
    }
    
    $tripName = sanitizeName($_POST['tripName']);
    $tripDir = "data/trips/" . $tripName;
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip directory not found: ' . $tripDir);
    }
    
    $receiptsDir = $tripDir . '/receipts';
    $travelDocsDir = $tripDir . '/travel_documents';
    
    $expenses = [];
    $tripMetadata = ['name' => $tripName];
    $processedFiles = [];
    $errors = [];
    
    // Process all files in receipts directory
    if (is_dir($receiptsDir)) {
        $files = array_diff(scandir($receiptsDir), ['.', '..']);
        
        foreach ($files as $fileName) {
            $filePath = $receiptsDir . '/' . $fileName;
            
            if (!is_file($filePath)) {
                continue;
            }
            
            try {
                if (detectTravelDocument($fileName)) {
                    // Move to travel documents folder
                    if (!is_dir($travelDocsDir)) {
                        mkdir($travelDocsDir, 0755, true);
                    }
                    
                    $newPath = $travelDocsDir . '/' . $fileName;
                    rename($filePath, $newPath);
                    
                    // Extract trip details
                    $tripDetails = extractTripDetailsFromDocument($newPath);
                    if ($tripDetails) {
                        $tripMetadata = array_merge($tripMetadata, $tripDetails);
                        
                        // If we got a better trip name, prepare for directory rename
                        if (isset($tripDetails['trip_name']) && $tripDetails['trip_name'] !== $tripName) {
                            $tripMetadata['should_rename_to'] = sanitizeName($tripDetails['trip_name']);
                        }
                    }
                    
                    $processedFiles[] = ['file' => $fileName, 'type' => 'travel_document', 'status' => 'moved'];
                } else {
                    // Process as receipt
                    $expenseData = processReceiptWithGemini($filePath);
                    
                    if ($expenseData) {
                        $expense = array_merge($expenseData, [
                            'id' => uniqid(),
                            'source' => 'receipts/' . $fileName,
                            'is_travel_document' => false
                        ]);
                        
                        $expenses[] = $expense;
                        $processedFiles[] = ['file' => $fileName, 'type' => 'receipt', 'status' => 'processed'];
                    }
                }
            } catch (Exception $e) {
                $errors[] = ['file' => $fileName, 'error' => $e->getMessage()];
            }
        }
    }
    
    // Save expenses and metadata
    $expensesPath = $tripDir . '/expenses.json';
    $metadataPath = $tripDir . '/metadata.json';
    
    file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT));
    file_put_contents($metadataPath, json_encode($tripMetadata, JSON_PRETTY_PRINT));
    
    // Handle trip renaming if needed
    $finalTripName = $tripName;
    if (isset($tripMetadata['should_rename_to']) && $tripMetadata['should_rename_to'] !== $tripName) {
        $newTripName = $tripMetadata['should_rename_to'];
        $newTripDir = "data/trips/" . $newTripName;
        
        if (!is_dir($newTripDir)) {
            if (rename($tripDir, $newTripDir)) {
                $finalTripName = $newTripName;
                $tripMetadata['name'] = $newTripName;
                unset($tripMetadata['should_rename_to']);
                
                // Update metadata in new location
                file_put_contents($newTripDir . '/metadata.json', json_encode($tripMetadata, JSON_PRETTY_PRINT));
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'tripName' => $finalTripName,
        'expenseCount' => count($expenses),
        'processedFiles' => $processedFiles,
        'errors' => $errors,
        'tripMetadata' => $tripMetadata
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>