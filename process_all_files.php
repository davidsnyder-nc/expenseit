<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'gemini_api.php';

function sanitizeName($name) {
    $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
    $name = str_replace(' ', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_') ?: 'untitled';
}

function analyzeFileWithGemini($filePath, $fileName) {
    try {
        // First, determine what type of document this is
        $analysisPrompt = "Analyze this document and determine:
1. Is this a travel document (itinerary, flight confirmation, hotel booking, etc.) or a receipt/expense?
2. Extract key information based on document type.

If TRAVEL DOCUMENT, return:
{
    \"type\": \"travel_document\",
    \"destination\": \"City, State\",
    \"trip_name\": \"City\",
    \"start_date\": \"YYYY-MM-DD\",
    \"end_date\": \"YYYY-MM-DD\",
    \"notes\": \"travel details\"
}

If RECEIPT/EXPENSE, return:
{
    \"type\": \"receipt\",
    \"date\": \"YYYY-MM-DD\",
    \"merchant\": \"merchant name\",
    \"amount\": 0.00,
    \"tax_amount\": 0.00,
    \"category\": \"Meals|Transportation|Lodging|Entertainment|Groceries|Shopping|Gas|Other\",
    \"note\": \"description\"
}

Analyze the content carefully and return only valid JSON.";

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
            'category' => 'Other',
            'note' => 'Failed to process with AI',
            'gemini_processed' => false
        ];
        
    } catch (Exception $e) {
        error_log("Gemini analysis error for $fileName: " . $e->getMessage());
        return [
            'type' => 'receipt',
            'filename' => $fileName,
            'error' => $e->getMessage(),
            'gemini_processed' => false
        ];
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $tripName = $input['tripName'] ?? '';
    
    if (empty($tripName)) {
        throw new Exception('Trip name is required');
    }
    
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip directory not found: ' . $tripDir);
    }
    
    $receiptsDir = $tripDir . '/receipts';
    $travelDocsDir = $tripDir . '/travel_documents';
    
    $expenses = [];
    $travelDocuments = [];
    $tripMetadata = ['name' => $tripName];
    $processedFiles = [];
    $geminiResults = [];
    
    // Process ALL files in receipts directory with Gemini
    if (is_dir($receiptsDir)) {
        $files = array_diff(scandir($receiptsDir), ['.', '..']);
        
        foreach ($files as $fileName) {
            $filePath = $receiptsDir . '/' . $fileName;
            
            if (!is_file($filePath)) {
                continue;
            }
            
            // EVERY file gets analyzed by Gemini
            $analysis = analyzeFileWithGemini($filePath, $fileName);
            $geminiResults[] = $analysis;
            
            if ($analysis['type'] === 'travel_document') {
                // Move to travel documents folder
                if (!is_dir($travelDocsDir)) {
                    mkdir($travelDocsDir, 0755, true);
                }
                
                $newPath = $travelDocsDir . '/' . $fileName;
                if (rename($filePath, $newPath)) {
                    $travelDocuments[] = $analysis;
                    
                    // Update trip metadata with travel document info
                    if (isset($analysis['destination'])) {
                        $tripMetadata['destination'] = $analysis['destination'];
                    }
                    if (isset($analysis['trip_name'])) {
                        $tripMetadata['trip_name'] = $analysis['trip_name'];
                    }
                    if (isset($analysis['start_date'])) {
                        $tripMetadata['start_date'] = $analysis['start_date'];
                    }
                    if (isset($analysis['end_date'])) {
                        $tripMetadata['end_date'] = $analysis['end_date'];
                    }
                    if (isset($analysis['notes'])) {
                        $tripMetadata['notes'] = $analysis['notes'];
                    }
                    
                    $processedFiles[] = [
                        'file' => $fileName,
                        'type' => 'travel_document',
                        'status' => 'moved_and_processed',
                        'gemini_analyzed' => true
                    ];
                }
            } else {
                // Process as expense
                $expense = [
                    'id' => uniqid(),
                    'date' => $analysis['date'] ?? date('Y-m-d'),
                    'merchant' => $analysis['merchant'] ?? 'Unknown',
                    'amount' => floatval($analysis['amount'] ?? 0),
                    'category' => $analysis['category'] ?? 'Other',
                    'note' => $analysis['note'] ?? 'Receipt',
                    'source' => 'receipts/' . $fileName,
                    'is_travel_document' => false,
                    'tax_amount' => floatval($analysis['tax_amount'] ?? 0),
                    'gemini_processed' => $analysis['gemini_processed'] ?? false,
                    'excluded' => false
                ];
                
                $expenses[] = $expense;
                $processedFiles[] = [
                    'file' => $fileName,
                    'type' => 'receipt',
                    'status' => 'processed_as_expense',
                    'gemini_analyzed' => true
                ];
            }
        }
    }
    
    // Process existing travel documents too
    if (is_dir($travelDocsDir)) {
        $files = array_diff(scandir($travelDocsDir), ['.', '..']);
        
        foreach ($files as $fileName) {
            $filePath = $travelDocsDir . '/' . $fileName;
            
            if (!is_file($filePath)) {
                continue;
            }
            
            // Analyze travel documents with Gemini
            $analysis = analyzeFileWithGemini($filePath, $fileName);
            $geminiResults[] = $analysis;
            $travelDocuments[] = $analysis;
            
            // Update trip metadata
            if (isset($analysis['destination'])) {
                $tripMetadata['destination'] = $analysis['destination'];
            }
            if (isset($analysis['trip_name'])) {
                $tripMetadata['trip_name'] = $analysis['trip_name'];
            }
            if (isset($analysis['start_date'])) {
                $tripMetadata['start_date'] = $analysis['start_date'];
            }
            if (isset($analysis['end_date'])) {
                $tripMetadata['end_date'] = $analysis['end_date'];
            }
            
            // Include travel documents as expenses by default (user can exclude later)
            if (isset($analysis['amount']) && floatval($analysis['amount']) > 0) {
                $expense = [
                    'id' => uniqid(),
                    'date' => $analysis['date'] ?? date('Y-m-d'),
                    'merchant' => $analysis['merchant'] ?? 'Travel Service',
                    'amount' => floatval($analysis['amount']),
                    'category' => $analysis['category'] ?? 'Travel',
                    'note' => $analysis['note'] ?? 'Travel Document',
                    'source' => 'travel_documents/' . $fileName,
                    'is_travel_document' => true,
                    'tax_amount' => floatval($analysis['tax_amount'] ?? 0),
                    'gemini_processed' => $analysis['gemini_processed'] ?? false,
                    'excluded' => false // Can be toggled by user
                ];
                
                $expenses[] = $expense;
            }
        }
    }
    
    // Save all data
    $expensesPath = $tripDir . '/expenses.json';
    $metadataPath = $tripDir . '/metadata.json';
    
    file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT));
    file_put_contents($metadataPath, json_encode($tripMetadata, JSON_PRETTY_PRINT));
    
    // Handle trip renaming if we extracted a better name
    $finalTripName = $tripName;
    if (isset($tripMetadata['trip_name']) && $tripMetadata['trip_name'] !== $tripName) {
        $baseName = $tripMetadata['trip_name'];
        
        // Create user-friendly trip name based on destination
        if (preg_match('/^temp_\d+$/', $tripName)) {
            // Extract city name from destination if available
            $cityName = $baseName;
            if (isset($tripMetadata['destination'])) {
                // Extract city from "City, State" format
                $parts = explode(',', $tripMetadata['destination']);
                $cityName = trim($parts[0]);
            }
            
            // Create clean trip name
            $newTripName = sanitizeName($cityName . "_Trip");
            
            // Handle conflicts by adding number
            $counter = 1;
            $originalName = $newTripName;
            while (is_dir("data/trips/" . $newTripName)) {
                $counter++;
                $newTripName = sanitizeName($cityName . "_Trip_" . $counter);
            }
            
            $newTripDir = "data/trips/" . $newTripName;
            
            if (rename($tripDir, $newTripDir)) {
                $finalTripName = $newTripName;
                $tripMetadata['name'] = $newTripName;
                
                // Update metadata in new location
                file_put_contents($newTripDir . '/metadata.json', json_encode($tripMetadata, JSON_PRETTY_PRINT));
                
                // Update expenses and metadata paths
                $expensesPath = $newTripDir . '/expenses.json';
                file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT));
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'tripName' => $finalTripName,
        'expenseCount' => count($expenses),
        'travelDocumentCount' => count($travelDocuments),
        'processedFiles' => $processedFiles,
        'geminiAnalysis' => $geminiResults,
        'tripMetadata' => $tripMetadata,
        'totalFilesAnalyzed' => count($geminiResults)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>