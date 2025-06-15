<?php
require_once 'extract_destination.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

function loadAllTrips() {
    $tripsDir = 'data/trips';
    
    if (!is_dir($tripsDir)) {
        return ['success' => true, 'trips' => []];
    }
    
    $trips = [];
    $tripDirs = glob($tripsDir . '/*', GLOB_ONLYDIR);
    
    foreach ($tripDirs as $tripDir) {
        $tripName = basename($tripDir);
        if ($tripName === 'temp') continue;
        
        try {
            $metadataPath = $tripDir . '/metadata.json';
            $expensesPath = $tripDir . '/expenses.json';
            
            if (!file_exists($metadataPath)) continue;
            
            $metadata = json_decode(file_get_contents($metadataPath), true);
            if (!$metadata) continue;
            
            // Extract destination if not set
            if (empty($metadata['destination'])) {
                $metadata['destination'] = extractDestination($metadata['name'] ?? $tripName);
                file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
            }
            
            $expenses = [];
            if (file_exists($expensesPath)) {
                $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
            }
            
            $total = 0;
            foreach ($expenses as $expense) {
                $total += floatval($expense['amount'] ?? 0);
            }
            
            $trips[] = [
                'name' => $tripName,
                'metadata' => $metadata,
                'expenseCount' => count($expenses),
                'total' => number_format($total, 2)
            ];
            
        } catch (Exception $e) {
            error_log("Error loading trip $tripName: " . $e->getMessage());
        }
    }
    
    usort($trips, function($a, $b) {
        $dateA = $a['metadata']['start_date'] ?? '';
        $dateB = $b['metadata']['start_date'] ?? '';
        return strtotime($dateB) <=> strtotime($dateA);
    });
    
    return ['success' => true, 'trips' => $trips];
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'trips') {
        echo json_encode(loadAllTrips());
    } elseif ($action === 'archived_trips') {
        echo json_encode(['success' => true, 'trips' => []]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>