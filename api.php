<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function sanitizeName($name) {
    $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
    $name = str_replace(' ', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_') ?: 'untitled';
}

function extractDestinationSimple($tripName) {
    // Simple pattern matching for common destinations
    $patterns = [
        '/\b(toronto|ontario|canada)\b/i' => 'Toronto',
        '/\b(austin|texas|tx)\b/i' => 'Austin',
        '/\b(paris|france)\b/i' => 'Paris',
        '/\b(nyc|new york|manhattan)\b/i' => 'New York City',
        '/\b(london|uk|england)\b/i' => 'London',
        '/\b(tokyo|japan)\b/i' => 'Tokyo',
        '/\b(la|los angeles|california|ca)\b/i' => 'Los Angeles',
        '/\b(chicago|illinois|il)\b/i' => 'Chicago',
        '/\b(miami|florida|fl)\b/i' => 'Miami',
        '/\b(seattle|washington|wa)\b/i' => 'Seattle',
        '/\b(boston|massachusetts|ma)\b/i' => 'Boston',
        '/\b(vancouver|bc|british columbia)\b/i' => 'Vancouver',
        '/\b(montreal|quebec)\b/i' => 'Montreal'
    ];
    
    foreach ($patterns as $pattern => $destination) {
        if (preg_match($pattern, $tripName)) {
            return $destination;
        }
    }
    
    return 'Unknown';
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if ($action === 'trips') {
        $tripsDir = 'data/trips';
        $trips = [];
        
        if (is_dir($tripsDir)) {
            $tripDirs = glob($tripsDir . '/*', GLOB_ONLYDIR);
            
            foreach ($tripDirs as $tripDir) {
                $tripName = basename($tripDir);
                if ($tripName === 'temp') continue;
                
                $metadataPath = $tripDir . '/metadata.json';
                $expensesPath = $tripDir . '/expenses.json';
                
                if (!file_exists($metadataPath)) continue;
                
                $metadata = json_decode(file_get_contents($metadataPath), true);
                if (!$metadata) continue;
                
                // Add destination if not present
                if (empty($metadata['destination'])) {
                    $metadata['destination'] = extractDestinationSimple($metadata['name'] ?? $tripName);
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
            }
            
            usort($trips, function($a, $b) {
                $dateA = $a['metadata']['start_date'] ?? '';
                $dateB = $b['metadata']['start_date'] ?? '';
                return strtotime($dateB) <=> strtotime($dateA);
            });
        }
        
        echo json_encode(['success' => true, 'trips' => $trips]);
        
    } elseif ($action === 'archived_trips') {
        echo json_encode(['success' => true, 'trips' => []]);
        
    } elseif ($action === 'trip') {
        $tripName = $_GET['name'] ?? '';
        if (empty($tripName)) {
            echo json_encode(['success' => false, 'error' => 'Trip name is required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $tripDir = 'data/trips/' . $tripName;
        
        if (!is_dir($tripDir)) {
            echo json_encode(['success' => false, 'error' => 'Trip not found']);
            exit;
        }
        
        $metadataPath = $tripDir . '/metadata.json';
        $expensesPath = $tripDir . '/expenses.json';
        
        if (!file_exists($metadataPath)) {
            echo json_encode(['success' => false, 'error' => 'Trip metadata not found']);
            exit;
        }
        
        $metadata = json_decode(file_get_contents($metadataPath), true);
        if (!$metadata) {
            echo json_encode(['success' => false, 'error' => 'Invalid trip metadata']);
            exit;
        }
        
        $expenses = [];
        if (file_exists($expensesPath)) {
            $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
        }
        
        // Calculate statistics
        $total = 0;
        $categories = [];
        foreach ($expenses as $expense) {
            $amount = floatval($expense['amount'] ?? 0);
            $total += $amount;
            $category = $expense['category'] ?? 'Other';
            if (!isset($categories[$category])) {
                $categories[$category] = 0;
            }
            $categories[$category] += $amount;
        }
        
        // Sort expenses by date (most recent first)
        usort($expenses, function($a, $b) {
            $dateA = $a['date'] ?? '';
            $dateB = $b['date'] ?? '';
            return strtotime($dateB) <=> strtotime($dateA);
        });
        
        $reportPath = $tripDir . '/report.pdf';
        $hasReport = file_exists($reportPath);
        
        echo json_encode([
            'success' => true,
            'trip' => [
                'name' => $tripName,
                'metadata' => $metadata,
                'expenses' => $expenses,
                'statistics' => [
                    'total' => $total,
                    'expenseCount' => count($expenses),
                    'categories' => $categories,
                    'hasReport' => $hasReport
                ]
            ]
        ]);
        
    } elseif ($action === 'receipts') {
        $tripName = $_GET['name'] ?? '';
        if (empty($tripName)) {
            echo json_encode(['success' => false, 'error' => 'Trip name is required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $receiptsDir = 'data/trips/' . $tripName . '/receipts';
        $receipts = [];
        
        if (is_dir($receiptsDir)) {
            $receiptFiles = glob($receiptsDir . '/*');
            foreach ($receiptFiles as $file) {
                if (is_file($file)) {
                    $receipts[] = [
                        'name' => basename($file),
                        'path' => $file,
                        'url' => $file,
                        'size' => filesize($file),
                        'modified' => filemtime($file)
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'receipts' => $receipts
        ]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>