<?php
require_once 'functions/notifications.php';
require_once 'functions/points.php';
require_once 'functions/production.php';
require_once 'functions/payments.php';

function process_return($sale_id, $personnel_id, $amount, $payment_type, $reason, $return_type) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', get_current_branch());
        log_action('return_failed', "CSRF hatası, Satış ID: $sale_id");
        return false;
    }
    
    if (!is_shift_supervisor($personnel_id)) {
        add_notification("İade işlemi için yetkiniz yok!", 'error', get_current_branch());
        log_action('return_failed', "Yetkisiz iade denemesi, Personel ID: $personnel_id, Satış ID: $sale_id");
        return false;
    }
    
    if (empty(trim($reason))) {
        add_notification("İade sebebi zorunludur!", 'error', get_current_branch());
        log_action('return_failed', "Sebep eksik, Satış ID: $sale_id");
        return false;
    }
    
    $query = "SELECT branch_id, shift_id, customer_id, total_amount, payment_type, return_status 
              FROM sales WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    if (!$sale) {
        add_notification("Geçersiz satış ID!", 'error', get_current_branch());
        log_action('return_failed', "Geçersiz satış ID: $sale_id");
        return false;
    }
    
    $query = "SELECT id FROM shifts WHERE id = ? AND status = 'open' AND branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $sale['shift_id'], $sale['branch_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        add_notification("Aktif vardiya bulunamadı veya şube uyumsuz!", 'error', $sale['branch_id']);
        log_action('return_failed', "Vardiya kapalı veya uyumsuz, Satış ID: $sale_id");
        return false;
    }
    
    if ($amount <= 0 || $amount > $sale['total_amount']) {
        add_notification("İade tutarı geçersiz veya satış tutarını aşamaz!", 'error', $sale['branch_id']);
        log_action('return_failed', "Geçersiz iade tutarı: $amount, Satış ID: $sale_id");
        return false;
    }
    
    if ($payment_type != $sale['payment_type']) {
        add_notification("İade ödeme türü, satış ödeme türüyle uyuşmuyor!", 'error', $sale['branch_id']);
        log_action('return_failed', "Ödeme türü uyumsuz, Satış ID: $sale_id, İade Türü: $payment_type");
        return false;
    }
    
    if ($sale['return_status'] == 'full') {
        add_notification("Bu satış zaten tam iade edilmiş!", 'error', $sale['branch_id']);
        log_action('return_failed', "Tam iade mevcut, Satış ID: $sale_id");
        return false;
    }
    
    $remaining_amount = $sale['total_amount'];
    if ($sale['return_status'] == 'partial') {
        $query = "SELECT SUM(amount) as total_returned FROM returns WHERE sale_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $returned = $stmt->get_result()->fetch_assoc();
        $remaining_amount -= $returned['total_returned'] ?? 0;
    }
    if ($amount > $remaining_amount) {
        add_notification("İade tutarı kalan tutarı aşamaz!", 'error', $sale['branch_id']);
        log_action('return_failed', "Kalan tutar aşımı: $amount, Satış ID: $sale_id");
        return false;
    }
    
    $db->begin_transaction();
    try {
        $query = "INSERT INTO returns (sale_id, shift_id, personnel_id, branch_id, amount, payment_type, reason, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iiiidss", $sale_id, $sale['shift_id'], $personnel_id, $sale['branch_id'], $amount, $payment_type, $reason);
        $stmt->execute();
        $return_id = $db->insert_id;
        
        $return_status = ($amount == $remaining_amount) ? 'full' : 'partial';
        $query = "UPDATE sales SET return_status = ?, return_id = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("sii", $return_status, $return_id, $sale_id);
        $stmt->execute();
        
        $query = "INSERT INTO cash_registers (shift_id, sale_id, amount, transaction_type, payment_type, return_reason) 
                  VALUES (?, ?, ?, 'refund', ?, ?)";
        $stmt = $db->prepare($query);
        $negative_amount = -$amount;
        $stmt->bind_param("iids", $sale['shift_id'], $sale_id, $negative_amount, $payment_type, $reason);
        $stmt->execute();
        
        if ($sale['payment_type'] == 'open_account') {
            if (!update_open_account_for_return($sale_id, $amount)) {
                throw new Exception("Açık hesap güncelleme hatası!");
            }
            check_debt_limit($sale['customer_id'], $sale['branch_id'], 0);
        }
        
        if (!update_stock_from_recipe($sale_id, -$amount)) {
            throw new Exception("Stok güncelleme hatası!");
        }
        
        if (!reverse_points($sale_id, $amount)) {
            throw new Exception("Puan düşme hatası!");
        }
        
        $db->commit();
        
        add_notification("İade başarıyla işlendi: $amount TL, Sebep: $reason", 'success', $sale['branch_id']);
        log_action('return_processed', "Satış ID: $sale_id, Tutar: $amount TL, Sebep: $reason, Personel ID: $personnel_id");
        return $return_id;
    } catch (Exception $e) {
        $db->rollback();
        add_notification("İade işlemi başarısız: " . $e->getMessage(), 'error', $sale['branch_id']);
        log_action('return_failed', "Hata: {$e->getMessage()}, Satış ID: $sale_id");
        return false;
    }
}

