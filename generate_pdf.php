<?php
require_once 'vendor/autoload.php';

use Mpdf\Mpdf;

header('Content-Type: application/pdf');
header('Access-Control-Allow-Origin: *');

try {
    $tripName = $_GET['trip'] ?? '';
    
    if (empty($tripName)) {
        throw new Exception('Trip name is required');
    }
    
    $tripName = sanitizeName($tripName);
    $tripDir = "data/trips/" . $tripName;
    
    // Check if trip exists
    if (!is_dir($tripDir)) {
        throw new Exception('Trip not found');
    }
    
    // Load trip data
    $metadataPath = $tripDir . "/metadata.json";
    $expensesPath = $tripDir . "/expenses.json";
    
    if (!file_exists($metadataPath)) {
        throw new Exception('Trip metadata not found');
    }
    
    $metadata = json_decode(file_get_contents($metadataPath), true);
    $expenses = file_exists($expensesPath) ? json_decode(file_get_contents($expensesPath), true) : [];
    
    // Load receipts
    $receiptsDir = $tripDir . "/receipts";
    $receipts = [];
    if (is_dir($receiptsDir)) {
        $files = glob($receiptsDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $receipts[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'isImage' => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'heic', 'tiff', 'tif'])
                ];
            }
        }
    }
    
    // Generate PDF
    $pdf = generateTripPDF($metadata, $expenses, $receipts);
    
    // Save PDF to trip directory
    $pdfPath = $tripDir . "/report.pdf";
    file_put_contents($pdfPath, $pdf);
    
    // Output PDF
    header('Content-Disposition: inline; filename="' . $tripName . '-report.pdf"');
    echo $pdf;
    
} catch (Exception $e) {
    // Return error as plain text
    header('Content-Type: text/plain');
    http_response_code(400);
    echo 'Error generating PDF: ' . $e->getMessage();
}

/**
 * Generate trip PDF report
 */
function generateTripPDF($metadata, $expenses, $receipts = []) {
    // Calculate totals
    $total = 0;
    $categories = [];
    $includedExpenseCount = 0;
    
    foreach ($expenses as $expense) {
        // Skip excluded expenses from totals and categories
        if ($expense['excluded'] ?? false) {
            continue;
        }
        
        $includedExpenseCount++;
        $amount = floatval($expense['amount'] ?? 0);
        $total += $amount;
        
        $category = $expense['category'] ?? 'Other';
        if (!isset($categories[$category])) {
            $categories[$category] = 0;
        }
        $categories[$category] += $amount;
    }
    
    // Sort categories by amount (descending)
    arsort($categories);
    
    // Create PDF
    $mpdf = new Mpdf([
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20
    ]);
    
    // Set document info
    $mpdf->SetTitle($metadata['name'] . ' - Expense Report');
    $mpdf->SetAuthor('Expense Wizard');
    
    // Build HTML content
    $html = buildPDFHTML($metadata, $expenses, $categories, $total, $receipts, $includedExpenseCount);
    
    // Write HTML to PDF
    $mpdf->WriteHTML($html);
    
    return $mpdf->Output('', 'S');
}

/**
 * Build HTML content for PDF
 */
