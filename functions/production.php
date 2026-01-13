<?php
require_once 'functions/notifications.php';

function create_production_order($product_id, $branch_id, $quantity) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', $branch_id);
        log_action('production_failed', "CSRF hatası, Ürün ID: $product_id");
        return false;
    }
    
    $db->begin_transaction();
    try {
        $query = "INSERT INTO production (product_id, branch_id, quantity, status) VALUES (?, ?, ?, 'pending')";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iid", $product_id, $branch_id, $quantity);
        $stmt->execute();
        $production_id = $db->insert_id;
        
        $query = "SELECT ingredient_id, quantity FROM recipes WHERE product_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($ingredients as $ingredient) {
            $ingredient_quantity = $ingredient['quantity'] * $quantity;
            $query = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("di", $ingredient_quantity, $ingredient['ingredient_id']);
            $stmt->execute();
        }
        
        $db->commit();
        add_notification("Üretim emri oluşturuldu: Ürün ID $product_id, Miktar: $quantity", 'success', $branch_id);
        log_action('production_created', "Ürün ID: $product_id, Miktar: $quantity");
        return $production_id;
    } catch (Exception $e) {
        $db->rollback();
        add_notification("Üretim emri başarısız: " . $e->getMessage(), 'error', $branch_id);
        return false;
    }
}

function create_production_order_from_sale($sale_id) {
    global $db;
    
    $query = "SELECT si.product_id, si.quantity FROM sales_items si WHERE si.sale_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($items as $item) {
        $query = "SELECT branch_id FROM sales WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $branch_id = $stmt->get_result()->fetch_assoc()['branch_id'];
        
        create_production_order($item['product_id'], $branch_id, $item['quantity']);
    }
}

function add_recipe($product_id, $ingredients) {
    global $db;
    
    if (!verify_csrf_token($_POST['csrf_token'])) {
        add_notification("Güvenlik hatası: Geçersiz CSRF token!", 'error', get_current_branch());
        log_action('recipe_failed', "CSRF hatası, Ürün ID: $product_id");
        return false;
    }
    
    $db->begin_transaction();
    try {
        $query = "DELETE FROM recipes WHERE product_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        
        foreach ($ingredients as $ingredient) {
            $ingredient_id = intval($ingredient['id']);
            $quantity = floatval($ingredient['quantity']);
            $query = "INSERT INTO recipes (product_id, ingredient_id, quantity) VALUES (?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iid", $product_id, $ingredient_id, $quantity);
            $stmt->execute();
        }
        
        $db->commit();
        add_notification("Reçete kaydedildi: Ürün ID $product_id", 'success', get_current_branch());
        log_action('recipe_added', "Ürün ID: $product_id");
        return true;
    } catch (Exception $e) {
        $db->rollback();
        add_notification("Reçete ekleme başarısız: " . $e->getMessage(), 'error', get_current_branch());
        return false;
    }
}
?>