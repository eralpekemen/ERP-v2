<?php
require_once 'functions/notifications.php';

function add_branch($name, $address) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', 1);
        log_action('branch_failed', "CSRF hatası, Şube: $name");
        return false;
    }
    
    $query = "INSERT INTO branches (name, address, status) VALUES (?, ?, 'active')";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ss", $name, $address);
    if ($stmt->execute()) {
        add_notification("Şube eklendi: $name", 'success', 1);
        log_action('branch_added', "Şube: $name");
        return true;
    }
    return false;
}

function is_main_company() {
    return $_SESSION['branch_id'] == 1; // Ana şirket ID varsayılan 1
}
?>