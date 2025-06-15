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

$filePath = "data/trips/$trip/receipts/$file";
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// Check if file exists and is a supported format
$supportedFormats = ['pdf', 'png', 'jpg', 'jpeg', 'heic', 'tiff', 'tif'];
if (!file_exists($filePath) || !in_array($extension, $supportedFormats)) {
    http_response_code(404);
    exit('File not found or unsupported format');
}

// Generate cache path
$cacheDir = "data/trips/$trip/thumbnails";
$cachePath = $cacheDir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Check if thumbnail already exists and is newer than original
if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($filePath)) {
    // Serve cached thumbnail
    readfile($cachePath);
    exit;
}

// Generate thumbnail
$thumbnailCreated = false;

// Method 1: Try Imagick for all formats
if (extension_loaded('imagick') && class_exists('Imagick')) {
    try {
        $imagick = new Imagick();
        
        if ($extension === 'pdf') {
            $imagick->setResolution(150, 150);
            $imagick->readImage($filePath . '[0]'); // First page only
        } elseif (in_array($extension, ['heic', 'tiff', 'tif'])) {
            // For HEIC and TIFF files, read directly
            $imagick->readImage($filePath);
        } else {
            // For PNG, JPG, JPEG
            $imagick->readImage($filePath);
        }
        
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(85);
        
        // Calculate dimensions maintaining aspect ratio
        $originalWidth = $imagick->getImageWidth();
        $originalHeight = $imagick->getImageHeight();
        $maxWidth = 300;
        $maxHeight = 200;
        
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = round($originalWidth * $ratio);
        $newHeight = round($originalHeight * $ratio);
        
        $imagick->scaleImage($newWidth, $newHeight, true);
        
        // Save to cache
        $imagick->writeImage($cachePath);
        $imagick->clear();
        
        $thumbnailCreated = true;
    } catch (Exception $e) {
        error_log("Imagick thumbnail generation failed for $extension: " . $e->getMessage());
    }
}

// Method 2: Try Ghostscript for PDFs or fallback for other formats
if (!$thumbnailCreated && function_exists('exec')) {
    try {
        if ($extension === 'pdf') {
            $tempImagePath = sys_get_temp_dir() . '/pdf_thumb_' . uniqid() . '.jpg';
            $escapedPdfPath = escapeshellarg($filePath);
            $escapedImagePath = escapeshellarg($tempImagePath);
            
            // Use Ghostscript to convert PDF to JPEG
            $gsCommand = "gs -dNOPAUSE -dBATCH -sDEVICE=jpeg -dJPEGQ=85 -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile=$escapedImagePath $escapedPdfPath 2>/dev/null";
            exec($gsCommand, $output, $returnCode);
        } else {
            // For HEIC/TIFF files that failed ImageMagick, try to create a placeholder
            $returnCode = 1; // Skip processing for now
        }
        
        if ($returnCode === 0 && file_exists($tempImagePath)) {
            // Resize using GD if available
            if (extension_loaded('gd')) {
                $sourceImage = imagecreatefromjpeg($tempImagePath);
                if ($sourceImage) {
                    $sourceWidth = imagesx($sourceImage);
                    $sourceHeight = imagesy($sourceImage);
                    
                    // Calculate new dimensions maintaining aspect ratio
                    $maxWidth = 300;
                    $maxHeight = 200;
                    $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
                    $newWidth = round($sourceWidth * $ratio);
                    $newHeight = round($sourceHeight * $ratio);
                    
                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                    
                    // Set white background
                    $white = imagecolorallocate($resizedImage, 255, 255, 255);
                    imagefill($resizedImage, 0, 0, $white);
                    
                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
                    
                    // Save to cache
                    imagejpeg($resizedImage, $cachePath, 85);
                    
                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);
                    $thumbnailCreated = true;
                }
            } else {
                // Copy original if GD not available
                copy($tempImagePath, $cachePath);
                $thumbnailCreated = true;
            }
            unlink($tempImagePath);
        }
    } catch (Exception $e) {
        error_log("Ghostscript thumbnail generation failed: " . $e->getMessage());
    }
}

// Serve the thumbnail if created
if ($thumbnailCreated && file_exists($cachePath)) {
    readfile($cachePath);
} else {
    // Generate a placeholder image
    $width = 300;
    $height = 200;
    $image = imagecreatetruecolor($width, $height);
    
    // Colors
    $backgroundColor = imagecolorallocate($image, 248, 249, 250);
    $borderColor = imagecolorallocate($image, 222, 226, 230);
    $textColor = imagecolorallocate($image, 108, 117, 125);
    
    // Fill background
    imagefill($image, 0, 0, $backgroundColor);
    
    // Draw border
    imagerectangle($image, 0, 0, $width-1, $height-1, $borderColor);
    
    // Add text
    $text = 'PDF';
    $font = 5; // Built-in font
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $x = ($width - $textWidth) / 2;
    $y = ($height - $textHeight) / 2;
    
    imagestring($image, $font, $x, $y, $text, $textColor);
    
    // Output image
    imagejpeg($image, null, 85);
    imagedestroy($image);
}
?>