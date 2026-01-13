<?php
ob_start();
session_start();
require_once '../config.php';
require_once '../functions/common.php';

// AJAX GÜVENLİK
if (!isset($_POST['ajax']) || $_POST['ajax'] != '1') {
    die('Yetkisiz erişim');
}

// CSRF KONTROL
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
    exit;
}

// OTURUM KONTROL
if (!isset($_SESSION['personnel_id']) || !in_array($_SESSION['personnel_type'], ['admin', 'cashier'])) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$branch_id = get_current_branch();

// ACTION KONTROL
$action = $_POST['action'] ?? '';
if (empty($action)) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Action eksik']);
    exit;
}

ob_clean();
header('Content-Type: application/json');

switch ($action) {
    case 'list':
        $q = "SELECT 
                p.id, 
                p.name, 
                COALESCE(p.barcode, '') AS barcode,
                p.stock_quantity AS stock,
                p.unit_price AS price,
                COALESCE(c.name, 'Kategorisiz') AS category
              FROM products p
              LEFT JOIN product_categories c ON p.category_id = c.id
              WHERE p.branch_id = ?
              ORDER BY p.name ASC";

        $s = $db->prepare($q);
        if (!$s) {
            echo json_encode(['success' => false, 'message' => 'Sorgu hatası: ' . $db->error]);
            exit;
        }

        $s->bind_param("i", $branch_id);
        if (!$s->execute()) {
            echo json_encode(['success' => false, 'message' => 'Yürütme hatası: ' . $s->error]);
            exit;
        }

        $result = $s->get_result();
        $products = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true,
            'products' => $products,
            'debug' => [
                'branch_id' => $branch_id,
                'count' => count($products)
            ]
        ]);
        exit;

    case 'add':
        $name        = trim($_POST['name'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $unit_price  = floatval($_POST['unit_price'] ?? 0);
        $stock_qty   = floatval($_POST['stock_quantity'] ?? 0);

        if ($name === '' || $unit_price <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
            exit;
        }

        $q = "INSERT INTO products (name, category_id, unit_price, stock_quantity, branch_id)
              VALUES (?, ?, ?, ?, ?)";
        $s = $db->prepare($q);
        $s->bind_param("sidii", $name, $category_id, $unit_price, $stock_qty, $branch_id);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true, 'message' => 'Ürün eklendi.']
                           : ['success' => false, 'message' => 'Ekleme hatası!']);
        exit;

    case 'edit':
        $id = intval($_POST['id'] ?? 0);
        $updates = [];
        $params = [];
        $types = '';

        if (isset($_POST['name']) && trim($_POST['name']) !== '') {
            $updates[] = "name = ?";
            $params[] = trim($_POST['name']);
            $types .= 's';
        }
        if (isset($_POST['category_id'])) {
            $updates[] = "category_id = ?";
            $params[] = intval($_POST['category_id']);
            $types .= 'i';
        }
        if (isset($_POST['unit_price'])) {
            $updates[] = "unit_price = ?";
            $params[] = floatval($_POST['unit_price']);
            $types .= 'd';
        }
        if (isset($_POST['stock_quantity'])) {
            $updates[] = "stock_quantity = ?";
            $params[] = floatval($_POST['stock_quantity']);
            $types .= 'd';
        }

        if (empty($updates) || $id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Güncellenecek veri yok']);
            exit;
        }

        $updates[] = "id = ?";
        $params[] = $id;
        $types .= 'i';
        $updates[] = "branch_id = ?";
        $params[] = $branch_id;
        $types .= 'i';

        $q = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param($types, ...$params);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true, 'message' => 'Güncellendi']
                             : ['success' => false, 'message' => 'Hata']);
    exit;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ID!']);
            exit;
        }

        // Reçete kontrolü
        $check = $db->prepare("SELECT COUNT(*) FROM recipes WHERE product_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $count = $check->get_result()->fetch_row()[0];

        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu ürün reçetede kullanılıyor!']);
            exit;
        }

        $q = "DELETE FROM products WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ii", $id, $branch_id);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true, 'message' => 'Ürün silindi.']
                           : ['success' => false, 'message' => 'Silme hatası!']);
        exit;

    case 'categories':
        $q = "SELECT id, name FROM product_categories WHERE branch_id = ? ORDER BY name";
        $s = $db->prepare($q);
        $s->bind_param("i", $branch_id);
        $s->execute();
        $res = $s->get_result();
        $cats = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode($cats);
        exit;

    case 'add_category':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Kategori adı zorunlu!']);
            exit;
        }

        $check = $db->prepare("SELECT id FROM product_categories WHERE name = ? AND branch_id = ?");
        $check->bind_param("si", $name, $branch_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu kategori zaten var!']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO product_categories (name, branch_id) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $branch_id);
        $ok = $stmt->execute();
        $id = $db->insert_id;

        echo json_encode($ok ? ['success' => true, 'id' => $id, 'name' => $name]
                           : ['success' => false, 'message' => 'Ekleme hatası!']);
        exit;

    case 'edit_category':
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
            exit;
        }

        $check = $db->prepare("SELECT id FROM product_categories WHERE name = ? AND branch_id = ? AND id != ?");
        $check->bind_param("sii", $name, $branch_id, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu isimde başka kategori var!']);
            exit;
        }

        $stmt = $db->prepare("UPDATE product_categories SET name = ? WHERE id = ? AND branch_id = ?");
        $stmt->bind_param("sii", $name, $id, $branch_id);
        $ok = $stmt->execute();

        echo json_encode($ok ? ['success' => true, 'name' => $name]
                           : ['success' => false, 'message' => 'Güncelleme hatası!']);
        exit;

    case 'delete_category':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ID!']);
            exit;
        }

        $check = $db->prepare("SELECT id FROM products WHERE category_id = ? AND branch_id = ? LIMIT 1");
        $check->bind_param("ii", $id, $branch_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu kategoride ürün var!']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM product_categories WHERE id = ? AND branch_id = ?");
        $stmt->bind_param("ii", $id, $branch_id);
        $ok = $stmt->execute();

        echo json_encode($ok ? ['success' => true]
                           : ['success' => false, 'message' => 'Silme hatası!']);
        exit;
    
    case 'recipes':
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ürün']);
            exit;
        }
        $q = "SELECT r.id, r.ingredient_id, i.name AS ingredient_name, r.quantity, i.unit, i.unit_cost
              FROM recipes r
              JOIN ingredients i ON r.ingredient_id = i.id
              WHERE r.product_id = ? AND i.branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ii", $product_id, $branch_id);
        $s->execute();
        $result = $s->get_result();
        $recipes = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'recipes' => $recipes]);
        exit;

    case 'add_recipe':
        $product_id = intval($_POST['product_id'] ?? 0);
        $ingredient_id = intval($_POST['ingredient_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);

        if ($product_id <= 0 || $ingredient_id <= 0 || $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
            exit;
        }

        $q = "INSERT INTO recipes (product_id, ingredient_id, quantity) VALUES (?, ?, ?)";
        $s = $db->prepare($q);
        $s->bind_param("iid", $product_id, $ingredient_id, $quantity);
        $ok = $s->execute();
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Ekleme hatası']);
        exit;

    case 'edit_recipe':
        $id = intval($_POST['id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        if ($id <= 0 || $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
            exit;
        }
        $q = "UPDATE recipes SET quantity = ? WHERE id = ? AND product_id IN (SELECT id FROM products WHERE branch_id = ?)";
        $s = $db->prepare($q);
        $s->bind_param("dii", $quantity, $id, $branch_id);
        $ok = $s->execute();
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Güncelleme hatası']);
        exit;

    case 'delete_recipe':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ID']);
            exit;
        }
        $q = "DELETE FROM recipes WHERE id = ? AND product_id IN (SELECT id FROM products WHERE branch_id = ?)";
        $s = $db->prepare($q);
        $s->bind_param("ii", $id, $branch_id);
        $ok = $s->execute();
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Silme hatası']);
        exit;

    case 'cost':
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ürün']);
            exit;
        }

        $q = "SELECT 
                COALESCE(SUM(r.quantity * i.unit_cost), 0) AS total_cost,
                p.unit_price,
                (p.unit_price - COALESCE(SUM(r.quantity * i.unit_cost), 0)) AS profit,
                CASE 
                    WHEN p.unit_price > 0 
                    THEN ROUND(((p.unit_price - COALESCE(SUM(r.quantity * i.unit_cost), 0)) / p.unit_price) * 100, 1)
                    ELSE 0 
                END AS profit_margin
              FROM products p
              LEFT JOIN recipes r ON p.id = r.product_id
              LEFT JOIN ingredients i ON r.ingredient_id = i.id AND i.branch_id = p.branch_id
              WHERE p.id = ? AND p.branch_id = ?
              GROUP BY p.id";

        $s = $db->prepare($q);
        $s->bind_param("ii", $product_id, $branch_id);
        $s->execute();
        $result = $s->get_result();
        $row = $result->fetch_assoc();

        echo json_encode([
            'success' => true,
            'cost' => [
                'total_cost' => round($row['total_cost'], 2),
                'unit_price' => round($row['unit_price'], 2),
                'profit' => round($row['profit'], 2),
                'profit_margin' => $row['profit_margin']
            ]
        ]);
        exit;

    case 'optimize_price':
        $product_id = intval($_POST['product_id'] ?? 0);
        $target_margin = floatval($_POST['target_margin'] ?? 30); // %30 hedef

        if ($product_id <= 0 || $target_margin <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
            exit;
        }

        // Mevcut maliyet
        $q = "SELECT COALESCE(SUM(r.quantity * i.unit_cost), 0) AS total_cost, p.unit_price
              FROM products p
              LEFT JOIN recipes r ON p.id = r.product_id
              LEFT JOIN ingredients i ON r.ingredient_id = i.id AND i.branch_id = p.branch_id
              WHERE p.id = ? AND p.branch_id = ?
              GROUP BY p.id";
        $s = $db->prepare($q);
        $s->bind_param("ii", $product_id, $branch_id);
        $s->execute();
        $result = $s->get_result();
        $row = $result->fetch_assoc();

        $total_cost = round($row['total_cost'], 2);
        $current_price = round($row['unit_price'], 2);

        // Önerilen fiyat = Maliyet / (1 - hedef marj)
        $suggested_price = $total_cost > 0 ? round($total_cost / (1 - ($target_margin / 100)), 2) : 0;
        $current_margin = $current_price > 0 ? round((($current_price - $total_cost) / $current_price) * 100, 1) : 0;

        echo json_encode([
            'success' => true,
            'optimization' => [
                'total_cost' => $total_cost,
                'current_price' => $current_price,
                'current_margin' => $current_margin,
                'target_margin' => $target_margin,
                'suggested_price' => $suggested_price,
                'price_increase' => round($suggested_price - $current_price, 2),
                'needs_update' => $current_margin < $target_margin
            ]
        ]);
        exit;

    case 'ingredients':
            ob_clean();
            $q = "SELECT id, name, unit, unit_cost, stock_quantity FROM ingredients WHERE branch_id = ? ORDER BY name";
            $s = $db->prepare($q);
            if (!$s) {
                echo json_encode(['success' => false, 'message' => 'Sorgu hazırlama hatası: ' . $db->error]);
                exit;
            }
            $s->bind_param("i", $branch_id);
            if (!$s->execute()) {
                echo json_encode(['success' => false, 'message' => 'Sorgu çalıştırma hatası: ' . $s->error]);
                exit;
            }
            $res = $s->get_result();
            $ingredients = $res->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'ingredients' => $ingredients]);
        exit;

    case 'add_ingredient':
        ob_clean();
        $name = trim($_POST['name'] ?? '');
        $unit = $_POST['unit'] ?? '';
        $unit_cost = floatval($_POST['unit_cost'] ?? 0);
        $stock_quantity = floatval($_POST['stock_quantity'] ?? 0);

        if ($name === '' || $unit === '') {
            echo json_encode(['success' => false, 'message' => 'Ad ve birim zorunlu']);
            exit;
        }

        $q = "INSERT INTO ingredients (name, unit, unit_cost, stock_quantity, branch_id) VALUES (?, ?, ?, ?, ?)";
        $s = $db->prepare($q);
        if (!$s) {
            echo json_encode(['success' => false, 'message' => 'Hazırlama hatası']);
            exit;
        }
        $s->bind_param("ssdii", $name, $unit, $unit_cost, $stock_quantity, $branch_id);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => $s->error]);
        exit;  
    
    case 'edit_ingredient':
        ob_clean();
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $unit = $_POST['unit'] ?? '';
        $unit_cost = floatval($_POST['unit_cost'] ?? 0);
        $stock_quantity = floatval($_POST['stock_quantity'] ?? 0);

        if ($id <= 0 || $name === '' || $unit_cost <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
            exit;
        }

        $q = "UPDATE ingredients SET name = ?, unit = ?, unit_cost = ?, stock_quantity = ? WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ssddii", $name, $unit, $unit_cost, $stock_quantity, $id, $branch_id);
        $ok = $s->execute();
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Güncelleme hatası']);
        exit;

    case 'delete_ingredient':
        ob_clean();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz ID']);
            exit;
        }

        // Reçete kontrolü
        $check = $db->prepare("SELECT COUNT(*) FROM recipes WHERE ingredient_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $count = $check->get_result()->fetch_row()[0];
        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu malzeme reçetede kullanılıyor!']);
            exit;
        }

        $q = "DELETE FROM ingredients WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ii", $id, $branch_id);
        $ok = $s->execute();
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Silme hatası']);
        exit;

    case 'update_stock':
        ob_clean();
        $updates = json_decode($_POST['updates'] ?? '[]', true);
        if (empty($updates)) {
            echo json_encode(['success' => false, 'message' => 'Güncellenecek veri yok']);
            exit;
        }

        $ok = true;
        $db->begin_transaction();
        try {
            $stmt_product = $db->prepare("UPDATE products SET stock_quantity = ? WHERE id = ? AND branch_id = ?");
            $stmt_ingredient = $db->prepare("UPDATE ingredients SET stock_quantity = ? WHERE id = ? AND branch_id = ?");

            foreach ($updates as $u) {
                $qty = floatval($u['count']);
                $id = intval($u['id']);
                $item_type = $u['type'];

                // Eski stok al
                $table = $item_type === 'product' ? 'products' : 'ingredients';
                $col = 'stock_quantity';
                $old_res = $db->query("SELECT $col FROM $table WHERE id = $id AND branch_id = $branch_id");
                if (!$old_res || $old_res->num_rows === 0) continue;
                $old_stock = $old_res->fetch_assoc()[$col];

                // Güncelle
                if ($item_type === 'product') {
                    $stmt_product->bind_param("dii", $qty, $id, $branch_id);
                    $stmt_product->execute();
                } else {
                    $stmt_ingredient->bind_param("dii", $qty, $id, $branch_id);
                    $stmt_ingredient->execute();
                }

                // LOG
                $diff = $qty - $old_stock;
                $log_type = $diff > 0 ? 'in' : ($diff < 0 ? 'out' : 'adjustment');
                log_stock_movement($db, $log_type, $item_type, $id, abs($diff), $old_stock, $qty, 'Stok Sayımı');
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            $ok = false;
        }

        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Hata']);
        exit;

    case 'stock_movements':
            ob_clean();
            $date = $_POST['date'] ?? '';
            $type = $_POST['type'] ?? '';

            $q = "SELECT 
                    sm.*, 
                    CASE sm.item_type 
                        WHEN 'product' THEN p.name 
                        WHEN 'ingredient' THEN i.name 
                    END AS item_name,
                    per.username AS user_name,
                    CASE sm.type
                        WHEN 'in' THEN 'Giriş'
                        WHEN 'out' THEN 'Çıkış'
                        WHEN 'adjustment' THEN 'Düzeltme'
                        WHEN 'order' THEN 'Sipariş'
                        WHEN 'count' THEN 'Sayım'
                    END AS type_tr
                  FROM stock_movements sm
                  LEFT JOIN products p ON sm.item_type = 'product' AND sm.item_id = p.id
                  LEFT JOIN ingredients i ON sm.item_type = 'ingredient' AND sm.item_id = i.id
                  LEFT JOIN personnel per ON sm.created_by = per.id
                  WHERE sm.branch_id = ?";

            $params = [$branch_id];
            $types = "i";

            if ($date) {
                $q .= " AND DATE(sm.created_at) = ?";
                $params[] = $date;
                $types .= "s";
            }
            if ($type) {
                $q .= " AND sm.type = ?";
                $params[] = $type;
                $types .= "s";
            }

            $q .= " ORDER BY sm.created_at DESC LIMIT 100";

            $s = $db->prepare($q);
            $s->bind_param($types, ...$params);
            $s->execute();
            $res = $s->get_result();
            $movements = $res->fetch_all(MYSQLI_ASSOC);

            echo json_encode(['success' => true, 'movements' => $movements]);
            exit;
        case 'complete_sale':
            $table_id = intval($_POST['table_id'] ?? 0);
            $customer_id = $_POST['customer_id'] ? intval($_POST['customer_id']) : 1;
            $payment_details = json_decode($_POST['payment_details'] ?? '[]', true);
            $installments = json_decode($_POST['installments'] ?? '{}', true);
            $user_rates = json_decode($_POST['user_rates'] ?? '{}', true);

            if ($table_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz masa']);
                exit;
            }

            // Açık satış
            $stmt = $db->prepare("SELECT id, total_amount FROM sales WHERE table_id = ? AND status = 'open' AND branch_id = ?");
            $stmt->bind_param("ii", $table_id, $branch_id);
            $stmt->execute();
            $sale = $stmt->get_result()->fetch_assoc();

            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Açık satış yok']);
                exit;
            }

            $sale_id = $sale['id'];
            $sale_total = floatval($sale['total_amount']);

            // Ödeme kontrol
            $total_paid_tl = 0;
            foreach ($payment_details as $p) {
                $amount = floatval($p['amount']);
                $currency = $p['currency'] ?? 'TL';
                $rate = $currency === 'TL' ? 1 : (floatval($user_rates[$p['method_id']] ?? 0) ?: get_exchange_rate($currency));
                $total_paid_tl += $amount * $rate;
            }

            if (round($total_paid_tl, 2) < round($sale_total, 2)) {
                echo json_encode(['success' => false, 'message' => 'Yetersiz ödeme']);
                exit;
            }

            $db->begin_transaction();
            try {
                // STOK DÜŞÜMÜ
                $stmt_items = $db->prepare("SELECT si.product_id, si.quantity, p.stock_quantity 
                                            FROM sale_items si 
                                            JOIN products p ON si.product_id = p.id 
                                            WHERE si.sale_id = ? AND p.branch_id = ?");
                $stmt_items->bind_param("ii", $sale_id, $branch_id);
                $stmt_items->execute();
                $items = $stmt_items->get_result();

                $stmt_product = $db->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?");
                $stmt_ing = $db->prepare("UPDATE ingredients SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?");

                while ($item = $items->fetch_assoc()) {
                    $qty = $item['quantity'];
                    $product_id = $item['product_id'];
                    $old_stock = $item['stock_quantity'];
                    
                    // Ürün düş
                    $stmt_product->bind_param("dii", $qty, $product_id, $branch_id);
                    $stmt_product->execute();

                    log_stock_movement($db, 'out', 'product', $product_id, $qty, $old_stock, $old_stock - $qty, "Satış ID: $sale_id");

                    // REÇETE KONTROLÜ (TABLO VARSA ÇALIŞIR)
                    $recipe_table_exists = $db->query("SHOW TABLES LIKE 'recipe_items'")->num_rows > 0;

                    if ($recipe_table_exists) {
                        $stmt_rec = $db->prepare("SELECT ingredient_id, quantity FROM recipe_items WHERE product_id = ?");
                        $stmt_rec->bind_param("i", $product_id);
                        $stmt_rec->execute();
                        $recipes = $stmt_rec->get_result();

                        while ($ri = $recipes->fetch_assoc()) {
                            $ing_qty = $ri['quantity'] * $qty;
                            $ing_id = $ri['ingredient_id'];

                            $ing_res = $db->query("SELECT stock_quantity FROM ingredients WHERE id = $ing_id AND branch_id = $branch_id");
                            $ing_old = $ing_res->num_rows ? $ing_res->fetch_assoc()['stock_quantity'] : 0;

                            $stmt_ing->bind_param("dii", $ing_qty, $ing_id, $branch_id);
                            $stmt_ing->execute();

                            log_stock_movement($db, 'out', 'ingredient', $ing_id, $ing_qty, $ing_old, $ing_old - $ing_qty, "Reçete → Satış ID: $sale_id");
                        }
                    }
                }

                // Ödemeler
                $stmt_pay = $db->prepare("INSERT INTO payments (sale_id, payment_method_id, amount, currency, commission, installment_count, user_rate, branch_id)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($payment_details as $p) {
                    $method_id = $p['method_id'] === 'open_account' ? 0 : intval($p['method_id']);
                    $amount = floatval($p['amount']);
                    $currency = $p['currency'];
                    $commission = 0;
                    $installment = $installments[$p['method_id']] ?? 1;
                    $user_rate = $user_rates[$p['method_id']] ?? 1;

                    $stmt_pay->bind_param("iidsddii", $sale_id, $method_id, $amount, $currency, $commission, $installment, $user_rate, $branch_id);
                    $stmt_pay->execute();
                }

                // Kapat
                $db->query("UPDATE sales SET status = 'completed' WHERE id = $sale_id");
                $db->query("UPDATE tables SET status = 'available' WHERE id = $table_id");

                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Satış tamamlandı!']);
            } catch (Exception $e) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Geçersiz action']);
            break;
    }
?>