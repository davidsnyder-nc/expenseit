<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'gemini_api.php';

// Load environment variables immediately
if (function_exists('loadEnvFile')) {
    loadEnvFile();
}

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
                // US Major Cities
                'AUS' => 'Austin, TX', 'DFW' => 'Dallas, TX', 'IAH' => 'Houston, TX',
                'LAX' => 'Los Angeles, CA', 'JFK' => 'New York, NY', 'LGA' => 'New York, NY',
                'ORD' => 'Chicago, IL', 'ATL' => 'Atlanta, GA', 'MIA' => 'Miami, FL',
                'SEA' => 'Seattle, WA', 'SFO' => 'San Francisco, CA', 'LAS' => 'Las Vegas, NV',
                'PHX' => 'Phoenix, AZ', 'DEN' => 'Denver, CO', 'MSP' => 'Minneapolis, MN',
                'DTW' => 'Detroit, MI', 'BOS' => 'Boston, MA', 'PHL' => 'Philadelphia, PA',
                'BWI' => 'Baltimore, MD', 'DCA' => 'Washington, DC', 'IAD' => 'Washington, DC',
                'CLT' => 'Charlotte, NC', 'MCO' => 'Orlando, FL', 'FLL' => 'Fort Lauderdale, FL',
                'SAN' => 'San Diego, CA', 'TPA' => 'Tampa, FL', 'PDX' => 'Portland, OR',
                'MSY' => 'New Orleans, LA', 'BNA' => 'Nashville, TN', 'RDU' => 'Raleigh, NC',
                'CLE' => 'Cleveland, OH', 'PIT' => 'Pittsburgh, PA', 'SLC' => 'Salt Lake City, UT',
                'SAT' => 'San Antonio, TX', 'MEM' => 'Memphis, TN', 'STL' => 'St. Louis, MO',
                
                // Canada
                'YYZ' => 'Toronto, ON', 'YVR' => 'Vancouver, BC', 'YUL' => 'Montreal, QC',
                'YYC' => 'Calgary, AB', 'YEG' => 'Edmonton, AB', 'YOW' => 'Ottawa, ON',
                'YHZ' => 'Halifax, NS', 'YWG' => 'Winnipeg, MB',
                
                // Europe
                'LHR' => 'London, UK', 'LGW' => 'London, UK', 'CDG' => 'Paris, France',
                'ORY' => 'Paris, France', 'FRA' => 'Frankfurt, Germany', 'MUC' => 'Munich, Germany',
                'BER' => 'Berlin, Germany', 'AMS' => 'Amsterdam, Netherlands', 'FCO' => 'Rome, Italy',
                'MAD' => 'Madrid, Spain', 'BCN' => 'Barcelona, Spain', 'ZUR' => 'Zurich, Switzerland',
                'VIE' => 'Vienna, Austria', 'CPH' => 'Copenhagen, Denmark', 'ARN' => 'Stockholm, Sweden',
                'OSL' => 'Oslo, Norway', 'HEL' => 'Helsinki, Finland', 'DUB' => 'Dublin, Ireland',
                'BRU' => 'Brussels, Belgium', 'LIS' => 'Lisbon, Portugal', 'ATH' => 'Athens, Greece',
                'IST' => 'Istanbul, Turkey', 'SVO' => 'Moscow, Russia',
                
                // Asia Pacific
                'NRT' => 'Tokyo, Japan', 'HND' => 'Tokyo, Japan', 'KIX' => 'Osaka, Japan',
                'ICN' => 'Seoul, South Korea', 'PEK' => 'Beijing, China', 'PVG' => 'Shanghai, China',
                'HKG' => 'Hong Kong', 'SIN' => 'Singapore', 'BKK' => 'Bangkok, Thailand',
                'KUL' => 'Kuala Lumpur, Malaysia', 'CGK' => 'Jakarta, Indonesia', 'MNL' => 'Manila, Philippines',
                'SYD' => 'Sydney, Australia', 'MEL' => 'Melbourne, Australia', 'BNE' => 'Brisbane, Australia',
                'PER' => 'Perth, Australia', 'AKL' => 'Auckland, New Zealand', 'DEL' => 'Delhi, India',
                'BOM' => 'Mumbai, India', 'BLR' => 'Bangalore, India',
                
                // Middle East & Africa
                'DXB' => 'Dubai, UAE', 'DOH' => 'Doha, Qatar', 'CAI' => 'Cairo, Egypt',
                'JNB' => 'Johannesburg, South Africa', 'CPT' => 'Cape Town, South Africa',
                
                // South America
                'GRU' => 'São Paulo, Brazil', 'GIG' => 'Rio de Janeiro, Brazil', 'SCL' => 'Santiago, Chile',
                'LIM' => 'Lima, Peru', 'BOG' => 'Bogotá, Colombia', 'EZE' => 'Buenos Aires, Argentina'
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
    // Extract city name from various location formats
    if (empty($destination)) {
        return 'Trip';
    }
    
    // Handle different location formats:
    // "City, State" -> "City"
    // "City, Country" -> "City"
    // "Airport Code (City)" -> "City"
    // "Downtown City" -> "City"
    // "Full Address with City, State" -> "City"
    
    // Clean up the destination string
    $destination = trim($destination);
    
    // Extract from airport format: "LAX (Los Angeles)" or "JFK (New York)"
    if (preg_match('/\([^)]+\s+([^,)]+)(?:,\s*[^)]+)?\)/', $destination, $matches)) {
        return trim($matches[1]);
    }
    
    // Extract from "City, State/Country" format
    if (strpos($destination, ',') !== false) {
        $parts = explode(',', $destination);
        $cityPart = trim($parts[0]);
        
        // Remove common prefixes like "Downtown", "North", "South", etc.
        $cityPart = preg_replace('/^(Downtown|North|South|East|West|Central)\s+/i', '', $cityPart);
        
        return $cityPart;
    }
    
    // For single words or phrases, clean up common location prefixes
    $destination = preg_replace('/^(Downtown|North|South|East|West|Central)\s+/i', '', $destination);
    
    // Extract city from airport codes if they contain city names
    if (preg_match('/([A-Za-z\s]+)\s+Airport/i', $destination, $matches)) {
        return trim($matches[1]);
    }
    
    // Return the cleaned destination as city name
    return $destination;
}

// Function removed - now using the one from gemini_api.php

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
                    'location' => $analysis['location'] ?? '',
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
                
                // Collect location data for destination detection
                if (!empty($expense['location'])) {
                    if (!isset($tripMetadata['detected_locations'])) {
                        $tripMetadata['detected_locations'] = [];
                    }
                    $tripMetadata['detected_locations'][] = $expense['location'];
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
    
    // Smart destination detection from collected locations
    if (!isset($tripMetadata['destination']) && isset($tripMetadata['detected_locations']) && !empty($tripMetadata['detected_locations'])) {
        $locationCounts = array_count_values($tripMetadata['detected_locations']);
        arsort($locationCounts); // Sort by frequency, most common first
        
        // Use the most frequently mentioned location as destination
        $mostCommonLocation = array_key_first($locationCounts);
        if ($mostCommonLocation) {
            $tripMetadata['destination'] = $mostCommonLocation;
            $tripMetadata['trip_name'] = extractCityFromDestination($mostCommonLocation);
        }
    }
    
    // Clean up temporary location data
    if (isset($tripMetadata['detected_locations'])) {
        unset($tripMetadata['detected_locations']);
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