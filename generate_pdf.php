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
                    'isImage' => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'])
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
    
    foreach ($expenses as $expense) {
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
    $html = buildPDFHTML($metadata, $expenses, $categories, $total, $receipts);
    
    // Write HTML to PDF
    $mpdf->WriteHTML($html);
    
    return $mpdf->Output('', 'S');
}

/**
 * Build HTML content for PDF
 */
function buildPDFHTML($metadata, $expenses, $categories, $total, $receipts = []) {
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
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
            }
            .summary-card {
                text-align: center;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 1px solid #e9ecef;
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
        
        <div class="summary-grid">
            <div class="summary-card">
                <h3>Duration</h3>
                <div class="value">' . $duration . '</div>
            </div>
            <div class="summary-card">
                <h3>Expenses</h3>
                <div class="value">' . count($expenses) . ' items</div>
            </div>
            <div class="summary-card">
                <h3>Total Amount</h3>
                <div class="value total">$' . number_format($total, 2) . '</div>
            </div>
        </div>';
    
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
        
        // Group expenses by category for detailed view
        $expensesByCategory = [];
        foreach ($expenses as $expense) {
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
            
            // Embed images in PDF, show filename for PDFs
            if ($receipt['isImage']) {
                $imageData = base64_encode(file_get_contents($receipt['path']));
                $extension = strtolower(pathinfo($receipt['filename'], PATHINFO_EXTENSION));
                $mimeType = $extension === 'png' ? 'image/png' : 'image/jpeg';
                
                $html .= '
                    <div class="receipt-image">
                        <img src="data:' . $mimeType . ';base64,' . $imageData . '" style="max-width: 200px; max-height: 150px; margin-top: 10px;">
                    </div>';
            } else {
                $html .= '
                    <div class="receipt-file">
                        <p style="color: #666; font-style: italic; margin-top: 10px;">PDF attachment: ' . htmlspecialchars($receipt['filename']) . '</p>
                    </div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '
            </div>
        </div>';
    }
    
    $html .= '
        <div class="footer">
            <p>Generated on ' . date('F j, Y \a\t g:i A') . ' by Expense Wizard</p>
        </div>
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
 * Format file size for display
 */
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round(($bytes / pow($k, $i)), 2) . ' ' . $sizes[$i];
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
