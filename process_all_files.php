<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'gemini_api.php';

function sanitizeName($name) {
    // For file system compatibility, replace spaces with underscores but keep user display name separate
    $name = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $name);
    $name = preg_replace('/\s+/', '_', $name); // Replace spaces with underscores for file system
    $name = preg_replace('/_+/', '_', $name); // Normalize multiple underscores
    return trim($name, '_') ?: 'untitled';
}

function extractDestinationFromTransportation($note, $merchant) {
    // Common patterns for flight destinations
    $patterns = [
        '/to\s+([A-Z]{3})\s*[\)\.]/',  // "to AUS)"
        '/to\s+([A-Za-z\s]+)\s*\(([A-Z]{3})\)/',  // "to Austin (AUS)"
        '/\s+to\s+([A-Za-z\s,]+?)[\.\,\;]/',  // " to Austin, TX."
        '/destination:?\s*([A-Za-z\s,]+)/i',  // "Destination: Austin"
        '/arriving\s+in\s+([A-Za-z\s,]+)/i',  // "arriving in Austin"
    ];
    
    $text = $note . ' ' . $merchant;
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $destination = trim($matches[1]);
            
            // Map airport codes to cities
            $airportCodes = [
                'AUS' => 'Austin, TX',
                'DFW' => 'Dallas, TX',
                'IAH' => 'Houston, TX',
                'LAX' => 'Los Angeles, CA',
                'JFK' => 'New York, NY',
                'ORD' => 'Chicago, IL',
                'ATL' => 'Atlanta, GA',
                'MIA' => 'Miami, FL',
                'SEA' => 'Seattle, WA',
                'SFO' => 'San Francisco, CA',
                'LAS' => 'Las Vegas, NV',
                'PHX' => 'Phoenix, AZ',
                'DEN' => 'Denver, CO',
                'MSP' => 'Minneapolis, MN',
                'DTW' => 'Detroit, MI',
                'BOS' => 'Boston, MA',
                'PHL' => 'Philadelphia, PA',
                'LGA' => 'New York, NY',
                'BWI' => 'Baltimore, MD',
                'IAD' => 'Washington, DC',
                'CLT' => 'Charlotte, NC',
                'MCO' => 'Orlando, FL',
                'FLL' => 'Fort Lauderdale, FL',
                'SAN' => 'San Diego, CA',
                'TPA' => 'Tampa, FL',
                'PDX' => 'Portland, OR',
                'MSY' => 'New Orleans, LA',
                'BNA' => 'Nashville, TN',
                'RDU' => 'Raleigh, NC',
                'CLE' => 'Cleveland, OH',
                'PIT' => 'Pittsburgh, PA',
                'ILM' => 'Wilmington, NC'
            ];
            
            if (isset($airportCodes[$destination])) {
                return $airportCodes[$destination];
            } elseif (strlen($destination) > 2) {
                return $destination;
            }
        }
    }
    
    return null;
}

function extractCityFromDestination($destination) {
    // Extract just the city name from "City, State" format
    $parts = explode(',', $destination);
    return trim($parts[0]);
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
    \"note\": \"description\",
    \"is_hotel_stay\": false,
    \"daily_breakdown\": []
}

If HOTEL RECEIPT specifically, also include daily breakdown:
{
    \"type\": \"receipt\",
    \"date\": \"YYYY-MM-DD\",
    \"merchant\": \"hotel name\",
    \"amount\": 0.00,
    \"tax_amount\": 0.00,
    \"category\": \"Lodging\",
    \"note\": \"Hotel stay details\",
    \"is_hotel_stay\": true,
    \"daily_breakdown\": [
        {
            \"date\": \"YYYY-MM-DD\",
            \"room_rate\": 0.00,
            \"tax_rate\": 0.00,
            \"tax_percentage\": \"0.0%\",
            \"daily_total\": 0.00
        }
    ]
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
    
    // Don't sanitize temp trip names as they need to match exact directory names
    if (!preg_match('/^temp_\d+$/', $tripName)) {
        $tripName = sanitizeName($tripName);
    }
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
                    'excluded' => false,
                    'is_hotel_stay' => $analysis['is_hotel_stay'] ?? false,
                    'daily_breakdown' => $analysis['daily_breakdown'] ?? []
                ];
                
                $expenses[] = $expense;
                
                // Extract trip metadata from transportation receipts (flights, trains, etc.)
                if ($expense['category'] === 'Transportation' && !isset($tripMetadata['destination'])) {
                    $destination = extractDestinationFromTransportation($expense['note'], $expense['merchant']);
                    if ($destination) {
                        $tripMetadata['destination'] = $destination;
                        $tripMetadata['trip_name'] = extractCityFromDestination($destination);
                    }
                }
                
                // Track date range for all expenses
                if (!isset($tripMetadata['start_date']) || $expense['date'] < $tripMetadata['start_date']) {
                    $tripMetadata['start_date'] = $expense['date'];
                }
                if (!isset($tripMetadata['end_date']) || $expense['date'] > $tripMetadata['end_date']) {
                    $tripMetadata['end_date'] = $expense['date'];
                }
                
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
    
    // Handle trip renaming if we extracted destination/date info
    $finalTripName = $tripName;
    
    // Create meaningful trip name for temp trips
    if (preg_match('/^temp_\d+$/', $tripName)) {
        $cityName = 'Trip';
        $datePrefix = '';
        
        // Extract city name from destination if available
        if (isset($tripMetadata['destination'])) {
            $parts = explode(',', $tripMetadata['destination']);
            $cityName = trim($parts[0]);
        } elseif (isset($tripMetadata['trip_name'])) {
            $cityName = $tripMetadata['trip_name'];
        }
        
        // Add date suffix if start date is available
        $dateSuffix = '';
        if (isset($tripMetadata['start_date'])) {
            $dateSuffix = ' ' . date('F Y', strtotime($tripMetadata['start_date']));
        }
        
        // Create clean trip name with destination and date - keep user-friendly format
        $displayTripName = $cityName . $dateSuffix;
        $fileSystemTripName = sanitizeName($displayTripName);
        
        // Handle conflicts by adding counter
        $counter = 1;
        $originalFileSystemName = $fileSystemTripName;
        while (is_dir("data/trips/" . $fileSystemTripName)) {
            $counter++;
            $fileSystemTripName = $originalFileSystemName . "_" . $counter;
            $displayTripName = $cityName . $dateSuffix . " " . $counter;
        }
        
        $newTripDir = "data/trips/" . $fileSystemTripName;
        
        if (rename($tripDir, $newTripDir)) {
            $finalTripName = $displayTripName; // Return user-friendly name
            $tripMetadata['name'] = $displayTripName; // Store user-friendly name
            $tripMetadata['filesystem_name'] = $fileSystemTripName; // Store filesystem name
            
            // Update metadata in new location
            file_put_contents($newTripDir . '/metadata.json', json_encode($tripMetadata, JSON_PRETTY_PRINT));
            
            // Update expenses and metadata paths
            $expensesPath = $newTripDir . '/expenses.json';
            file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT));
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