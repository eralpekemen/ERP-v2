<?php
require_once 'functions/notifications.php';
require_once 'config.php';

// TCMB’den kurları çeken fonksiyon
function get_exchange_rates() {
    global $db;
    
    $cache_file = 'cache/exchange_rates.json';
    $cache_duration = 3600;
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
        return json_decode(file_get_contents($cache_file), true);
    }
    
    $url = 'https://www.tcmb.gov.tr/kurlar/today.xml';
    $xml = @file_get_contents($url);
    if ($xml === false) {
        return ['USD' => 34.0, 'EUR' => 36.0];
    }
    
    $rates = ['TL' => 1.0];
    $xml = simplexml_load_string($xml);
    foreach ($xml->Currency as $currency) {
        if ((string)$currency['CurrencyCode'] == 'USD') {
            $rates['USD'] = floatval($currency->ForexSelling);
        }
        if ((string)$currency['CurrencyCode'] == 'EUR') {
            $rates['EUR'] = floatval($currency->ForexSelling);
        }
    }
    
    if (!is_dir('cache')) {
        mkdir('cache', 0755, true);
    }
    file_put_contents($cache_file, json_encode($rates));
    
    return $rates;
}

// Tutarı TL’ye çeviren fonksiyon
function convert_to_tl($amount, $currency) {
    $rates = get_exchange_rates();
    return $amount * ($rates[$currency] ?? 1.0);
}

// Promosyon kodu doğrulama
function validate_promo_code($code, $branch_id) {
    global $db;
    $query = "SELECT discount_percent FROM promotions WHERE code = ? AND branch_id = ? AND valid_from <= NOW() AND valid_until >= NOW()";
    $stmt = $db->prepare($query);
    $stmt->bind_param("si", $code, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['discount_percent'] : 0;
}

function process_sale($branch_id, $personnel_id, $shift_id, $customer_id, $products, $payment_type, $commission_rate, $promo_code = '') {
    global $db;
    
    if (!$shift_id) {
        return false;
    }
    
    $subtotal = 0;
    $low_stock_products = [];
    foreach ($products as $product) {
        $query = "SELECT unit_price, stock_quantity FROM products WHERE id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $product['id'], $branch_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['stock_quantity'] < $product['quantity']) {
            return false;
        }
        if ($result['stock_quantity'] - $product['quantity'] <= 10) {
            $low_stock_products[] = $product['id'];
        }
        $subtotal += $result['unit_price'] * $product['quantity'];
    }
    
    $discount_percent = validate_promo_code($promo_code, $branch_id);
    $discount = $subtotal * ($discount_percent / 100);
    $subtotal -= $discount;
    $tax = $subtotal * 0.06;
    $commission = $subtotal * ($commission_rate / 100);
    $total = $subtotal + $tax + $commission;
    
    $query = "SELECT currency FROM payment_methods WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $payment_type);
    $stmt->execute();
    $currency = $stmt->get_result()->fetch_assoc()['currency'] ?? 'TL';
    $total = convert_to_tl($total, $currency);
    
    $query = "INSERT INTO sales (branch_id, personnel_id, shift_id, customer_id, payment_type, subtotal, tax, commission, total, sale_date, discount) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iiiisdddd", $branch_id, $personnel_id, $shift_id, $customer_id, $payment_type, $subtotal, $tax, $commission, $total, $discount);
    
    if ($stmt->execute()) {
        $sale_id = $db->insert_id;
        
        foreach ($products as $product) {
            $query = "SELECT unit_price, stock_quantity FROM products WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $product['id'], $branch_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            $query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iiid", $sale_id, $product['id'], $product['quantity'], $result['unit_price']);
            $stmt->execute();
            
            $query = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $product['quantity'], $product['id'], $branch_id);
            $stmt->execute();
        }
        
        // Düşük stok uyarısı
        if (!empty($low_stock_products)) {
            $query = "SELECT name FROM products WHERE id IN (" . implode(',', array_fill(0, count($low_stock_products), '?')) . ")";
            $stmt = $db->prepare($query);
            $stmt->bind_param(str_repeat('i', count($low_stock_products)), ...$low_stock_products);
            $stmt->execute();
            $low_stock_names = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $message = "Düşük stok uyarısı: " . implode(', ', array_column($low_stock_names, 'name'));
            add_notification($message, 'warning', $branch_id);
        }
        
        return $sale_id;
    }
    return false;
}
function add_open_account($customer_id, $sale_id, $branch_id, $amount) {
    global $db;
    
    $due_date = date('Y-m-d', strtotime('+30 days')); // Varsayılan 30 gün vade
    $query = "INSERT INTO open_accounts (customer_id, sale_id, branch_id, amount_due, due_date, status) 
              VALUES (?, ?, ?, ?, ?, 'open')";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iiids", $customer_id, $sale_id, $branch_id, $amount, $due_date);
    if ($stmt->execute()) {
        $open_account_id = $db->insert_id;
        log_action('open_account_added', "Müşteri ID: $customer_id, Satış ID: $sale_id, Tutar: $amount TL");
        return $open_account_id;
    }
    return false;
}