function is_shift_supervisor($personnel_id) {
    global $db;
    $query = "SELECT id FROM personnel 
              WHERE id = ? AND role_id = (SELECT id FROM personnel_roles WHERE name = 'Vardiya Sorumlusu')";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function reverse_points($sale_id, $amount) {
    global $db;
    
    $query = "SELECT created_by, total_amount FROM sales WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    if (!$sale) return false;
    
    $ratio = $amount / $sale['total_amount'];
    
    $query = "SELECT product_id, quantity FROM sales_items WHERE sale_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($items as $item) {
        $query = "SELECT point_value FROM products WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $item['product_id']);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $points_to_reverse = $product['point_value'] * $item['quantity'] * $ratio;
        
        $query = "UPDATE personnel_points 
                  SET points = points - ? 
                  WHERE personnel_id = ? AND sale_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("dii", $points_to_reverse, $sale['created_by'], $sale_id);
        if (!$stmt->execute()) return false;
    }
    
    log_action('points_reversed', "Satış ID: $sale_id, Düşülen Puan: $points_to_reverse");
    return true;
}

function update_open_account_for_return($sale_id, $amount) {
    global $db;
    
    $query = "SELECT customer_id, amount_due FROM open_accounts WHERE sale_id = ? AND status = 'open'";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account) return true;
    
    $new_amount_due = $account['amount_due'] - $amount;
    if ($new_amount_due <= 0) {
        $query = "UPDATE open_accounts SET amount_due = 0, status = 'paid', paid_at = NOW() WHERE sale_id = ?";
    } else {
        $query = "UPDATE open_accounts SET amount_due = ? WHERE sale_id = ?";
    }
    $stmt = $db->prepare($query);
    if ($new_amount_due <= 0) {
        $stmt->bind_param("i", $sale_id);
    } else {
        $stmt->bind_param("di", $new_amount_due, $sale_id);
    }
    $result = $stmt->execute();
    
    if ($result) {
        log_action('open_account_updated', "Satış ID: $sale_id, İade Tutarı: $amount, Yeni Borç: $new_amount_due");
    }
    return $result;
}

function update_stock_from_recipe($sale_id, $amount) {
    global $db;
    
    $query = "SELECT product_id, quantity, total_amount FROM sales_items si 
              JOIN sales s ON si.sale_id = s.id WHERE si.sale_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($items as $item) {
        $ratio = $amount / $item['total_amount'];
        $return_quantity = $item['quantity'] * $ratio;
        
        $query = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("di", $return_quantity, $item['product_id']);
        if (!$stmt->execute()) return false;
        
        $query = "UPDATE production SET quantity = quantity - ? WHERE sale_id = ? AND status = 'pending'";
        $stmt = $db->prepare($query);
        $stmt->bind_param("di", $return_quantity, $sale_id);
        $stmt->execute();
    }
    
    log_action('stock_updated', "Satış ID: $sale_id, İade Tutarı: $amount");
    return true;
}
?>