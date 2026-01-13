<?php
require_once 'functions/notifications.php';

function open_shift($branch_id, $cashier_id, $shift_type, $opening_balance) {
    global $db;
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', $branch_id);
        return false;
    }
    
    $query = "SELECT id FROM shifts WHERE branch_id = ? AND status = 'open'";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        add_notification("Zaten açık bir vardiya var!", 'error', $branch_id);
        return false;
    }
    
    $query = "INSERT INTO shifts (branch_id, personnel_id, shift_type, opening_balance, status, start_time) 
              VALUES (?, ?, ?, ?, 'open', NOW())";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iisd", $branch_id, $cashier_id, $shift_type, $opening_balance);
    if ($stmt->execute()) {
        $shift_id = $db->insert_id;
        add_notification("Vardiya açıldı: $shift_type", 'success', $branch_id);
        return $shift_id;
    }
    return false;
}

function close_shift($shift_id, $closing_balance) {
    global $db;
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', get_current_branch());
        return false;
    }
    
    $query = "SELECT branch_id, opening_balance FROM shifts WHERE id = ? AND status = 'open'";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $shift_id);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    
    if (!$shift) {
        add_notification("Geçersiz veya kapalı vardiya!", 'error', get_current_branch());
        return false;
    }
    
    $branch_id = $shift['branch_id'];
    $opening = $shift['opening_balance'];
    $difference = $closing_balance - $opening;
    
    $db->begin_transaction();
    try {
        $query = "UPDATE shifts SET status = 'closed', closing_balance = ?, closed_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("di", $closing_balance, $shift_id);
        $stmt->execute();
        
        // Günlük kapanış tetikle (daily_close.php entegrasyonu)
        process_daily_close($branch_id, $_SESSION['personnel_id'], date('Y-m-d'), $closing_balance, 0, 'Vardiya kapanış');

        $db->commit();
        add_notification("Vardiya kapatıldı: ID $shift_id, Fark: $difference TL", 'success', $branch_id);
        return true;
    } catch (Exception $e) {
        $db->rollback();
        add_notification("Vardiya kapatma başarısız: " . $e->getMessage(), 'error', $branch_id);
        return false;
    }
}

function is_shift_supervisor($personnel_id) {
    global $db;
    $query = "SELECT role_id FROM personnel WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    $role_id = $stmt->get_result()->fetch_assoc()['role_id'];
    return in_array($role_id, [1, 2]); // Varsayılan: 1 (Müdür), 2 (Vardiya Sorumlusu)
}

function start_shift($branch_id, $personnel_id) {
    global $db;
    
    // Aktif vardiya kontrolü
    $query = "SELECT id FROM shifts WHERE branch_id = ? AND end_time IS NULL";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        return false; // Zaten aktif vardiya var
    }
    
    // Yeni vardiya başlat
    $query = "INSERT INTO shifts (branch_id, personnel_id, start_time) VALUES (?, ?, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $branch_id, $personnel_id);
    return $stmt->execute();
}

function end_shift($shift_id) {
    global $db;
    
    // Vardiyayı kapat
    $query = "UPDATE shifts SET end_time = NOW() WHERE id = ? AND end_time IS NULL";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $shift_id);
    return $stmt->execute();
}

?>