<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Debug what files are actually in the trip directory
$tripName = $_GET['trip'] ?? '';
if (empty($tripName)) {
    echo json_encode(['error' => 'Trip name required']);
    exit;
}

$tripDir = "data/trips/" . $tripName;
$result = [
    'tripName' => $tripName,
    'tripDir' => $tripDir,
    'exists' => is_dir($tripDir),
    'files' => [],
    'receipts' => [],
    'travelDocs' => []
];

if (is_dir($tripDir)) {
    // Get all files in trip directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tripDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = str_replace($tripDir . '/', '', $file->getPathname());
            $result['files'][] = [
                'path' => $relativePath,
                'fullPath' => $file->getPathname(),
                'size' => $file->getSize(),
                'extension' => $file->getExtension(),
                'basename' => $file->getBasename()
            ];
        }
    }
    
    // Check receipts directory specifically
    $receiptsDir = $tripDir . '/receipts';
    if (is_dir($receiptsDir)) {
        $receiptFiles = array_diff(scandir($receiptsDir), ['.', '..']);
        foreach ($receiptFiles as $file) {
            if (is_file($receiptsDir . '/' . $file)) {
                $result['receipts'][] = $file;
            }
        }
    }
    
    // Check travel documents directory
    $travelDir = $tripDir . '/travel_documents';
    if (is_dir($travelDir)) {
        $travelFiles = array_diff(scandir($travelDir), ['.', '..']);
        foreach ($travelFiles as $file) {
            if (is_file($travelDir . '/' . $file)) {
                $result['travelDocs'][] = $file;
            }
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>