function buildPDFHTML($metadata, $expenses, $categories, $total, $receipts = [], $includedExpenseCount = 0) {
    $startDate = formatDate($metadata['start_date'] ?? '');
    $endDate = formatDate($metadata['end_date'] ?? '');
    $duration = calculateDuration($metadata['start_date'] ?? '', $metadata['end_date'] ?? '');
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body {
                font-family: "Helvetica", "Arial", sans-serif;
                font-size: 12px;
                line-height: 1.4;
                color: #333;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #4299e1;
                padding-bottom: 20px;
            }
            .header h1 {
                color: #4299e1;
                font-size: 24px;
                margin: 0 0 10px 0;
            }
            .header .subtitle {
                color: #666;
                font-size: 14px;
                margin: 5px 0;
            }
            .summary-grid {
                width: 100%;
                border-collapse: separate;
                border-spacing: 10px;
                margin: 20px auto;
                table-layout: fixed;
            }
            .summary-card {
                text-align: center;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 1px solid #e9ecef;
                width: 33.33%;
            }
            .summary-card h3 {
                margin: 0 0 5px 0;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
            }
            .summary-card .value {
                font-size: 18px;
                font-weight: bold;
                color: #333;
            }
            .summary-card .total {
                color: #38a169;
            }
            .section {
                margin-bottom: 30px;
            }
            .section h2 {
                color: #4299e1;
                font-size: 16px;
                margin: 0 0 15px 0;
                padding-bottom: 5px;
                border-bottom: 1px solid #e9ecef;
            }
            .expenses-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .expenses-table th,
            .expenses-table td {
                padding: 8px 12px;
                text-align: left;
                border-bottom: 1px solid #e9ecef;
            }
            .expenses-table th {
                background: #f8f9fa;
                font-weight: bold;
                color: #666;
                font-size: 11px;
                text-transform: uppercase;
            }
            .expenses-table .amount {
                text-align: right;
                font-weight: bold;
            }
            .expenses-table .category-header {
                background: #4299e1;
                color: white;
                font-weight: bold;
            }
            .category-total {
                background: #ebf8ff;
                font-weight: bold;
            }
            .category-total .amount {
                color: #2b6cb0;
            }
            .notes {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                border-left: 4px solid #4299e1;
            }
            .notes h3 {
                margin: 0 0 10px 0;
                color: #4299e1;
            }
            .receipts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 12px;
                margin-top: 15px;
                max-width: 100%;
            }
            .receipt-attachment {
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 10px;
                background: #f8f9fa;
                text-align: center;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .receipt-info {
                margin-bottom: 8px;
                font-size: 10px;
            }
            .receipt-info strong {
                display: block;
                margin-bottom: 3px;
                color: #333;
                font-size: 11px;
            }
            .receipt-info small {
                color: #666;
                font-size: 9px;
            }
            .receipt-image img {
                border-radius: 4px;
                border: 1px solid #ddd;
                max-width: 100%;
                max-height: 200px;
                height: auto;
                object-fit: contain;
            }
            .receipt-file {
                padding: 15px;
                background: #fff;
                border: 2px dashed #ccc;
                border-radius: 4px;
                color: #666;
                font-size: 10px;
                min-height: 80px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .footer {
                margin-top: 40px;
                text-align: center;
                color: #666;
                font-size: 10px;
                border-top: 1px solid #e9ecef;
                padding-top: 15px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . htmlspecialchars($metadata['name']) . '</h1>
            <div class="subtitle">Expense Report</div>
            <div class="subtitle">' . $startDate . ' - ' . $endDate . '</div>
        </div>
        
        <table class="summary-grid">
            <tr>
                <td class="summary-card">
                    <h3>Duration</h3>
                    <div class="value">' . $duration . '</div>
                </td>
                <td class="summary-card">
                    <h3>Expenses</h3>
                    <div class="value">' . $includedExpenseCount . ' items</div>
                </td>
                <td class="summary-card">
                    <h3>Total Amount</h3>
                    <div class="value total">$' . number_format($total, 2) . '</div>
                </td>
            </tr>
        </table>';
    
    // Add notes section if exists
    if (!empty($metadata['notes'])) {
        $html .= '
        <div class="section">
            <div class="notes">
                <h3>Trip Notes</h3>
                <p>' . nl2br(htmlspecialchars($metadata['notes'])) . '</p>
            </div>
        </div>';
    }
    
    // Add category summary
    if (!empty($categories)) {
        $html .= '
        <div class="section">
            <h2>Expense Summary by Category</h2>
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="text-align: right;">Amount</th>
                        <th style="text-align: right;">Percentage</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($categories as $category => $amount) {
            $percentage = $total > 0 ? ($amount / $total) * 100 : 0;
            $html .= '
                    <tr>
                        <td>' . htmlspecialchars($category) . '</td>
                        <td class="amount">$' . number_format($amount, 2) . '</td>
                        <td class="amount">' . number_format($percentage, 1) . '%</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
        </div>';
    }
    
    // Add detailed expenses
    if (!empty($expenses)) {
        $html .= '
        <div class="section">
            <h2>Detailed Expenses</h2>';
        
        // Group expenses by category for detailed view (exclude excluded expenses)
        $expensesByCategory = [];
        foreach ($expenses as $expense) {
            // Skip excluded expenses from detailed view
            if ($expense['excluded'] ?? false) {
                continue;
            }
            
            $category = $expense['category'] ?? 'Other';
            if (!isset($expensesByCategory[$category])) {
                $expensesByCategory[$category] = [];
            }
            $expensesByCategory[$category][] = $expense;
        }
        
        // Sort by category total (descending)
        uksort($expensesByCategory, function($a, $b) use ($categories) {
            return ($categories[$b] ?? 0) <=> ($categories[$a] ?? 0);
        });
        
        $html .= '
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Merchant</th>
                        <th>Note</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($expensesByCategory as $category => $categoryExpenses) {
            $categoryTotal = array_sum(array_column($categoryExpenses, 'amount'));
            
            // Category header
            $html .= '
                    <tr class="category-header">
                        <td colspan="4">' . htmlspecialchars($category) . '</td>
                    </tr>';
            
            // Sort expenses by date
            usort($categoryExpenses, function($a, $b) {
                return strtotime($a['date'] ?? '') <=> strtotime($b['date'] ?? '');
            });
            
            // Category expenses
            foreach ($categoryExpenses as $expense) {
                $html .= '
                    <tr>
                        <td>' . formatDate($expense['date'] ?? '') . '</td>
                        <td>' . htmlspecialchars($expense['merchant'] ?? '') . '</td>
                        <td>' . htmlspecialchars($expense['note'] ?? '') . '</td>
                        <td class="amount">$' . number_format(floatval($expense['amount'] ?? 0), 2) . '</td>
                    </tr>';
            }
            
            // Category total
            $html .= '
                    <tr class="category-total">
                        <td colspan="3"><strong>' . htmlspecialchars($category) . ' Total</strong></td>
                        <td class="amount">$' . number_format($categoryTotal, 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
        </div>';
    }
    
    // Add receipts section if receipts exist
    if (!empty($receipts)) {
        $html .= '
        <div class="section">
            <h2>Receipt Attachments</h2>
            <div class="receipts-grid">';
        
        foreach ($receipts as $receipt) {
            $html .= '
                <div class="receipt-attachment">
                    <div class="receipt-info">
                        <strong>' . htmlspecialchars($receipt['filename']) . '</strong><br>
                        <small>' . formatFileSize($receipt['size']) . '</small>
                    </div>';
            
            // Embed images and create thumbnails for PDFs
            if ($receipt['isImage']) {
                $extension = strtolower(pathinfo($receipt['filename'], PATHINFO_EXTENSION));
                
                // For JPG/JPEG - embed directly
                if (in_array($extension, ['jpg', 'jpeg'])) {
                    $imageData = base64_encode(file_get_contents($receipt['path']));
                    
                    $html .= '
                        <div class="receipt-image">
                            <img src="data:image/jpeg;base64,' . $imageData . '" style="max-width: 400px; max-height: 300px; margin-top: 10px;">
                        </div>';
                }
            } else {
                // For PDFs, convert to image using multiple methods
                $thumbnailCreated = false;
                $thumbnailData = null;
                
                // Method 1: Try Imagick
                if (extension_loaded('imagick') && class_exists('Imagick')) {
                    try {
                        $imagick = new Imagick();
                        $imagick->setResolution(200, 200);
                        $imagick->readImage($receipt['path'] . '[0]'); // First page only
                        $imagick->setImageFormat('jpeg');
                        $imagick->setImageCompressionQuality(85);
                        $imagick->scaleImage(400, 300, true);
                        
                        $thumbnailData = base64_encode($imagick->getImageBlob());
                        $thumbnailCreated = true;
                        $imagick->clear();
                    } catch (Exception $e) {
                        error_log("Imagick PDF conversion failed: " . $e->getMessage());
                    }
                }
                
                // Method 2: Try Ghostscript if Imagick failed
                if (!$thumbnailCreated && function_exists('exec')) {
                    try {
                        $tempImagePath = sys_get_temp_dir() . '/pdf_thumb_' . uniqid() . '.jpg';
                        $escapedPdfPath = escapeshellarg($receipt['path']);
                        $escapedImagePath = escapeshellarg($tempImagePath);
                        
                        // Try gs (Ghostscript) command
                        $gsCommand = "gs -dNOPAUSE -dBATCH -sDEVICE=jpeg -dJPEGQ=85 -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile=$escapedImagePath $escapedPdfPath 2>/dev/null";
                        exec($gsCommand, $output, $returnCode);
                        
                        if ($returnCode === 0 && file_exists($tempImagePath)) {
                            // Resize the image
                            if (extension_loaded('gd')) {
                                $sourceImage = imagecreatefromjpeg($tempImagePath);
                                if ($sourceImage) {
                                    $sourceWidth = imagesx($sourceImage);
                                    $sourceHeight = imagesy($sourceImage);
                                    
                                    // Calculate new dimensions
                                    $maxWidth = 400;
                                    $maxHeight = 300;
                                    $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
                                    $newWidth = round($sourceWidth * $ratio);
                                    $newHeight = round($sourceHeight * $ratio);
                                    
                                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
                                    
                                    ob_start();
                                    imagejpeg($resizedImage, null, 85);
                                    $thumbnailData = base64_encode(ob_get_contents());
                                    ob_end_clean();
                                    
                                    imagedestroy($sourceImage);
                                    imagedestroy($resizedImage);
                                    $thumbnailCreated = true;
                                }
                            } else {
                                // Use original image if GD not available
                                $thumbnailData = base64_encode(file_get_contents($tempImagePath));
                                $thumbnailCreated = true;
                            }
                            unlink($tempImagePath);
                        }
                    } catch (Exception $e) {
                        error_log("Ghostscript PDF conversion failed: " . $e->getMessage());
                    }
                }
                
                if ($thumbnailCreated && $thumbnailData) {
                    $html .= '
                        <div class="receipt-image">
                            <img src="data:image/jpeg;base64,' . $thumbnailData . '" style="max-width: 400px; max-height: 300px; margin-top: 10px;">
                            <p style="font-size: 10px; color: #666; margin-top: 5px;">Converted from PDF</p>
                        </div>';
                } else {
                    $html .= '
                        <div class="receipt-file">
                            <div style="background: #f8f9fa; border: 2px dashed #dee2e6; padding: 20px; text-align: center; margin-top: 10px; border-radius: 8px;">
                                <div style="font-size: 24px; color: #6c757d; margin-bottom: 8px;">📄</div>
                                <div style="font-size: 12px; color: #495057; font-weight: bold;">PDF Document</div>
                                <div style="font-size: 10px; color: #6c757d; margin-top: 4px;">' . htmlspecialchars(basename($receipt['filename'])) . '</div>
                                <div style="font-size: 10px; color: #dc3545; margin-top: 4px;">Conversion to JPG failed</div>
                            </div>
                        </div>';
                }
            }
            
            $html .= '</div>';
        }
        
        $html .= '
            </div>
        </div>';
    }
    
    $html .= '
    </body>
    </html>';
    
    return $html;
}

/**
 * Format date for display
 */
function formatDate($date) {
    if (empty($date)) return '';
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format('M j, Y');
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Format file size for display
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Calculate trip duration
 */
function calculateDuration($startDate, $endDate) {
    if (empty($startDate) || empty($endDate)) {
        return 'Unknown';
    }
    
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $diff = $start->diff($end);
        
        $days = $diff->days + 1; // Include both start and end dates
        
        if ($days == 1) {
            return '1 day';
        } else {
            return $days . ' days';
        }
    } catch (Exception $e) {
        return 'Unknown';
    }
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
?>
