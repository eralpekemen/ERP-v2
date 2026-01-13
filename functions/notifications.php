<?php

function log_action($action, $details) {
    global $db;
    
    $query = "INSERT INTO logs (action, details, created_at) VALUES (?, ?, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ss", $action, $details);
    $stmt->execute();
}

function add_notification($message, $type = 'info', $branch_id = null, $personnel_id = null, $related_type = null, $related_id = null) {
    global $db;
    $query = "INSERT INTO notifications 
              (message, type, branch_id, personnel_id, related_type, related_id, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ssiiis", $message, $type, $branch_id, $personnel_id, $related_type, $related_id);
    return $stmt->execute();
}

function trigger_service_notification($branch_id) {
    add_notification("Servis talebi alındı!", "info", $branch_id);
}
?>