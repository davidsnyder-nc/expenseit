<?php
require_once 'gemini_api.php';

function extractDestination($tripName) {
    $prompt = "Extract the destination/location from this trip name: \"$tripName\"

Please return ONLY the destination name (city, state/country if clear) in a simple format. 
If no clear destination can be identified, return \"Unknown\".

Examples:
- \"Austin 2025\" → \"Austin\"
- \"Paris Summer Trip\" → \"Paris\"
- \"NYC Business Travel\" → \"New York City\"
- \"Family Vacation Florida\" → \"Florida\"
- \"Weekend Getaway\" → \"Unknown\"
- \"Conference 2025\" → \"Unknown\"

Trip name: \"$tripName\"
Destination:";

    try {
        $geminiResponse = callGeminiAPI($prompt);
        
        if ($geminiResponse && isset($geminiResponse['candidates'][0]['content']['parts'][0]['text'])) {
            $destination = trim($geminiResponse['candidates'][0]['content']['parts'][0]['text']);
            
            // Clean up the response - remove quotes, extra text
            $destination = preg_replace('/^["\']|["\']$/', '', $destination);
            $destination = preg_replace('/^(destination:|answer:|result:)\s*/i', '', $destination);
            $destination = trim($destination);
            
            // Validate the response
            if (empty($destination) || strlen($destination) > 50 || preg_match('/unknown|not found|cannot identify/i', $destination)) {
                return 'Unknown';
            }
            
            return $destination;
        }
    } catch (Exception $e) {
        error_log("Error extracting destination: " . $e->getMessage());
    }
    
    return 'Unknown';
}

// If called directly for testing
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $testTrips = [
        'Austin 2025',
        'Paris Summer Trip',
        'NYC Business Travel',
        'Family Vacation Florida',
        'Weekend Getaway',
        'Conference 2025',
        'Tokyo Adventure',
        'London Work Trip'
    ];
    
    header('Content-Type: application/json');
    $results = [];
    
    foreach ($testTrips as $trip) {
        $results[$trip] = extractDestination($trip);
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT);
}
?>