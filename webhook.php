<?php
require_once 'functions/pos.php';
require_once 'functions/notifications.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $branch_id = get_current_branch();
    $personnel_id = $_SESSION['personnel_id'] ?? 1; // Varsayılan personel
    $shift_id = get_active_shift($branch_id)['id'] ?? null;
    
    if (!$shift_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Aktif vardiya bulunamadı']);
        exit;
    }
    
    $customer_id = $data['customer_id'] ?? 1; // Varsayılan müşteri
    $products = $data['products'] ?? [];
    $payment_type = $data['payment_type'] ?? 'credit_card';
    
    $result = process_sale($branch_id, $personnel_id, $shift_id, $customer_id, $products, $payment_type);
    if ($result) {
        http_response_code(200);
        echo json_encode(['success' => true, 'sale_id' => $result]);
        add_notification("Webhook satışı işlendi: Satış ID $result", 'success', $branch_id);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Satış işlemi başarısız']);
    }
}
?>