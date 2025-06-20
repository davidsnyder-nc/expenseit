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
        case 'toggle_expense_exclusion':
            $result = toggleExpenseExclusion($data['tripName'], $data['expenseId']);
            break;
        case 'edit_expense':
            $result = editExpense($data['tripName'], $data['expense']);
            break;
        case 'edit_trip_metadata':
            $result = editTripMetadata($data['tripName'], $data['metadata']);
            break;
        case 'delete_trip':
            $result = deleteTrip($data['tripName']);
            break;
        case 'archive_trip':
            $result = archiveTrip($data['tripName']);
            break;
        case 'export_all_trips':
            $result = exportAllTrips();
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
    // Use trip_name from extracted details if available, otherwise fall back to metadata name
    $extractedTripName = $tripData['metadata']['trip_name'] ?? null;
    $metadataName = $tripData['metadata']['name'] ?? null;
    
    // Prefer extracted trip name, but fall back to metadata name
    $tripName = $extractedTripName ?: $metadataName;
    
    // If still empty, use destination or fallback
    if (empty($tripName)) {
        $destination = $tripData['metadata']['destination'] ?? 'Trip';
        $tripName = $destination;
    }
    
    $tripName = sanitizeName($tripName);
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
    
    $travelDocsDir = $tripDir . "/travel_documents";
    if (!is_dir($travelDocsDir)) {
        if (!mkdir($travelDocsDir, 0755, true)) {
            throw new Exception('Failed to create travel documents directory');
        }
    }
    
    // Find and move files from temporary directories
    $tempDirs = glob("data/trips/temp_*", GLOB_ONLYDIR);
    
    foreach ($tempDirs as $tempDir) {
        // Move receipt files
        $tempReceiptsDir = $tempDir . "/receipts";
        if (is_dir($tempReceiptsDir)) {
            $files = glob($tempReceiptsDir . "/*");
            foreach ($files as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $newPath = $receiptsDir . "/" . $filename;
                    if (!rename($file, $newPath)) {
                        error_log("Failed to move receipt file: $file to $newPath");
                    }
                }
            }
            @rmdir($tempReceiptsDir);
        }
        
        // Move travel documents
        $tempTravelDocsDir = $tempDir . "/travel_documents";
        if (is_dir($tempTravelDocsDir)) {
            $files = glob($tempTravelDocsDir . "/*");
            foreach ($files as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $newPath = $travelDocsDir . "/" . $filename;
                    if (!rename($file, $newPath)) {
                        error_log("Failed to move travel document: $file to $newPath");
                    }
                }
            }
            @rmdir($tempTravelDocsDir);
        }
        
        // Clean up temp directory
        @rmdir($tempDir);
    }
    
    // Update expense sources to point to new location
    foreach ($tripData['expenses'] as &$expense) {
        if (isset($expense['source'])) {
            // Handle dynamic temp directories
            if (preg_match('/data\/trips\/temp_\d+\/receipts\/(.+)/', $expense['source'], $matches)) {
                $expense['source'] = 'receipts/' . $matches[1];
            }
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
 * Toggle expense exclusion from totals
 */
function toggleExpenseExclusion($tripName, $expenseId) {
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
    
    // Find and toggle expense exclusion
    $found = false;
    $excluded = false;
    foreach ($expenses as &$expense) {
        if ($expense['id'] === $expenseId) {
            // Toggle excluded status
            $expense['excluded'] = !($expense['excluded'] ?? false);
            $excluded = $expense['excluded'];
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
    
    return [
        'success' => true, 
        'excluded' => $excluded,
        'message' => $excluded ? 'Expense excluded from totals' : 'Expense included in totals'
    ];
}

/**
 * Delete entire trip
 */
function deleteTrip($tripName) {
    $tripName = sanitizeName($tripName);
    $activeTripDir = "data/trips/" . $tripName;
    $archivedTripDir = "data/archive/" . $tripName;
    
    $tripDir = null;
    
    // Check if trip exists in active trips
    if (is_dir($activeTripDir)) {
        $tripDir = $activeTripDir;
    }
    // Check if trip exists in archived trips
    elseif (is_dir($archivedTripDir)) {
        $tripDir = $archivedTripDir;
    }
    
    if (!$tripDir) {
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

/**
 * Archive a trip by moving it to archive folder and creating a zip file
 */
function archiveTrip($tripName) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    $archiveBaseDir = "data/archive";
    $archiveDestDir = $archiveBaseDir . "/" . $tripName;
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Create archive directory if it doesn't exist
    if (!is_dir($archiveBaseDir)) {
        mkdir($archiveBaseDir, 0755, true);
    }
    
    // If archived trip already exists, remove it first
    if (is_dir($archiveDestDir)) {
        deleteDirectory($archiveDestDir);
    }
    
    // Move trip directory to archive
    if (rename($tripDir, $archiveDestDir)) {
        // Add archived timestamp to metadata
        $metadataPath = $archiveDestDir . '/metadata.json';
        if (file_exists($metadataPath)) {
            $metadata = json_decode(file_get_contents($metadataPath), true) ?: [];
            $metadata['archivedDate'] = date('Y-m-d H:i:s');
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
        }
        
        return ['success' => true, 'message' => 'Trip archived successfully'];
    } else {
        throw new Exception('Failed to move trip to archive');
    }
}

/**
 * Export all trips as a downloadable zip file
 */
function exportAllTrips() {
    $tripsDir = 'data/trips';
    $archiveDir = 'data/archive';
    $tempDir = sys_get_temp_dir() . '/expense_export_' . uniqid();
    
    // Create temporary directory
    if (!mkdir($tempDir, 0755, true)) {
        throw new Exception('Failed to create temporary directory');
    }
    
    try {
        // Create active trips folder in temp
        $activeDir = $tempDir . '/active_trips';
        mkdir($activeDir, 0755, true);
        
        // Copy active trips
        if (is_dir($tripsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tripsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                $sourceFile = $file->getRealPath();
                $relativePath = substr($sourceFile, strlen($tripsDir) + 1);
                $destFile = $activeDir . '/' . $relativePath;
                
                if ($file->isDir()) {
                    mkdir($destFile, 0755, true);
                } else {
                    copy($sourceFile, $destFile);
                }
            }
        }
        
        // Copy archived trips
        if (is_dir($archiveDir)) {
            $archivedDir = $tempDir . '/archived_trips';
            mkdir($archivedDir, 0755, true);
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($archiveDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                $sourceFile = $file->getRealPath();
                $relativePath = substr($sourceFile, strlen($archiveDir) + 1);
                $destFile = $archivedDir . '/' . $relativePath;
                
                if ($file->isDir()) {
                    mkdir($destFile, 0755, true);
                } else {
                    copy($sourceFile, $destFile);
                }
            }
        }
        
        // Create export zip
        $exportZip = sys_get_temp_dir() . '/all_trips_export_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($exportZip, ZipArchive::CREATE) === TRUE) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tempDir) + 1);
                
                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }
            }
            
            $zip->close();
            
            // Clean up temp directory
            deleteDirectory($tempDir);
            
            // Set headers for download
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="all_trips_export_' . date('Y-m-d_H-i-s') . '.zip"');
            header('Content-Length: ' . filesize($exportZip));
            
            // Output file and clean up
            readfile($exportZip);
            unlink($exportZip);
            exit;
            
        } else {
            throw new Exception('Failed to create export zip file');
        }
        
    } catch (Exception $e) {
        // Clean up temp directory on error
        if (is_dir($tempDir)) {
            deleteDirectory($tempDir);
        }
        throw $e;
    }
}

/**
 * Edit an existing expense
 */
function editExpense($tripName, $expenseData) {
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
            // Preserve certain fields that shouldn't be edited
            $preservedFields = ['source', 'is_travel_document', 'gemini_processed', 'daily_breakdown', 'is_hotel_stay'];
            foreach ($preservedFields as $field) {
                if (isset($expense[$field])) {
                    $expenseData[$field] = $expense[$field];
                }
            }
            
            // Update the expense
            $expense = array_merge($expense, $expenseData);
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
 * Edit trip metadata
 */
function editTripMetadata($tripName, $metadata) {
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    $metadataPath = $tripDir . "/metadata.json";
    
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Load existing metadata
    $existingMetadata = [];
    if (file_exists($metadataPath)) {
        $existingMetadata = json_decode(file_get_contents($metadataPath), true) ?: [];
    }
    
    // Check if trip name is changing
    $newTripName = sanitizeName($metadata['name']);
    $nameChanged = ($newTripName !== $tripName);
    
    // If name changed, check if new name already exists
    if ($nameChanged) {
        $newTripDir = "data/trips/" . $newTripName;
        if (is_dir($newTripDir)) {
            throw new Exception('A trip with that name already exists');
        }
    }
    
    // Update metadata
    $updatedMetadata = array_merge($existingMetadata, [
        'name' => $metadata['name'],
        'destination' => $metadata['destination'],
        'start_date' => $metadata['start_date'],
        'end_date' => $metadata['end_date'],
        'notes' => $metadata['notes']
    ]);
    
    // Save updated metadata
    if (!file_put_contents($metadataPath, json_encode($updatedMetadata, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save metadata');
    }
    
    $result = ['success' => true];
    
    // If name changed, rename the directory
    if ($nameChanged) {
        $newTripDir = "data/trips/" . $newTripName;
        if (rename($tripDir, $newTripDir)) {
            // Update metadata file in new location
            $newMetadataPath = $newTripDir . "/metadata.json";
            file_put_contents($newMetadataPath, json_encode($updatedMetadata, JSON_PRETTY_PRINT));
            $result['newTripName'] = $newTripName;
        } else {
            throw new Exception('Failed to rename trip directory');
        }
    }
    
    return $result;
}


?>
