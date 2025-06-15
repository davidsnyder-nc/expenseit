<?php
// Test PDF extraction functionality
header('Content-Type: text/plain');

// Check if we have any uploaded travel documents
$testDirs = [
    'data/trips/temp_travel_docs/receipts',
    'data/trips/temp_travel_docs/travel_documents'
];

echo "Testing PDF extraction functionality...\n\n";

foreach ($testDirs as $dir) {
    if (is_dir($dir)) {
        echo "Checking directory: $dir\n";
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $filename = basename($file);
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                echo "Found file: $filename (.$extension)\n";
                
                if ($extension === 'pdf') {
                    echo "Testing PDF extraction on: $filename\n";
                    
                    // Test our extraction function
                    $content = extractTextFromPDF($file);
                    if ($content) {
                        echo "Successfully extracted " . strlen($content) . " characters\n";
                        echo "First 200 characters: " . substr($content, 0, 200) . "...\n";
                    } else {
                        echo "Failed to extract text from PDF\n";
                    }
                    echo "\n";
                }
            }
        }
    }
}

// PDF extraction function (copy from extract_trip_details.php)
function extractTextFromPDF($filePath) {
    // Method 1: Try pdftotext command line tool
    if (function_exists('shell_exec')) {
        $output = shell_exec("pdftotext " . escapeshellarg($filePath) . " - 2>/dev/null");
        if ($output && trim($output)) {
            return trim($output);
        }
    }
    
    // Method 2: Try using PHP's file_get_contents to read raw PDF content
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
            if (strlen($readableText) > 50) {
                $text = $readableText;
            }
        }
        
        return trim($text);
    }
    
    return false;
}

echo "Test complete.\n";
?>