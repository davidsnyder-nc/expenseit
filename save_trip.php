<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Handle multipart form data or JSON
    if (isset($_POST['action'])) {
        // Multipart form data (file uploads)
        $action = $_POST['action'];
        $data = [];
        foreach ($_POST as $key => $value) {
            if ($key !== 'action') {
                if ($key === 'expenseData') {
                    $data[$key] = json_decode($value, true);
                } else {
                    $data[$key] = $value;
                }
            }
        }
    } else {
        // JSON data
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $data = $input;
    }
    
    switch ($action) {
        case 'create_trip':
            $result = createTrip($data['tripData']);
            break;
        case 'update_metadata':
            $result = updateTripMetadata($data['originalName'], $data['metadata']);
            break;
        case 'add_expense':
            $result = addExpense($data['tripName'], $data['expenseData'], $_FILES['receipt'] ?? null);
            break;
        case 'update_expense':
            $result = updateExpense($data['tripName'], $data['expenseData']);
            break;
        case 'delete_expense':
            $result = deleteExpense($data['tripName'], $data['expenseId']);
            break;
        case 'delete_trip':
            $result = deleteTrip($data['tripName']);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log('Save trip error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Create a new trip
 */
function createTrip($tripData) {
    $tripName = sanitizeName($tripData['metadata']['name']);
    $tripDir = "data/trips/" . $tripName;
    
    // Create directory structure
    if (!is_dir($tripDir)) {
        if (!mkdir($tripDir, 0755, true)) {
            throw new Exception('Failed to create trip directory');
        }
    }
    
    $receiptsDir = $tripDir . "/receipts";
    if (!is_dir($receiptsDir)) {
        if (!mkdir($receiptsDir, 0755, true)) {
            throw new Exception('Failed to create receipts directory');
        }
    }
    
    // Move files from temp directory if they exist
    $tempDir = "data/trips/temp/receipts";
    if (is_dir($tempDir)) {
        $files = glob($tempDir . "/*");
        foreach ($files as $file) {
            $filename = basename($file);
            $newPath = $receiptsDir . "/" . $filename;
            if (!rename($file, $newPath)) {
                error_log("Failed to move file: $file to $newPath");
            }
        }
        // Clean up temp directory
        @rmdir($tempDir);
        @rmdir("data/trips/temp");
    }
    
    // Update expense sources to point to new location
    foreach ($tripData['expenses'] as &$expense) {
        if (isset($expense['source']) && strpos($expense['source'], 'data/trips/temp/') === 0) {
            $expense['source'] = str_replace('data/trips/temp/receipts/', 'receipts/', $expense['source']);
        }
    }
    
    // Save metadata
    $metadataPath = $tripDir . "/metadata.json";
    if (!file_put_contents($metadataPath, json_encode($tripData['metadata'], JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save metadata');
    }
    
    // Save expenses
    $expensesPath = $tripDir . "/expenses.json";
    if (!file_put_contents($expensesPath, json_encode($tripData['expenses'], JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save expenses');
    }
    
    return ['success' => true, 'tripName' => $tripName];
}

/**
 * Update trip metadata
 */
function updateTripMetadata($originalName, $metadata) {
    $oldTripName = sanitizeName($originalName);
    $newTripName = sanitizeName($metadata['name']);
    
    $oldTripDir = "data/trips/" . $oldTripName;
    $newTripDir = "data/trips/" . $newTripName;
    
    // Check if old directory exists
    if (!is_dir($oldTripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Rename directory if name changed
    if ($oldTripName !== $newTripName) {
        if (is_dir($newTripDir)) {
            throw new Exception('A trip with this name already exists');
        }
        
        if (!rename($oldTripDir, $newTripDir)) {
            throw new Exception('Failed to rename trip directory');
        }
    }
    
    // Save updated metadata
    $metadataPath = $newTripDir . "/metadata.json";
    if (!file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save metadata');
    }
    
    return ['success' => true, 'tripName' => $newTripName];
}

/**
 * Add new expense to trip
 */
function addExpense($tripName, $expenseData, $receiptFile = null) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    $expensesPath = $tripDir . "/expenses.json";
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Handle receipt upload
    if ($receiptFile && $receiptFile['error'] === UPLOAD_ERR_OK) {
        $receiptsDir = $tripDir . "/receipts";
        if (!is_dir($receiptsDir)) {
            mkdir($receiptsDir, 0755, true);
        }
        
        $extension = pathinfo($receiptFile['name'], PATHINFO_EXTENSION);
        $filename = generateUniqueFilename($receiptFile['name'], $receiptsDir, $extension);
        $targetPath = $receiptsDir . '/' . $filename;
        
        if (move_uploaded_file($receiptFile['tmp_name'], $targetPath)) {
            $expenseData['source'] = 'receipts/' . $filename;
        }
    }
    
    // Load existing expenses
    $expenses = [];
    if (file_exists($expensesPath)) {
        $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
    }
    
    // Add new expense
    $expenses[] = $expenseData;
    
    // Save expenses
    if (!file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save expenses');
    }
    
    return ['success' => true];
}

/**
 * Update existing expense
 */
function updateExpense($tripName, $expenseData) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    $expensesPath = $tripDir . "/expenses.json";
    
    if (!file_exists($expensesPath)) {
        throw new Exception('Expenses file not found');
    }
    
    // Load expenses
    $expenses = json_decode(file_get_contents($expensesPath), true);
    if (!$expenses) {
        throw new Exception('Failed to load expenses');
    }
    
    // Find and update expense
    $found = false;
    foreach ($expenses as &$expense) {
        if ($expense['id'] === $expenseData['id']) {
            // Preserve source if not provided
            if (!isset($expenseData['source']) && isset($expense['source'])) {
                $expenseData['source'] = $expense['source'];
            }
            $expense = $expenseData;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        throw new Exception('Expense not found');
    }
    
    // Save expenses
    if (!file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save expenses');
    }
    
    return ['success' => true];
}

/**
 * Delete expense
 */
function deleteExpense($tripName, $expenseId) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    $expensesPath = $tripDir . "/expenses.json";
    
    if (!file_exists($expensesPath)) {
        throw new Exception('Expenses file not found');
    }
    
    // Load expenses
    $expenses = json_decode(file_get_contents($expensesPath), true);
    if (!$expenses) {
        throw new Exception('Failed to load expenses');
    }
    
    // Find and remove expense
    $expenseToDelete = null;
    $expenses = array_filter($expenses, function($expense) use ($expenseId, &$expenseToDelete) {
        if ($expense['id'] === $expenseId) {
            $expenseToDelete = $expense;
            return false;
        }
        return true;
    });
    
    if (!$expenseToDelete) {
        throw new Exception('Expense not found');
    }
    
    // Delete associated receipt file
    if (isset($expenseToDelete['source']) && $expenseToDelete['source']) {
        $receiptPath = $tripDir . "/" . $expenseToDelete['source'];
        if (file_exists($receiptPath)) {
            @unlink($receiptPath);
        }
    }
    
    // Re-index array
    $expenses = array_values($expenses);
    
    // Save expenses
    if (!file_put_contents($expensesPath, json_encode($expenses, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save expenses');
    }
    
    return ['success' => true];
}

/**
 * Delete entire trip
 */
function deleteTrip($tripName) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Recursively delete trip directory and all contents
    if (!deleteDirectory($tripDir)) {
        throw new Exception('Failed to delete trip directory');
    }
    
    return ['success' => true];
}

/**
 * Recursively delete a directory and all its contents
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
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
 * Generate unique filename to avoid conflicts
 */
function generateUniqueFilename($originalName, $directory, $extension) {
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $baseName);
    
    $filename = $baseName . '.' . $extension;
    $counter = 1;
    
    while (file_exists($directory . '/' . $filename)) {
        $filename = $baseName . '_' . $counter . '.' . $extension;
        $counter++;
    }
    
    return $filename;
}
?>
