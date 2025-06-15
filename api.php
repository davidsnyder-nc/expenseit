<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function sanitizeName($name) {
    // Keep spaces for user-friendly names, only remove problematic characters
    $name = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name); // Normalize multiple spaces to single space
    return trim($name) ?: 'untitled';
}

function extractDestinationSimple($tripName) {
    // Simple pattern matching for common destinations
    $patterns = [
        '/\b(india|delhi|mumbai|bangalore|chennai|kolkata|hyderabad)\b/i' => 'India',
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

function performSearch($query, $includeArchives = false) {
    $results = [];
    $searchDirs = ['data/trips'];
    
    // Add archive directory to search if requested
    if ($includeArchives) {
        $searchDirs[] = 'data/archive';
    }
    
    foreach ($searchDirs as $baseDir) {
        if (!is_dir($baseDir)) continue;
        
        $tripDirs = glob($baseDir . '/*', GLOB_ONLYDIR);
        
        foreach ($tripDirs as $tripDir) {
            $tripName = basename($tripDir);
            if ($tripName === 'temp') continue;
            
            $metadataPath = $tripDir . '/metadata.json';
            $expensesPath = $tripDir . '/expenses.json';
            
            if (!file_exists($metadataPath)) continue;
            
            $metadata = json_decode(file_get_contents($metadataPath), true);
            if (!$metadata) continue;
            
            $isArchived = ($baseDir === 'data/archive');
        
            // Search in trip metadata
            $searchableMetadata = [
                $metadata['name'] ?? '',
                $metadata['destination'] ?? '',
                $metadata['notes'] ?? '',
                $metadata['start_date'] ?? '',
                $metadata['end_date'] ?? ''
            ];
            
            foreach ($searchableMetadata as $field) {
                if (stripos($field, $query) !== false) {
                    $results[] = [
                        'tripName' => $tripName,
                        'type' => 'Trip Details' . ($isArchived ? ' (Archived)' : ''),
                        'content' => "Trip: {$metadata['name']} | Destination: {$metadata['destination']} | Notes: {$metadata['notes']}",
                        'archived' => $isArchived
                    ];
                    break; // Avoid duplicate entries for the same trip
                }
            }
        
            // Search in expenses
            if (file_exists($expensesPath)) {
                $expenses = json_decode(file_get_contents($expensesPath), true) ?: [];
                
                foreach ($expenses as $expense) {
                    $searchableExpenseData = [
                        $expense['merchant'] ?? '',
                        $expense['category'] ?? '',
                        $expense['note'] ?? '',
                        $expense['date'] ?? '',
                        (string)($expense['amount'] ?? '')
                    ];
                    
                    foreach ($searchableExpenseData as $field) {
                        if (stripos($field, $query) !== false) {
                            $amount = number_format($expense['amount'] ?? 0, 2);
                            $results[] = [
                                'tripName' => $tripName,
                                'type' => 'Expense' . ($isArchived ? ' (Archived)' : ''),
                                'content' => "Merchant: {$expense['merchant']} | Amount: \${$amount} | Category: {$expense['category']} | Note: {$expense['note']} | Date: {$expense['date']}",
                                'archived' => $isArchived
                            ];
                            break; // Avoid duplicate entries for the same expense
                        }
                    }
                }
            }
        }
    }
    
    // Sort results by trip name
    usort($results, function($a, $b) {
        return strcmp($a['tripName'], $b['tripName']);
    });
    
    return $results;
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
                // Sort by creation time (newest first) - use directory modification time
                $dirA = 'data/trips/' . sanitizeName($a['name']);
                $dirB = 'data/trips/' . sanitizeName($b['name']);
                $timeA = is_dir($dirA) ? filemtime($dirA) : 0;
                $timeB = is_dir($dirB) ? filemtime($dirB) : 0;
                return $timeB <=> $timeA;
            });
        }
        
        echo json_encode(['success' => true, 'trips' => $trips]);
        
    } elseif ($action === 'archived_trips') {
        $trips = [];
        $archiveDir = 'data/archive';
        
        if (is_dir($archiveDir)) {
            $tripDirs = glob($archiveDir . '/*', GLOB_ONLYDIR);
            
            foreach ($tripDirs as $tripDir) {
                $tripName = basename($tripDir);
                $metadataPath = $tripDir . '/metadata.json';
                
                if (file_exists($metadataPath)) {
                    $metadata = json_decode(file_get_contents($metadataPath), true) ?: [];
                    
                    $expensesPath = $tripDir . '/expenses.json';
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
                        'total' => number_format($total, 2),
                        'archived' => true
                    ];
                }
            }
            
            // Sort by archived date (newest first)
            usort($trips, function($a, $b) {
                $dateA = $a['metadata']['archivedDate'] ?? '';
                $dateB = $b['metadata']['archivedDate'] ?? '';
                return strtotime($dateB) <=> strtotime($dateA);
            });
        }
        
        echo json_encode(['success' => true, 'trips' => $trips]);
        
    } elseif ($action === 'trip') {
        $tripName = $_GET['name'] ?? '';
        if (empty($tripName)) {
            echo json_encode(['success' => false, 'error' => 'Trip name is required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $tripDir = 'data/trips/' . $tripName;
        $archiveDir = 'data/archive/' . $tripName;
        
        // Check both active and archived locations
        if (is_dir($tripDir)) {
            // Active trip
            $isArchived = false;
        } elseif (is_dir($archiveDir)) {
            // Archived trip
            $tripDir = $archiveDir;
            $isArchived = true;
        } else {
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
        
        // Calculate statistics (exclude travel documents from totals)
        $total = 0;
        $categories = [];
        foreach ($expenses as $expense) {
            // Skip travel documents in total calculations
            if (isset($expense['is_travel_document']) && $expense['is_travel_document'] === true) {
                continue;
            }
            
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
        
        // Add archived status to metadata for frontend
        $metadata['isArchived'] = $isArchived;
        
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
        $archiveReceiptsDir = 'data/archive/' . $tripName . '/receipts';
        
        // Check both active and archived locations
        if (is_dir($receiptsDir)) {
            $targetDir = $receiptsDir;
        } elseif (is_dir($archiveReceiptsDir)) {
            $targetDir = $archiveReceiptsDir;
        } else {
            echo json_encode(['success' => true, 'receipts' => []]);
            exit;
        }
        
        $receipts = [];
        
        if (is_dir($targetDir)) {
            $receiptFiles = glob($targetDir . '/*');
            foreach ($receiptFiles as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    // Only allow PDF and JPEG/JPG files
                    if (!in_array($extension, ['pdf', 'jpg', 'jpeg'])) {
                        continue;
                    }
                    
                    $isPdf = $extension === 'pdf';
                    $isImage = in_array($extension, ['jpg', 'jpeg']);
                    
                    // Use relative web-accessible paths
                    $webPath = $targetDir . '/' . $filename;
                    
                    $receipts[] = [
                        'filename' => $filename,
                        'name' => $filename,
                        'path' => $webPath,
                        'fullUrl' => $webPath,
                        'displayUrl' => $webPath,
                        'size' => filesize($file),
                        'modified' => filemtime($file),
                        'isPdf' => $isPdf,
                        'isImage' => $isImage,
                        'extension' => $extension
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'receipts' => $receipts
        ]);
        
    } elseif ($action === 'travel_documents') {
        $tripName = $_GET['name'] ?? '';
        if (empty($tripName)) {
            echo json_encode(['success' => false, 'error' => 'Trip name is required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $documentsDir = 'data/trips/' . $tripName . '/travel_documents';
        $archiveDocumentsDir = 'data/archive/' . $tripName . '/travel_documents';
        
        // Check both active and archived locations
        if (is_dir($documentsDir)) {
            $targetDir = $documentsDir;
        } elseif (is_dir($archiveDocumentsDir)) {
            $targetDir = $archiveDocumentsDir;
        } else {
            echo json_encode(['success' => true, 'documents' => []]);
            exit;
        }
        
        $documents = [];
        
        if (is_dir($targetDir)) {
            $documentFiles = glob($targetDir . '/*');
            foreach ($documentFiles as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    // Only show PDF files in travel documents
                    if ($extension !== 'pdf') {
                        continue;
                    }
                    
                    // Use relative web-accessible paths
                    $webPath = $targetDir . '/' . $filename;
                    
                    $documents[] = [
                        'filename' => $filename,
                        'name' => $filename,
                        'path' => $webPath,
                        'fullUrl' => $webPath,
                        'displayUrl' => $webPath,
                        'size' => filesize($file),
                        'modified' => filemtime($file),
                        'isPdf' => true,
                        'extension' => $extension
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'documents' => $documents
        ]);
        
    } elseif ($action === 'search') {
        $query = $_GET['query'] ?? '';
        $includeArchives = ($_GET['include_archives'] ?? 'false') === 'true';
        
        if (empty($query)) {
            echo json_encode(['success' => false, 'error' => 'Search query is required']);
            exit;
        }
        
        $results = performSearch($query, $includeArchives);
        echo json_encode(['success' => true, 'results' => $results]);
        
    } elseif ($action === 'rename_trip') {
        // Handle POST data for rename operation
        $input = json_decode(file_get_contents('php://input'), true);
        $oldName = $input['oldName'] ?? '';
        $newName = $input['newName'] ?? '';
        
        if (empty($oldName) || empty($newName)) {
            echo json_encode(['success' => false, 'error' => 'Old and new names are required']);
            exit;
        }
        
        $oldName = sanitizeName($oldName);
        $newName = sanitizeName($newName);
        
        $oldDir = 'data/trips/' . $oldName;
        $newDir = 'data/trips/' . $newName;
        
        if (!is_dir($oldDir)) {
            echo json_encode(['success' => false, 'error' => 'Source trip not found']);
            exit;
        }
        
        if (is_dir($newDir)) {
            echo json_encode(['success' => false, 'error' => 'Destination trip already exists']);
            exit;
        }
        
        // Rename the directory
        if (rename($oldDir, $newDir)) {
            // Update metadata file with new name
            $metadataPath = $newDir . '/metadata.json';
            if (file_exists($metadataPath)) {
                $metadata = json_decode(file_get_contents($metadataPath), true);
                if ($metadata) {
                    $metadata['name'] = $newName;
                    file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Trip renamed successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to rename trip directory']);
        }
        
    } elseif ($action === 'toggle_expense_exclusion') {
        $tripName = $_POST['trip_name'] ?? '';
        $expenseId = $_POST['expense_id'] ?? '';
        
        if (empty($tripName) || empty($expenseId)) {
            echo json_encode(['success' => false, 'error' => 'Trip name and expense ID are required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $expensesFile = 'data/trips/' . $tripName . '/expenses.json';
        
        if (!file_exists($expensesFile)) {
            echo json_encode(['success' => false, 'error' => 'Trip expenses not found']);
            exit;
        }
        
        $expenses = json_decode(file_get_contents($expensesFile), true);
        if (!$expenses) {
            echo json_encode(['success' => false, 'error' => 'Failed to load expenses']);
            exit;
        }
        
        $found = false;
        foreach ($expenses as &$expense) {
            if ($expense['id'] === $expenseId) {
                $expense['excluded'] = !($expense['excluded'] ?? false);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            echo json_encode(['success' => false, 'error' => 'Expense not found']);
            exit;
        }
        
        if (file_put_contents($expensesFile, json_encode($expenses, JSON_PRETTY_PRINT))) {
            echo json_encode(['success' => true, 'message' => 'Expense exclusion status updated']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save changes']);
        }
        
    } elseif ($action === 'delete_expense') {
        $tripName = $_POST['trip_name'] ?? '';
        $expenseId = $_POST['expense_id'] ?? '';
        
        if (empty($tripName) || empty($expenseId)) {
            echo json_encode(['success' => false, 'error' => 'Trip name and expense ID are required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $expensesFile = 'data/trips/' . $tripName . '/expenses.json';
        
        if (!file_exists($expensesFile)) {
            echo json_encode(['success' => false, 'error' => 'Trip expenses not found']);
            exit;
        }
        
        $expenses = json_decode(file_get_contents($expensesFile), true);
        if (!$expenses) {
            echo json_encode(['success' => false, 'error' => 'Failed to load expenses']);
            exit;
        }
        
        $originalCount = count($expenses);
        $expenses = array_filter($expenses, function($expense) use ($expenseId) {
            return $expense['id'] !== $expenseId;
        });
        
        if (count($expenses) === $originalCount) {
            echo json_encode(['success' => false, 'error' => 'Expense not found']);
            exit;
        }
        
        // Re-index array to maintain proper JSON structure
        $expenses = array_values($expenses);
        
        if (file_put_contents($expensesFile, json_encode($expenses, JSON_PRETTY_PRINT))) {
            echo json_encode(['success' => true, 'message' => 'Expense deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save changes']);
        }
        
    } elseif ($action === 'update_expense') {
        $tripName = $_POST['trip_name'] ?? '';
        $expenseId = $_POST['expense_id'] ?? '';
        
        if (empty($tripName) || empty($expenseId)) {
            echo json_encode(['success' => false, 'error' => 'Trip name and expense ID are required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        $expensesFile = 'data/trips/' . $tripName . '/expenses.json';
        
        if (!file_exists($expensesFile)) {
            echo json_encode(['success' => false, 'error' => 'Trip expenses not found']);
            exit;
        }
        
        $expenses = json_decode(file_get_contents($expensesFile), true);
        if (!$expenses) {
            echo json_encode(['success' => false, 'error' => 'Failed to load expenses']);
            exit;
        }
        
        $found = false;
        foreach ($expenses as &$expense) {
            if ($expense['id'] === $expenseId) {
                $expense['date'] = $_POST['date'] ?? $expense['date'];
                $expense['merchant'] = $_POST['merchant'] ?? $expense['merchant'];
                $expense['amount'] = floatval($_POST['amount'] ?? $expense['amount']);
                $expense['tax_amount'] = floatval($_POST['tax_amount'] ?? $expense['tax_amount']);
                $expense['category'] = $_POST['category'] ?? $expense['category'];
                $expense['note'] = $_POST['note'] ?? $expense['note'];
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            echo json_encode(['success' => false, 'error' => 'Expense not found']);
            exit;
        }
        
        if (file_put_contents($expensesFile, json_encode($expenses, JSON_PRETTY_PRINT))) {
            echo json_encode(['success' => true, 'message' => 'Expense updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save changes']);
        }
        
    } elseif ($action === 'update_trip') {
        $originalName = $_POST['original_name'] ?? '';
        $newName = $_POST['name'] ?? '';
        
        if (empty($originalName) || empty($newName)) {
            echo json_encode(['success' => false, 'error' => 'Original name and new name are required']);
            exit;
        }
        
        $originalName = sanitizeName($originalName);
        $newName = sanitizeName($newName);
        
        $metadataFile = 'data/trips/' . $originalName . '/metadata.json';
        
        if (!file_exists($metadataFile)) {
            echo json_encode(['success' => false, 'error' => 'Trip metadata not found']);
            exit;
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        if (!$metadata) {
            echo json_encode(['success' => false, 'error' => 'Failed to load trip metadata']);
            exit;
        }
        
        $metadata['name'] = $newName;
        $metadata['destination'] = $_POST['destination'] ?? $metadata['destination'];
        $metadata['start_date'] = $_POST['start_date'] ?? $metadata['start_date'];
        $metadata['end_date'] = $_POST['end_date'] ?? $metadata['end_date'];
        $metadata['notes'] = $_POST['notes'] ?? $metadata['notes'];
        
        if (file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT))) {
            echo json_encode(['success' => true, 'message' => 'Trip updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save changes']);
        }
        
    } elseif ($action === 'delete_trip') {
        $tripName = $_POST['name'] ?? '';
        
        if (empty($tripName)) {
            echo json_encode(['success' => false, 'error' => 'Trip name is required']);
            exit;
        }
        
        $tripName = sanitizeName($tripName);
        
        // Check in both active and archived directories
        $tripDir = 'data/trips/' . $tripName;
        $archiveDir = 'data/archive/' . $tripName;
        
        $targetDir = null;
        if (is_dir($tripDir)) {
            $targetDir = $tripDir;
        } elseif (is_dir($archiveDir)) {
            $targetDir = $archiveDir;
        }
        
        if (!$targetDir) {
            echo json_encode(['success' => false, 'error' => 'Trip not found']);
            exit;
        }
        
        // Recursively delete the trip directory and all its contents
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
        
        if (deleteDirectory($targetDir)) {
            echo json_encode(['success' => true, 'message' => 'Trip deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete trip']);
        }
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>