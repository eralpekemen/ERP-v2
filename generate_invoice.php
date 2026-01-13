<?php
session_start();
require_once 'config.php';
require_once 'functions/common.php';
require_once 'lib/tcpdf/tcpdf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax']) && $_POST['action'] == 'generate_invoice') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
        exit;
    }
    
    $customer_id = $_POST['customer_id'] ? intval($_POST['customer_id']) : 1;
    $payment_type = $_POST['payment_type'];
    $commission_rate = $_POST['commission_rate'] ?? 0;
    $currency = $_POST['currency'] ?? 'TL';
    $products = $_POST['products'] ?? [];
    
    if (empty($products)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Fatura için ürün ekleyin!']);
        exit;
    }
    
    // Müşteri bilgisi
    global $db;
    $query = "SELECT name FROM customers WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $customer_name = $customer['name'] ?? 'Muhtelif Müşteri';
    
    // Ödeme yöntemi bilgisi
    $query = "SELECT name FROM payment_methods WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $payment_type);
    $stmt->execute();
    $payment_method = $stmt->get_result()->fetch_assoc();
    $payment_name = $payment_method['name'] ?? 'Açık Hesap';
    
    // PDF oluştur
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Pine & Dine');
    $pdf->SetTitle('Fatura');
    $pdf->SetSubject('Satış Faturası');
    $pdf->SetHeaderData('', 0, 'Pine & Dine Fatura', 'Satış Faturası');
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->AddPage();
    
    $subtotal = 0;
    $html = '<h1>Fatura</h1>';
    $html .= '<p><strong>Müşteri:</strong> ' . htmlspecialchars($customer_name) . '</p>';
    $html .= '<p><strong>Ödeme Yöntemi:</strong> ' . htmlspecialchars($payment_name) . ' (' . $currency . ')</p>';
    $html .= '<p><strong>Tarih:</strong> ' . date('d.m.Y H:i:s') . '</p>';
    $html .= '<table border="1" cellpadding="4"><tr><th>Ürün</th><th>Adet</th><th>Birim Fiyat</th><th>Toplam</th></tr>';
    
    foreach ($products as $product) {
        $total = $product['price'] * $product['quantity'];
        $subtotal += $total;
        $html .= '<tr><td>' . htmlspecialchars($product['name']) . '</td><td>' . $product['quantity'] . '</td><td>' . number_format($product['price'], 2) . ' TL</td><td>' . number_format($total, 2) . ' TL</td></tr>';
    }
    
    $tax = $subtotal * 0.06;
    $commission = $subtotal * ($commission_rate / 100);
    $total = $subtotal + $tax + $commission;
    $total_converted = convert_to_tl($total, $currency);
    
    $html .= '</table>';
    $html .= '<p><strong>Alt Toplam:</strong> ' . number_format($subtotal, 2) . ' TL</p>';
    $html .= '<p><strong>Vergi (%6):</strong> ' . number_format($tax, 2) . ' TL</p>';
    $html .= '<p><strong>Komisyon:</strong> ' . number_format($commission, 2) . ' TL</p>';
    $html .= '<p><strong>Toplam:</strong> ' . number_format($total_converted, 2) . ' ' . $currency . '</p>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf_file = 'invoices/invoice_' . time() . '.pdf';
    if (!is_dir('invoices')) {
        mkdir('invoices', 0755, true);
    }
    $pdf->Output($pdf_file, 'F');
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Fatura oluşturuldu!', 'pdf_url' => $pdf_file]);
    exit;
}
?>