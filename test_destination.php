<?php
require_once 'extract_destination.php';

// Test the destination extraction for "india"
$tripName = "india";
echo "Testing destination extraction for: '$tripName'\n";

try {
    $destination = extractDestination($tripName);
    echo "Result: '$destination'\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>