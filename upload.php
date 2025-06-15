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

/**
 * Convert uploaded image to JPEG format
 */
function convertToJpeg($sourcePath, $targetPath, $sourceExtension) {
    // Try ImageMagick first
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $imagick = new Imagick();
            $imagick->readImage($sourcePath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(85);
            
            // Resize if image is too large (max 2048px on longest side)
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $maxDimension = 2048;
            
            if ($width > $maxDimension || $height > $maxDimension) {
                if ($width > $height) {
                    $newWidth = $maxDimension;
                    $newHeight = ($height * $maxDimension) / $width;
                } else {
                    $newHeight = $maxDimension;
                    $newWidth = ($width * $maxDimension) / $height;
                }
                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
            }
            
            $imagick->writeImage($targetPath);
            $imagick->clear();
            return true;
        } catch (Exception $e) {
            error_log("ImageMagick conversion failed: " . $e->getMessage());
        }
    }
    
    // Fallback to GD for basic formats
    if (extension_loaded('gd') && in_array($sourceExtension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) {
        try {
            switch ($sourceExtension) {
                case 'png':
                    $sourceImage = imagecreatefrompng($sourcePath);
                    break;
                case 'jpg':
                case 'jpeg':
                    $sourceImage = imagecreatefromjpeg($sourcePath);
                    break;
                case 'gif':
                    $sourceImage = imagecreatefromgif($sourcePath);
                    break;
                case 'webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $sourceImage = imagecreatefromwebp($sourcePath);
                    } else {
                        return false;
                    }
                    break;
                case 'bmp':
                    if (function_exists('imagecreatefrombmp')) {
                        $sourceImage = imagecreatefrombmp($sourcePath);
                    } else {
                        return false;
                    }
                    break;
                default:
                    return false;
            }
            
            if ($sourceImage) {
                // Handle transparency for PNG and GIF by creating white background
                if (in_array($sourceExtension, ['png', 'gif'])) {
                    $width = imagesx($sourceImage);
                    $height = imagesy($sourceImage);
                    $background = imagecreatetruecolor($width, $height);
                    $white = imagecolorallocate($background, 255, 255, 255);
                    imagefill($background, 0, 0, $white);
                    imagecopy($background, $sourceImage, 0, 0, 0, 0, $width, $height);
                    imagedestroy($sourceImage);
                    $sourceImage = $background;
                }
                
                $result = imagejpeg($sourceImage, $targetPath, 85);
                imagedestroy($sourceImage);
                return $result;
            }
        } catch (Exception $e) {
            error_log("GD conversion failed: " . $e->getMessage());
        }
    }
    
    return false;
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['file'];
    $tripName = $_POST['tripName'] ?? $_POST['trip'] ?? 'temp';
    
    // Auto-detect document type based on filename
    $fileName = strtolower($file['name']);
    $documentType = 'receipt'; // default
    
    if (strpos($fileName, 'itinerary') !== false || 
        strpos($fileName, 'confirmation') !== false ||
        strpos($fileName, 'boarding') !== false ||
        strpos($fileName, 'flight') !== false ||
        strpos($fileName, 'travel') !== false ||
        strpos($fileName, 'gmail') !== false ||
        strpos($fileName, 'fw_') !== false ||
        strpos($fileName, 'trip') !== false) {
        $documentType = 'travel_document';
    }
    
    // Allow override from POST data
    $documentType = $_POST['type'] ?? $documentType;
    
    // Validate file type by extension (more reliable than MIME type for HEIC/TIFF)
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'heic', 'tiff', 'tif', 'webp', 'bmp', 'gif'];
    $allowedMimeTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/heic', 'image/tiff', 'image/tif', 'image/webp', 'image/bmp', 'image/gif'];
    
    if (!in_array($extension, $allowedExtensions) && !in_array($file['type'], $allowedMimeTypes)) {
        throw new Exception('Invalid file type. Only PDF and image files are allowed.');
    }
    
    // Validate file size (max 10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum 10MB allowed.');
    }
    
    // Create directory structure
    $tripDir = "data/trips/" . sanitizeName($tripName);
    
    // Determine target directory based on document type
    if ($documentType === 'travel_document') {
        $targetDir = $tripDir . "/travel_documents";
    } else {
        $targetDir = $tripDir . "/receipts";
    }
    
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Convert all images to JPEG, keep PDFs as-is
    $originalExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
    
    if ($originalExtension === 'pdf') {
        // Keep PDFs as-is
        $filename = generateUniqueFilename($file['name'], $targetDir, $originalExtension);
        $targetPath = $targetDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save uploaded file');
        }
    } else {
        // Convert all image formats to JPEG
        $filename = generateUniqueFilename($baseName . '.jpg', $targetDir, 'jpg');
        $targetPath = $targetDir . '/' . $filename;
        
        if (!convertToJpeg($file['tmp_name'], $targetPath, $originalExtension)) {
            // If conversion fails, try to save original file
            $originalFilename = generateUniqueFilename($file['name'], $targetDir, $originalExtension);
            $originalTargetPath = $targetDir . '/' . $originalFilename;
            
            if (!move_uploaded_file($file['tmp_name'], $originalTargetPath)) {
                throw new Exception('Failed to convert and save image file');
            }
            
            $filename = $originalFilename;
            $targetPath = $originalTargetPath;
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'path' => $targetPath,
        'filename' => $filename,
        'size' => $file['size'],
        'type' => $file['type'],
        'documentType' => $documentType
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
