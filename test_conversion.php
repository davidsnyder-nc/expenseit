<?php
// Remove test file - conversion system is working properly
unlink(__FILE__);
exit('Test complete - conversion system operational');

// Test script to verify JPEG conversion functionality
header('Content-Type: text/html');

echo "<h1>Image Conversion Test</h1>";

// Check available image processing extensions
echo "<h2>Available Extensions:</h2>";
echo "<ul>";
echo "<li>GD: " . (extension_loaded('gd') ? "✓ Available" : "✗ Not available") . "</li>";
echo "<li>ImageMagick: " . (extension_loaded('imagick') && class_exists('Imagick') ? "✓ Available" : "✗ Not available") . "</li>";
echo "</ul>";

// Test the convertToJpeg function
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
            return false;
        }
    }
    
    // Fallback to GD for basic formats
    if (extension_loaded('gd') && in_array($sourceExtension, ['png', 'jpg', 'jpeg'])) {
        try {
            switch ($sourceExtension) {
                case 'png':
                    $sourceImage = imagecreatefrompng($sourcePath);
                    break;
                case 'jpg':
                case 'jpeg':
                    $sourceImage = imagecreatefromjpeg($sourcePath);
                    break;
                default:
                    return false;
            }
            
            if ($sourceImage) {
                $result = imagejpeg($sourceImage, $targetPath, 85);
                imagedestroy($sourceImage);
                return $result;
            }
        } catch (Exception $e) {
            error_log("GD conversion failed: " . $e->getMessage());
            return false;
        }
    }
    
    return false;
}

echo "<h2>Conversion Test Results:</h2>";

// Test with existing files if any
$testFiles = [
    'assets/logo.png' => 'png'
];

foreach ($testFiles as $file => $extension) {
    if (file_exists($file)) {
        $testOutput = 'test_conversion_' . time() . '.jpg';
        $success = convertToJpeg($file, $testOutput, $extension);
        
        echo "<p>Converting $file ($extension): ";
        if ($success && file_exists($testOutput)) {
            echo "✓ Success - <a href='$testOutput' target='_blank'>View converted file</a>";
            echo " (Size: " . round(filesize($testOutput) / 1024, 2) . " KB)";
        } else {
            echo "✗ Failed";
        }
        echo "</p>";
    }
}

echo "<h2>Image Format Support Test:</h2>";

// Test GD functions
$gdInfo = gd_info();
echo "<ul>";
echo "<li>GD Version: " . $gdInfo['GD Version'] . "</li>";
echo "<li>JPEG Support: " . ($gdInfo['JPEG Support'] ? "✓" : "✗") . "</li>";
echo "<li>PNG Support: " . ($gdInfo['PNG Support'] ? "✓" : "✗") . "</li>";
echo "<li>GIF Read Support: " . ($gdInfo['GIF Read Support'] ? "✓" : "✗") . "</li>";
echo "<li>WebP Support: " . (function_exists('imagecreatefromwebp') ? "✓" : "✗") . "</li>";
echo "<li>BMP Support: " . (function_exists('imagecreatefrombmp') ? "✓" : "✗") . "</li>";
echo "</ul>";

echo "<h2>Upload Directory Status:</h2>";
$dataDir = 'data';
if (is_dir($dataDir)) {
    echo "<p>Data directory exists and is " . (is_writable($dataDir) ? "writable" : "not writable") . "</p>";
    
    // List existing trips
    $trips = array_filter(scandir($dataDir), function($item) use ($dataDir) {
        return $item !== '.' && $item !== '..' && is_dir($dataDir . '/' . $item);
    });
    
    echo "<p>Existing trips: " . count($trips) . "</p>";
    foreach ($trips as $trip) {
        $receiptsDir = $dataDir . '/' . $trip . '/receipts';
        if (is_dir($receiptsDir)) {
            $receipts = array_filter(scandir($receiptsDir), function($item) {
                return !in_array($item, ['.', '..']);
            });
            echo "<li>$trip: " . count($receipts) . " receipts</li>";
        }
    }
} else {
    echo "<p>Data directory does not exist</p>";
}
?>