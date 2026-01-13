<?php
// functions/pos.php



// Start a new shift
function start_shift($branch_id, $personnel_id) {
    global $db;
    error_log("start_shift: Called with branch_id: $branch_id, personnel_id: $personnel_id");
    
    // Mevcut aktif vardiyayı kontrol et
    $existing_shift = get_active_shift($branch_id, $personnel_id);
    if ($existing_shift) {
        error_log("start_shift: Active shift already exists, shift_id: {$existing_shift['id']}");
        return $existing_shift['id'];
    }

    // Yeni vardiya ekle (kullanıcınızın şemasına göre shift_type, opening_balance vb. alanlar eklendi)
    $shift_type = 'morning'; // Loglarınızdan gelen varsayılan değer
    $opening_balance = null;
    $closing_balance = 0.00;
    $opened_at = date('Y-m-d H:i:s');
    $start_time = date('Y-m-d H:i:s');
    $query = "INSERT INTO shifts (branch_id, personnel_id, shift_type, opening_balance, closing_balance, status, opened_at, start_time) VALUES (?, ?, ?, ?, ?, 'open', ?, ?)";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        error_log("start_shift: Prepare failed: " . $db->error);
        return false;
    }
    $stmt->bind_param("iisddss", $branch_id, $personnel_id, $shift_type, $opening_balance, $closing_balance, $opened_at, $start_time);
    if ($stmt->execute()) {
        $shift_id = $db->insert_id;
        error_log("start_shift: Shift created, shift_id: $shift_id");
        // Kayıt doğrulama
        $verify_query = "SELECT * FROM shifts WHERE id = $shift_id";
        $verify_result = $db->query($verify_query);
        error_log("start_shift: Verification query result: " . json_encode($verify_result->fetch_assoc()));
        return $shift_id;
    } else {
        error_log("start_shift: Execute failed: " . $stmt->error);
        return false;
    }
}

// End an active shift
function end_shift($shift_id) {
    global $db;
    error_log("end_shift: Called with shift_id: $shift_id");
    $closed_at = date('Y-m-d H:i:s');
    $end_time = date('Y-m-d H:i:s');
    $query = "UPDATE shifts SET closed_at = ?, end_time = ?, status = 'closed' WHERE id = ?";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        error_log("end_shift: Prepare failed: " . $db->error);
        return false;
    }
    $stmt->bind_param("ssi", $closed_at, $end_time, $shift_id);
    if ($stmt->execute()) {
        error_log("end_shift: Shift ended, shift_id: $shift_id");
        return true;
    } else {
        error_log("end_shift: Execute failed: " . $stmt->error);
        return false;
    }
}

// Check if personnel is on break
function is_on_break($personnel_id) {
    $on_break = isset($_SESSION['on_break']) && $_SESSION['on_break'] === true;
    error_log("is_on_break: personnel_id: $personnel_id, on_break: " . ($on_break ? 'true' : 'false'));
    return $on_break;
}

// Start break mode
function start_break($personnel_id) {
    $_SESSION['on_break'] = true;
    error_log("start_break: Break started for personnel_id: $personnel_id");
}

// End break mode
function end_break($personnel_id) {
    unset($_SESSION['on_break']);
    error_log("end_break: Break ended for personnel_id: $personnel_id");
}
?>