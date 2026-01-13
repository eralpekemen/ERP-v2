<?php
require_once 'functions/notifications.php';
require_once 'functions/shifts.php';
require_once 'functions/common.php'; // log_action için değil, error_log için

function process_daily_close($branch_id, $personnel_id, $close_date, $cash_entered, $pos_entered, $note, $csrf_token = null) {
    global $db;
    
    // CSRF kontrolü (AJAX veya formdan gelen)
    if ($csrf_token === null && isset($_POST['csrf_token'])) {
        $csrf_token = $_POST['csrf_token'];
    }
    if ($csrf_token && !validate_csrf_token($csrf_token)) {  // DÜZELTME
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', $branch_id);
        error_log("daily_close_failed: CSRF hatası, Şube ID: $branch_id, Tarih: $close_date");
        return false;
    }
    
    // Yetki kontrolü
    if (!is_shift_supervisor($personnel_id)) {
        add_notification("Gün sonu işlemi için yetkiniz yok!", 'error', $branch_id);
        error_log("daily_close_failed: Yetkisiz deneme, Personel ID: $personnel_id, Şube ID: $branch_id");
        return false;
    }
    
    // Tüm vardiyalar kapalı mı?
    if (!validate_shifts_closed($branch_id, $close_date)) {
        add_notification("Tüm vardiyalar kapanmadan gün sonu yapılamaz!", 'error', $branch_id);
        error_log("daily_close_failed: Açık vardiya mevcut, Şube ID: $branch_id, Tarih: $close_date");
        return false;
    }
    
    // Sistem toplamlarını hesapla
    $totals = calculate_system_totals($branch_id, $close_date);
    $cash_system = $totals['cash_system'] ?? 0;
    $pos_system = $totals['pos_system'] ?? 0;
    
    $cash_difference = $cash_entered - $cash_system;
    $pos_difference = $pos_entered - $pos_system;
    
    $db->begin_transaction();
    try {
        $query = "INSERT INTO daily_closes 
                  (branch_id, personnel_id, close_date, cash_entered, pos_entered,
                   cash_system, pos_system, cash_difference, pos_difference, note, status, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iisddddddds", 
            $branch_id, $personnel_id, $close_date, 
            $cash_entered, $pos_entered,
            $cash_system, $pos_system, 
            $cash_difference, $pos_difference, $note
        );
        $stmt->execute();
        $daily_close_id = $db->insert_id;
        
        // Vardiyaları kilitle
        $query = "UPDATE shifts SET status = 'locked' 
                  WHERE branch_id = ? AND DATE(start_time) = ? AND status = 'closed'";
        $stmt = $db->prepare($query);
        $stmt->bind_param("is", $branch_id, $close_date);
        $stmt->execute();
        
        $db->commit();
        
        $message = "Gün sonu işlendi: $close_date | Nakit Fark: " . number_format($cash_difference, 2) . " TL | POS Fark: " . number_format($pos_difference, 2) . " TL";
        add_notification($message, 'success', $branch_id);
        error_log("daily_close_processed: $message");
        
        return $daily_close_id;
        
    } catch (Exception $e) {
        $db->rollback();
        $msg = "Gün sonu işlemi başarısız: " . $e->getMessage();
        add_notification($msg, 'error', $branch_id);
        error_log("daily_close_failed: $msg, Şube: $branch_id");
        return false;
    }
}

function calculate_system_totals($branch_id, $close_date) {
    global $db;
    
    // SATIŞLARDAN TOPLA (daha doğru)
    $query = "SELECT 
                COALESCE(SUM(CASE WHEN p.type = 'cash' THEN s.total ELSE 0 END), 0) AS cash_system,
                COALESCE(SUM(CASE WHEN p.type = 'credit_card' THEN s.total ELSE 0 END), 0) AS pos_system
              FROM sales s
              JOIN payment_details pd ON s.id = pd.sale_id
              JOIN payment_methods p ON pd.method_id = p.id
              WHERE s.branch_id = ? AND DATE(s.sale_date) = ?";
              
    $stmt = $db->prepare($query);
    $stmt->bind_param("is", $branch_id, $close_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return [
        'cash_system' => (float)($result['cash_system'] ?? 0),
        'pos_system' => (float)($result['pos_system'] ?? 0)
    ];
}

function validate_shifts_closed($branch_id, $close_date) {
    global $db;
    $query = "SELECT 1 FROM shifts 
              WHERE branch_id = ? AND DATE(start_time) = ? AND status = 'open' 
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bind_param("is", $branch_id, $close_date);
    $stmt->execute();
    return $stmt->get_result()->num_rows === 0;
}

// CRON İÇİN: Her gün saat 23:59'da çalıştır
function send_daily_close_reminder() {
    global $db;
    $current_date = date('Y-m-d');
    
    $query = "SELECT b.id, b.name FROM branches b
              WHERE NOT EXISTS (
                  SELECT 1 FROM daily_closes dc
                  WHERE dc.branch_id = b.id AND dc.close_date = ?
              )";
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $current_date);
    $stmt->execute();
    $branches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($branches as $branch) {
        add_notification("Gün sonu işlemi yapılmadı: {$branch['name']} - $current_date", 'warning', $branch['id']);
        error_log("daily_close_reminder: Şube {$branch['name']} ($current_date) gün sonu yapılmadı.");
    }
}
?>