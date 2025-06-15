<?php
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=3600');

// Get parameters
$file = $_GET['file'] ?? '';
$trip = $_GET['trip'] ?? '';

if (empty($file) || empty($trip)) {
    http_response_code(400);
    exit('Missing parameters');
}

// Sanitize inputs
$trip = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $trip);
$trip = str_replace(' ', '_', $trip);
$file = basename($file); // Prevent directory traversal

// Check both active and archived locations
$activeFilePath = "data/trips/$trip/receipts/$file";
$archiveFilePath = "data/archive/$trip/receipts/$file";

if (file_exists($activeFilePath)) {
    $filePath = $activeFilePath;
} elseif (file_exists($archiveFilePath)) {
    $filePath = $archiveFilePath;
} else {
    http_response_code(404);
    exit('File not found');
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// Generate cache path
$cacheDir = "data/trips/$trip/converted";
if (!file_exists($activeFilePath)) {
    $cacheDir = "data/archive/$trip/converted";
}
$cachePath = $cacheDir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Check if converted image already exists and is newer than original
if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($filePath)) {
    // Serve cached converted image
    readfile($cachePath);
    exit;
}

// Convert HEIC/TIFF to JPEG using ImageMagick
$converted = false;

if (extension_loaded('imagick') && class_exists('Imagick')) {
    try {
        $imagick = new Imagick();
        $imagick->readImage($filePath);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(85);
        
        // Save converted image to cache
        $imagick->writeImage($cachePath);
        $imagick->clear();
        
        // Serve the converted image
        readfile($cachePath);
        $converted = true;
    } catch (Exception $e) {
        error_log("HEIC/TIFF conversion failed: " . $e->getMessage());
    }
}

if (!$converted) {
    http_response_code(500);
    exit('Image conversion failed');
}
?>