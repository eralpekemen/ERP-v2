<?php
function deduct_recipe_stock($sale_item_id) {
    global $db;
    $branch_id = get_current_branch();

    $q = "SELECT si.quantity AS sale_qty, r.ingredient_id, r.quantity AS recipe_qty
          FROM sale_items si
          JOIN recipes r ON si.product_id = r.product_id
          JOIN ingredients i ON r.ingredient_id = i.id
          WHERE si.id = ? AND i.branch_id = ?";
    $s = $db->prepare($q);
    $s->bind_param("ii", $sale_item_id, $branch_id);
    $s->execute();
    $items = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($items as $item) {
        $total = $item['sale_qty'] * $item['recipe_qty'];

        // Stok kontrol
        $check = $db->prepare("SELECT current_qty FROM ingredients WHERE id = ? AND branch_id = ?");
        $check->bind_param("ii", $item['ingredient_id'], $branch_id);
        $check->execute();
        $current = $check->get_result()->fetch_row()[0] ?? 0;

        if ($current < $total) return false;

        // Düşüm
        $db->query("UPDATE ingredients SET current_qty = current_qty - $total WHERE id = {$item['ingredient_id']} AND branch_id = $branch_id");
        log_movement($item['ingredient_id'], 'out', $total, 'Satış', $sale_item_id);
    }
    return true;
}

function log_movement($ingredient_id, $type, $qty, $reason, $sale_item_id = null) {
    global $db;
    $branch_id = get_current_branch();
    $q = "INSERT INTO ingredient_movements (ingredient_id, type, qty, reason, sale_item_id, branch_id, created_at)
          VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $s = $db->prepare($q);
    $s->bind_param("isdisi", $ingredient_id, $type, $qty, $reason, $sale_item_id, $branch_id);
    $s->execute();
}