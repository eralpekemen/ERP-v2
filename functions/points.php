<?php
require_once 'functions/notifications.php';

function add_sale_points($sale_id, $products) {
    global $db;
    
    $query = "SELECT created_by FROM sales WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    if (!$sale) return false;
    
    $total_points = 0;
    foreach ($products as $product) {
        $product_id = intval($product['id']);
        $quantity = floatval($product['quantity']);
        $query = "SELECT p.point_value, pc.point_multiplier 
                  FROM products p 
                  JOIN product_categories pc ON p.category_id = pc.id 
                  WHERE p.id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $points = $result['point_value'] * $quantity * $result['point_multiplier'];
        $total_points += $points;
    }
    
    $query = "INSERT INTO personnel_points (personnel_id, sale_id, points) VALUES (?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iid", $sale['created_by'], $sale_id, $total_points);
    if ($stmt->execute()) {
        log_action('points_added', "Satış ID: $sale_id, Puan: $total_points");
        return true;
    }
    return false;
}
?>