function process_open_account_payment($open_account_id, $branch_id, $personnel_id, $amount_paid, $payment_type) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', $branch_id);
        return false;
    }
    
    $query = "SELECT customer_id, amount_due FROM open_accounts WHERE id = ? AND branch_id = ? AND status = 'open'";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $open_account_id, $branch_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account) {
        add_notification("Geçersiz veya kapalı açık hesap!", 'error', $branch_id);
        return false;
    }
    
    if ($amount_paid > $account['amount_due']) {
        add_notification("Ödeme tutarı borç miktarını aşamaz!", 'error', $branch_id);
        return false;
    }
    
    $db->begin_transaction();
    try {
        $query = "INSERT INTO open_account_payments (open_account_id, shift_id, amount_paid, payment_type) 
                  VALUES (?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $shift_id = get_active_shift($branch_id)['id'];
        $stmt->bind_param("iids", $open_account_id, $shift_id, $amount_paid, $payment_type);
        $stmt->execute();
        
        $new_amount_due = $account['amount_due'] - $amount_paid;
        if ($new_amount_due <= 0) {
            $query = "UPDATE open_accounts SET amount_due = 0, status = 'paid', paid_at = NOW() WHERE id = ?";
        } else {
            $query = "UPDATE open_accounts SET amount_due = ? WHERE id = ?";
        }
        $stmt = $db->prepare($query);
        if ($new_amount_due <= 0) {
            $stmt->bind_param("i", $open_account_id);
        } else {
            $stmt->bind_param("di", $new_amount_due, $open_account_id);
        }
        $stmt->execute();
        
        check_debt_limit($account['customer_id'], $branch_id, 0);
        
        $db->commit();
        add_notification("Açık hesap ödemesi başarıyla işlendi: $amount_paid TL", 'success', $branch_id);
        log_action('open_account_payment_processed', "Açık Hesap ID: $open_account_id, Tutar: $amount_paid TL");
        return true;
    } catch (Exception $e) {
        $db->rollback();
        add_notification("Ödeme işlemi başarısız: " . $e->getMessage(), 'error', $branch_id);
        return false;
    }
}

function check_debt_limit($customer_id, $branch_id, $new_debt) {
    global $db;
    
    $query = "SELECT debt_limit, debt_limit_enabled, 
              COALESCE(SUM(amount_due), 0) as total_debt 
              FROM customers c 
              LEFT JOIN open_accounts oa ON c.id = oa.customer_id AND oa.status = 'open' 
              WHERE c.id = ? AND c.branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $customer_id, $branch_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    
    if ($customer['debt_limit_enabled'] && ($customer['total_debt'] + $new_debt) > $customer['debt_limit']) {
        add_notification("Müşteri borç limiti aşıldı: {$customer['total_debt']} TL / {$customer['debt_limit']} TL", 'error', $branch_id);
        return false;
    }
    return true;
}
?>