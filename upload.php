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
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['file'];
    $tripName = $_POST['tripName'] ?? 'temp';
    
    // Validate file type
    $allowedTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only PDF and image files are allowed.');
    }
    
    // Validate file size (max 10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum 10MB allowed.');
    }
    
    // Create directory structure
    $tripDir = "data/trips/" . sanitizeName($tripName);
    $receiptsDir = $tripDir . "/receipts";
    
    if (!is_dir($receiptsDir)) {
        if (!mkdir($receiptsDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = generateUniqueFilename($file['name'], $receiptsDir, $extension);
    $targetPath = $receiptsDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'path' => $targetPath,
        'filename' => $filename,
        'size' => $file['size'],
        'type' => $file['type']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
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
    $baseName = sanitizeName($baseName);
    
    $filename = $baseName . '.' . $extension;
    $counter = 1;
    
    while (file_exists($directory . '/' . $filename)) {
        $filename = $baseName . '_' . $counter . '.' . $extension;
        $counter++;
    }
    
    return $filename;
}
?>
