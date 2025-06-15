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
    
    // Calculate totals and exclude excluded expenses
    $total = 0;
    $taxTotal = 0;
    $includedExpenseCount = 0;
    $categories = [];
    
    foreach ($expenses as $expense) {
        // Skip excluded expenses
        if ($expense['excluded'] ?? false) {
            continue;
        }
        
        $amount = floatval($expense['amount'] ?? 0);
        $taxAmount = floatval($expense['tax_amount'] ?? 0);
        $category = $expense['category'] ?? 'Uncategorized';
        
        $total += $amount;
        $taxTotal += $taxAmount;
        $includedExpenseCount++;
        
        if (!isset($categories[$category])) {
            $categories[$category] = 0;
        }
        $categories[$category] += $amount;
    }
    
    // Format dates
    $startDate = formatDate($metadata['start_date'] ?? '');
    $endDate = formatDate($metadata['end_date'] ?? '');
    $duration = !empty($startDate) && !empty($endDate) ? $startDate . ' - ' . $endDate : 'Not specified';
    
    // Generate HTML
    $html = generatePDFHTML($metadata, $expenses, $total, $taxTotal, $includedExpenseCount, $categories, $duration, $startDate, $endDate);
    
    // Create mPDF instance
    $mpdf = new Mpdf([
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 16,
        'margin_bottom' => 16,
        'margin_header' => 9,
        'margin_footer' => 9
    ]);
    
    // Write HTML to PDF
    $mpdf->WriteHTML($html);
    
    // Set the PDF filename
    $filename = sanitizeName($metadata['name']) . '_expense_report.pdf';
    
    // Output the PDF
    $mpdf->Output($filename, 'I'); // 'I' for inline display

} catch (Exception $e) {
    // Handle errors
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error generating PDF: ' . $e->getMessage();
}

function generatePDFHTML($metadata, $expenses, $total, $taxTotal, $includedExpenseCount, $categories, $duration, $startDate, $endDate) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 0;
                color: #333;
                line-height: 1.4;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #e53e3e;
                padding-bottom: 20px;
            }
            .header h1 {
                margin: 0;
                color: #e53e3e;
                font-size: 24px;
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
                padding: 8px;
                text-align: left;
                border-bottom: 1px solid #e9ecef;
            }
            .expenses-table th {
                background: #f8f9fa;
                font-weight: bold;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
            }
            .expenses-table .amount {
                text-align: right;
                font-weight: bold;
            }
            .category-header {
                background: #e2e8f0;
                font-weight: bold;
            }
            .category-total {
                background: #f1f5f9;
                font-weight: bold;
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
            <div class="subtitle">' . $duration . '</div>
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
    
    // Add category summary if we have categories
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
            <h2>Detailed Expenses</h2>
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
        
        // Group expenses by category for detailed view (exclude excluded expenses)
        $expensesByCategory = [];
        foreach ($expenses as $expense) {
            // Skip excluded expenses from detailed view
            if ($expense['excluded'] ?? false) {
                continue;
            }
            
            $category = $expense['category'] ?? 'Uncategorized';
            if (!isset($expensesByCategory[$category])) {
                $expensesByCategory[$category] = [];
            }
            $expensesByCategory[$category][] = $expense;
        }
        
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
                
                // Add daily breakdown for hotel stays
                if (($expense['is_hotel_stay'] ?? false) && !empty($expense['daily_breakdown'])) {
                    foreach ($expense['daily_breakdown'] as $day) {
                        $html .= '
                    <tr style="background: #f8f9fa; font-size: 11px;">
                        <td style="padding-left: 20px;">' . formatDate($day['date'] ?? '') . '</td>
                        <td style="padding-left: 20px;">└ Daily Rate</td>
                        <td style="padding-left: 20px;">Room: $' . number_format(floatval($day['room_rate'] ?? 0), 2) . 
                        ' + Tax: $' . number_format(floatval($day['tax_rate'] ?? 0), 2) . 
                        ' (' . htmlspecialchars($day['tax_percentage'] ?? '') . ')</td>
                        <td class="amount">$' . number_format(floatval($day['daily_total'] ?? 0), 2) . '</td>
                    </tr>';
                    }
                }
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
    
    $html .= '
        <div class="footer">
            <p>Generated by expense.it on ' . date('Y-m-d H:i:s') . '</p>
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
        $timestamp = strtotime($date);
        return $timestamp ? date('M j, Y', $timestamp) : $date;
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Sanitize name for file paths
 */
function sanitizeName($name) {
    // Remove any characters that could be problematic in file paths
    $name = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $name);
    $name = preg_replace('/\s+/', '_', $name);
    $name = trim($name, '_');
    return $name;
}
?>