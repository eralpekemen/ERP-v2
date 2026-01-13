<?php
require_once 'functions/notifications.php';
require_once 'functions/payments.php';
require_once 'functions/points.php';
require_once 'functions/production.php';

function process_sale($branch_id, $personnel_id, $shift_id, $customer_id, $products, $payment_type) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', $branch_id);
        log_action('sale_failed', "CSRF hatası, Şube ID: $branch_id");
        return false;
    }
    
    $total_amount = 0;
    foreach ($products as $product) {
        $product_id = intval($product['id']);
        $quantity = floatval($product['quantity']);
        $query = "SELECT unit_price, stock_quantity FROM products WHERE id = ? AND stock_quantity >= ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("id", $product_id, $quantity);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if (!$result) {
            add_notification("Yetersiz stok veya geçersiz ürün: ID $product_id", 'error', $branch_id);
            return false;
        }
        $total_amount += $result['unit_price'] * $quantity;
    }
    
    if ($payment_type == 'open_account' && !check_debt_limit($customer_id, $branch_id, $total_amount)) {
        add_notification("Müşteri borç limiti aşıldı!", 'error', $branch_id);
        return false;
    }
    
    $db->begin_transaction();
    try {
        $query = "INSERT INTO sales (customer_id, total_amount, branch_id, created_by, shift_id, payment_type, order_type) 
                  VALUES (?, ?, ?, ?, ?, ?, 'in_store')";
        $stmt = $db->prepare($query);
        $stmt->bind_param("idiiis", $customer_id, $total_amount, $branch_id, $personnel_id, $shift_id, $payment_type);
        $stmt->execute();
        $sale_id = $db->insert_id;
        
        foreach ($products as $product) {
            $product_id = intval($product['id']);
            $quantity = floatval($product['quantity']);
            $query = "SELECT unit_price FROM products WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $unit_price = $stmt->get_result()->fetch_assoc()['unit_price'];
            
            $query = "INSERT INTO sales_items (sale_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iidd", $sale_id, $product_id, $quantity, $unit_price);
            $stmt->execute();
            
            $query = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("di", $quantity, $product_id);
            $stmt->execute();
        }
        
        $query = "INSERT INTO cash_registers (shift_id, sale_id, amount, transaction_type, payment_type)