<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'trips':
            $result = loadAllTrips();
            break;
        case 'trip':
            $tripName = $_GET['name'] ?? '';
            if (empty($tripName)) {
                throw new Exception('Trip name is required');
            }
            $result = loadTrip($tripName);
            break;
        case 'receipts':
            $tripName = $_GET['name'] ?? '';
            if (empty($tripName)) {
                throw new Exception('Trip name is required');
            }
            $result = loadTripReceipts($tripName);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log('Load error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Load all trips for dashboard display
 */
function loadAllTrips() {
    $tripsDir = 'data/trips';
    
    // Check if trips directory exists
    if (!is_dir($tripsDir)) {
        return [
            'success' => true,
            'trips' => []
        ];
    }
    
    $trips = [];
    $tripDirs = glob($tripsDir . '/*', GLOB_ONLYDIR);
    
    foreach ($tripDirs as $tripDir) {
        $tripName = basename($tripDir);
        
        // Skip temp directories
        if ($tripName === 'temp') {
            continue;
        }
        
        try {
            $trip = loadTripSummary($tripName, $tripDir);
            if ($trip) {
                $trips[] = $trip;
            }
        } catch (Exception $e) {
            // Log error but continue with other trips
            error_log("Error loading trip $tripName: " . $e->getMessage());
        }
    }
    
    // Sort trips by start date (most recent first)
    usort($trips, function($a, $b) {
        $dateA = $a['metadata']['start_date'] ?? '';
        $dateB = $b['metadata']['start_date'] ?? '';
        return strtotime($dateB) <=> strtotime($dateA);
    });
    
    return [
        'success' => true,
        'trips' => $trips
    ];
}

/**
 * Load trip summary for dashboard
 */
function loadTripSummary($tripName, $tripDir) {
    $metadataPath = $tripDir . '/metadata.json';
    $expensesPath = $tripDir . '/expenses.json';
    
    // Load metadata
    if (!file_exists($metadataPath)) {
        return null;
    }
    
    $metadata = json_decode(file_get_contents($metadataPath), true);
    if (!$metadata) {
        return null;
    }
    
    // Load expenses to calculate totals
    $expenses = [];
    if (file_exists($expensesPath)) {
        $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
    }
    
    // Calculate total amount
    $total = 0;
    foreach ($expenses as $expense) {
        $total += floatval($expense['amount'] ?? 0);
    }
    
    return [
        'name' => $tripName,
        'metadata' => $metadata,
        'expenseCount' => count($expenses),
        'total' => number_format($total, 2)
    ];
}

/**
 * Load complete trip details
 */
function loadTrip($tripName) {
    $tripName = sanitizeName($tripName);
    $tripDir = 'data/trips/' . $tripName;
    
    // Check if trip directory exists
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    $metadataPath = $tripDir . '/metadata.json';
    $expensesPath = $tripDir . '/expenses.json';
    
    // Load metadata
    if (!file_exists($metadataPath)) {
        throw new Exception('Trip metadata not found');
    }
    
    $metadata = json_decode(file_get_contents($metadataPath), true);
    if (!$metadata) {
        throw new Exception('Invalid trip metadata');
    }
    
    // Load expenses
    $expenses = [];
    if (file_exists($expensesPath)) {
        $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
    }
    
    // Calculate totals and statistics
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
    
    // Check if PDF report exists
    $reportPath = $tripDir . '/report.pdf';
    $hasReport = file_exists($reportPath);
    
    return [
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
    ];
}

/**
 * Sanitize name for use as directory name
 */
function sanitizeName($name) {
    // Remove or replace invalid characters
    $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
    // Replace spaces with underscores
    $name = str_replace(' ', '_', $name);
    // Remove multiple underscores
    $name = preg_replace('/_+/', '_', $name);
    // Trim underscores from start and end
    $name = trim($name, '_');
    
    return $name ?: 'untitled';
}

/**
 * Load trip receipts for API
 */
function loadTripReceipts($tripName) {
    $receipts = getTripReceipts($tripName);
    
    return [
        'success' => true,
        'receipts' => $receipts
    ];
}

/**
 * Get trip receipts list
 */
function getTripReceipts($tripName) {
    $tripName = sanitizeName($tripName);
    $receiptsDir = 'data/trips/' . $tripName . '/receipts';
    
    if (!is_dir($receiptsDir)) {
        return [];
    }
    
    $receipts = [];
    $files = glob($receiptsDir . '/*');
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $receipts[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file)
            ];
        }
    }
    
    // Sort by filename
    usort($receipts, function($a, $b) {
        return strcmp($a['filename'], $b['filename']);
    });
    
    return $receipts;
}

/**
 * Get trip statistics
 */
function getTripStatistics($tripName) {
    $tripName = sanitizeName($tripName);
    $expensesPath = 'data/trips/' . $tripName . '/expenses.json';
    
    if (!file_exists($expensesPath)) {
        return [
            'total' => 0,
            'count' => 0,
            'categories' => [],
            'dailyTotals' => [],
            'averagePerDay' => 0
        ];
    }
    
    $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
    
    $total = 0;
    $categories = [];
    $dailyTotals = [];
    
    foreach ($expenses as $expense) {
        $amount = floatval($expense['amount'] ?? 0);
        $total += $amount;
        
        // Category totals
        $category = $expense['category'] ?? 'Other';
        if (!isset($categories[$category])) {
            $categories[$category] = 0;
        }
        $categories[$category] += $amount;
        
        // Daily totals
        $date = $expense['date'] ?? '';
        if ($date) {
            if (!isset($dailyTotals[$date])) {
                $dailyTotals[$date] = 0;
            }
            $dailyTotals[$date] += $amount;
        }
    }
    
    // Calculate average per day
    $dayCount = count($dailyTotals);
    $averagePerDay = $dayCount > 0 ? $total / $dayCount : 0;
    
    // Sort categories by amount (descending)
    arsort($categories);
    
    // Sort daily totals by date
    ksort($dailyTotals);
    
    return [
        'total' => $total,
        'count' => count($expenses),
        'categories' => $categories,
        'dailyTotals' => $dailyTotals,
        'averagePerDay' => $averagePerDay
    ];
}
?>
