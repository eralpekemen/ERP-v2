<?php
require_once '../config.php';
require_once '../functions/common.php';
session_start();
if (!isset($_SESSION['personnel_id'])) exit(json_encode(['success'=>false]));

$branch_id = get_current_branch();
$action = $_POST['action'] ?? '';

if ($action === 'get_product_detail') {
    $id = (int)$_POST['product_id'];
    
    // Ana ürün
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
    $stmt->bind_param("ii", $id, $branch_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    // Ekstralar
    $stmt = $db->prepare("SELECT name, price FROM product_extras WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $extras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Boyutlar
    $stmt = $db->prepare("SELECT name, additional_price, stock_quantity FROM product_sizes WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $sizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Reçete
    $stmt = $db->prepare("SELECT ri.quantity, p.name AS ingredient_name, p.unit_price AS cost 
                          FROM recipe_items ri 
                          LEFT JOIN products p ON ri.ingredient_id = p.id 
                          WHERE ri.product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $recipe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true,
        'product' => $product ?? [],
        'extras' => $extras,
        'sizes' => $sizes,
        'recipe' => $recipe
    ]);
    exit;
}