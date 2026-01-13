<?php
// Çıktı tamponunu başlat
ob_start();

session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Saat dilimini ayarla
date_default_timezone_set('Europe/Istanbul');

// Oturum ve kasiyer kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id']) || $_SESSION['personnel_type'] != 'cashier') {
    header("Location: login.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// Loglama: Oturum bilgileri
error_log("pos_takeaway.php - Session branch_id: $branch_id, personnel_id: $personnel_id, method: {$_SERVER['REQUEST_METHOD']}");

// AJAX isteklerini işle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // CSRF kontrolü
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $response['message'] = 'Geçersiz CSRF token!';
        error_log("Hata: Geçersiz CSRF token, action={$_POST['action']}");
        echo json_encode($response);
        exit;
    }

    // AJAX kontrolü (isteğe bağlı)
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] == '1';

    if ($_POST['action'] == 'get_product') {
        $product_id = intval($_POST['product_id']);
        $query = "SELECT id, name, unit_price, description, image_url, stock_quantity, requires_features 
                  FROM products WHERE id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $product_id, $branch_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if ($product) {
            // Özellikleri al
            $query = "SELECT id, name, CAST(additional_price AS DECIMAL(10,2)) AS additional_price, is_mandatory, stock_quantity 
                      FROM product_features WHERE product_id = ? AND branch_id = ? AND stock_quantity > 0";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $product_id, $branch_id);
            $stmt->execute();
            $features = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($features as &$feature) {
                $feature['additional_price'] = floatval($feature['additional_price']);
            }

            // Boyutları al
            $query = "SELECT id, name, CAST(additional_price AS DECIMAL(10,2)) AS additional_price, stock_quantity 
                      FROM product_sizes WHERE product_id = ? AND branch_id = ? AND stock_quantity > 0";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $product_id, $branch_id);
            $stmt->execute();
            $sizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($sizes as &$size) {
                $size['additional_price'] = floatval($size['additional_price']);
            }

            $response = [
                'success' => true,
                'data' => [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'unit_price' => floatval($product['unit_price']),
                    'description' => $product['description'],
                    'image_url' => $product['image_url'] ?: 'https://placehold.co/150',
                    'stock_quantity' => $product['stock_quantity'],
                    'requires_features' => $product['requires_features'],
                    'features' => $features,
                    'sizes' => $sizes
                ]
            ];
            error_log("get_product: product_id=$product_id, branch_id=$branch_id, data=" . json_encode($response['data']));
        } else {
            $response['message'] = 'Ürün bulunamadı!';
            error_log("get_product: Ürün bulunamadı, product_id=$product_id, branch_id=$branch_id");
        }
        echo json_encode($response);
        exit;
    }

    if ($_POST['action'] == 'submit_order') {
        $products_order = json_decode($_POST['products'] ?? '[]', true);
        if (empty($products_order)) {
            $response['message'] = 'Lütfen en az bir ürün ekleyin!';
            error_log("submit_order: Ürün listesi boş");
            echo json_encode($response);
            exit;
        }

        // Yeni satış kaydı oluştur
        $query = "INSERT INTO sales (branch_id, customer_id, total_amount, currency, order_type, status, sale_date, personnel_id) 
                  VALUES (?, 1, 0, 'TL', 'takeaway', 'open', NOW(), ?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $branch_id, $personnel_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Satış kaydı oluşturulamadı: ' . $stmt->error;
            error_log("submit_order: Satış kaydı oluşturulamadı, error=" . $stmt->error);
            echo json_encode($response);
            exit;
        }
        $sale_id = $db->insert_id;

        $success = true;
        $total_amount = 0;
        foreach ($products_order as $item) {
            $product_id = intval($item['id']);
            $quantity = intval($item['quantity']);
            $notes = $item['notes'] ?? '';
            $feature_ids = isset($item['feature_ids']) && is_array($item['feature_ids']) ? array_map('intval', $item['feature_ids']) : [];
            $size_id = isset($item['size_id']) && $item['size_id'] !== 'null' ? intval($item['size_id']) : null;

            $query = "SELECT unit_price, stock_quantity, requires_features FROM products WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $product_id, $branch_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();

            if (!$product) {
                $success = false;
                $response['message'] = 'Ürün bulunamadı: product_id=' . $product_id;
                error_log("submit_order: Ürün bulunamadı, product_id=$product_id");
                continue;
            }

            if ($product['requires_features'] && empty($feature_ids)) {
                $success = false;
                $response['message'] = 'Zorunlu özellik seçimi eksik: ' . ($item['name'] ?? 'Bilinmeyen ürün');
                error_log("submit_order: Zorunlu özellik eksik, product_id=$product_id");
                continue;
            }

            $additional_price = 0;
            if (!empty($feature_ids)) {
                foreach ($feature_ids as $feature_id) {
                    $query = "SELECT additional_price, stock_quantity FROM product_features WHERE id = ? AND product_id = ? AND branch_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("iii", $feature_id, $product_id, $branch_id);
                    $stmt->execute();
                    $feature = $stmt->get_result()->fetch_assoc();
                    if (!$feature || $feature['stock_quantity'] < $quantity) {
                        $success = false;
                        $response['message'] = 'Özellik stokta yok veya yetersiz: feature_id=' . $feature_id;
                        error_log("submit_order: Özellik stokta yok, feature_id=$feature_id, stock_quantity=" . ($feature['stock_quantity'] ?? 'null'));
                        continue 2;
                    }
                    $additional_price += floatval($feature['additional_price']);
                }
            }

            if ($size_id) {
                $query = "SELECT additional_price, stock_quantity FROM product_sizes WHERE id = ? AND product_id = ? AND branch_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iii", $size_id, $product_id, $branch_id);
                $stmt->execute();
                $size = $stmt->get_result()->fetch_assoc();
                if (!$size || $size['stock_quantity'] < $quantity) {
                    $success = false;
                    $response['message'] = 'Boyut stokta yok veya yetersiz: size_id=' . $size_id;
                    error_log("submit_order: Boyut stokta yok, size_id=$size_id, stock_quantity=" . ($size['stock_quantity'] ?? 'null'));
                    continue;
                }
                $additional_price += floatval($size['additional_price']);
            }

            if ($product['stock_quantity'] < $quantity) {
                $success = false;
                $response['message'] = 'Yetersiz stok: product_id=' . $product_id;
                error_log("submit_order: Yetersiz stok, product_id=$product_id, stock_quantity={$product['stock_quantity']}");
                continue;
            }

            $unit_price = floatval($product['unit_price']) + $additional_price;
            $item_total = $unit_price * $quantity;

            $query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, notes) VALUES (?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iiids", $sale_id, $product_id, $quantity, $unit_price, $notes);
            if (!$stmt->execute()) {
                $success = false;
                $response['message'] = 'Ürün eklenemedi: ' . $stmt->error;
                error_log("submit_order: Ürün eklenemedi, product_id=$product_id, error=" . $stmt->error);
                continue;
            }
            $sale_item_id = $db->insert_id;

            if (!empty($feature_ids) || $size_id) {
                foreach ($feature_ids as $feature_id) {
                    $query = "SELECT additional_price FROM product_features WHERE id = ? AND product_id = ? AND branch_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("iii", $feature_id, $product_id, $branch_id);
                    $stmt->execute();
                    $feature = $stmt->get_result()->fetch_assoc();
                    $feature_additional_price = $feature ? floatval($feature['additional_price']) : 0;

                    $query = "INSERT INTO sale_item_features (sale_item_id, feature_id, size_id, additional_price) VALUES (?, ?, ?, ?)";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("iiid", $sale_item_id, $feature_id, $size_id, $feature_additional_price);
                    if (!$stmt->execute()) {
                        $success = false;
                        $response['message'] = 'Özellik eklenemedi: ' . $stmt->error;
                        error_log("submit_order: Özellik eklenemedi, sale_item_id=$sale_item_id, feature_id=$feature_id, error=" . $stmt->error);
                        continue;
                    }
                }
                if ($size_id && empty($feature_ids)) {
                    $query = "INSERT INTO sale_item_features (sale_item_id, feature_id, size_id, additional_price) VALUES (?, NULL, ?, ?)";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("iid", $sale_item_id, $size_id, $additional_price);
                    if (!$stmt->execute()) {
                        $success = false;
                        $response['message'] = 'Boyut eklenemedi: ' . $stmt->error;
                        error_log("submit_order: Boyut eklenemedi, sale_item_id=$sale_item_id, size_id=$size_id, error=" . $stmt->error);
                        continue;
                    }
                }
            }

            $query = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $quantity, $product_id, $branch_id);
            $stmt->execute();

            if (!empty($feature_ids)) {
                foreach ($feature_ids as $feature_id) {
                    $query = "UPDATE product_features SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("iii", $quantity, $feature_id, $branch_id);
                    $stmt->execute();
                }
            }

            if ($size_id) {
                $query = "UPDATE product_sizes SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iii", $quantity, $size_id, $branch_id);
                $stmt->execute();
            }

            $total_amount += $item_total;
        }

        if ($success) {
            $query = "UPDATE sales SET total_amount = total_amount + ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("di", $total_amount, $sale_id);
            if ($stmt->execute()) {
                $points = floor($total_amount / 10);
                $query = "INSERT INTO personnel_points (personnel_id, sale_id, points, branch_id) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iiii", $personnel_id, $sale_id, $points, $branch_id);
                if (!$stmt->execute()) {
                    $response['message'] = 'Personel puanı eklenemedi: ' . $stmt->error;
                    error_log("submit_order: Personel puanı eklenemedi, sale_id=$sale_id, error=" . $stmt->error);
                    echo json_encode($response);
                    exit;
                }

                $response['success'] = true;
                $response['message'] = "Sipariş başarıyla oluşturuldu! Satış ID: $sale_id";
            } else {
                $response['message'] = 'Satış toplamı güncellenemedi: ' . $stmt->error;
                error_log("submit_order: Satış toplamı güncellenemedi, sale_id=$sale_id, error=" . $stmt->error);
            }
        }

        echo json_encode($response);
        exit;
    }

    // Tanımlanmamış bir action için hata
    $response['message'] = 'Geçersiz işlem!';
    error_log("Hata: Geçersiz action, action={$_POST['action']}");
    echo json_encode($response);
    exit;
}

// Ürünler ve kategoriler
$query = "SELECT id, name, icon FROM product_categories WHERE branch_id = ? ORDER BY name";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$products = [];
foreach ($categories as $cat) {
    $query = "SELECT id, name, unit_price, description, image_url, stock_quantity, requires_features 
              FROM products WHERE category_id = ? AND branch_id = ? AND stock_quantity >= 0";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $cat['id'], $branch_id);
    $stmt->execute();
    $products[$cat['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// HTML çıktısı için header
display_header('Paket Servis');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Paket Servis Sistemi">
    <meta name="author" content="SABL">
    <title>Paket Servis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="/ac/assets/css/app.min.css" rel="stylesheet">
    <link href="/ac/assets/css/vendor.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/perfect-scrollbar/1.5.5/css/perfect-scrollbar.min.css">
    <style>
        .pos-product { cursor: pointer; }
        .pos-product.not-available { opacity: 0.5; pointer-events: none; }
        .pos-product .img { width: 100%; height: 150px; background-size: cover; background-position: center; border-radius: 8px; }
        .pos-sidebar { width: 400px; position: fixed; right: 0; top: 0; bottom: 0; }
        .pos-sidebar-body { overflow-y: auto; }
        .pos-order { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #e0e0e0; }
        .pos-order-product .img { width: 50px; height: 50px; background-size: cover; background-position: center; }
        @media (max-width: 767px) {
            .pos-sidebar { width: 100%; transform: translateX(100%); transition: transform 0.3s; }
            .pos-mobile-sidebar-toggled .pos-sidebar { transform: translateX(0); }
        }
        .modal-body .form-group { margin-bottom: 15px; }
        .feature-radio, .feature-checkbox, .size-radio { margin-right: 10px; }
    </style>
</head>
<body class="pace-top">
<div id="app" class="app app-content-full-height app-without-sidebar app-without-header">
    <div id="content" class="app-content p-0">
        <div class="pos pos-with-menu pos-with-sidebar" id="pos">
            <div class="pos-container">
                <!-- Menü -->
                <div class="pos-menu">
                    <div class="logo">
                        <a href="pos.php">
                            <div class="logo-img"><i class="fa fa-bowl-rice"></i></div>
                            <div class="logo-text">SABL</div>
                        </a>
                    </div>
                    <div class="nav-container">
                        <div class="h-100" data-scrollbar="true" data-skip-mobile="true">
                            <ul class="nav nav-tabs">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" data-filter="all">
                                        <i class="fa fa-fw fa-utensils"></i> Tüm Ürünler
                                    </a>
                                </li>
                                <?php foreach ($categories as $cat): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-filter="cat-<?php echo htmlspecialchars($cat['id']); ?>">
                                            <i class="fa fa-fw <?php echo htmlspecialchars($cat['icon']); ?>"></i> <?php echo htmlspecialchars($cat['name']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Ürünler -->
                <div class="pos-content">
                    <div class="pos-content-container h-100">
                        <div class="row gx-4">
                            <?php foreach ($products as $cat_id => $cat_products): ?>
                                <?php foreach ($cat_products as $product): ?>
                                    <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-4 col-sm-6 pb-4" data-type="cat-<?php echo htmlspecialchars($cat_id); ?>">
                                        <div class="pos-product <?php echo $product['stock_quantity'] <= 0 ? 'not-available' : ''; ?>" 
                                             data-product-id="<?php echo $product['id']; ?>" 
                                             data-product-name="<?php echo htmlspecialchars($product['name']); ?>" 
                                             data-product-price="<?php echo $product['unit_price']; ?>" 
                                             data-product-image="<?php echo $product['image_url'] ?: 'https://placehold.co/150'; ?>" 
                                             data-product-desc="<?php echo htmlspecialchars($product['description']); ?>"
                                             data-requires-features="<?php echo $product['requires_features'] ? 'true' : 'false'; ?>">
                                            <div class="img" style="background-image: url(<?php echo $product['image_url'] ?: 'https://placehold.co/150'; ?>)"></div>
                                            <div class="info">
                                                <div class="title"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <div class="desc"><?php echo htmlspecialchars($product['description']); ?></div>
                                                <div class="price"><?php echo number_format($product['unit_price'], 2); ?> TL</div>
                                            </div>
                                            <?php if ($product['stock_quantity'] <= 0): ?>
                                                <div class="not-available-text">
                                                    <div>Stokta Yok</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="pos-sidebar" id="pos-sidebar">
                    <div class="h-100 d-flex flex-column p-0">
                        <div class="pos-sidebar-header">
                            <div class="back-btn">
                                <button type="button" data-toggle-class="pos-mobile-sidebar-toggled" data-toggle-target="#pos" class="btn">
                                    <i class="fa fa-chevron-left"></i>
                                </button>
                            </div>
                            <div class="icon"><i class="fa fa-plate-wheat"></i></div>
                            <div class="title">Paket Servis</div>
                            <div class="order">Sipariş: <span class="fw-semibold" id="order-id">#<?php echo 1000 + rand(0, 9999); ?></span></div>
                        </div>
                        <div class="pos-sidebar-nav small">
                            <ul class="nav nav-tabs nav-fill">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" data-bs-toggle="tab" data-bs-target="#newOrderTab">Yeni Sipariş (<span id="order-count">0</span>)</a>
                                </li>
                            </ul>
                        </div>
                        <div class="pos-sidebar-body tab-content" data-scrollbar="true" data-height="100%">
                            <div class="tab-pane fade h-100 show active" id="newOrderTab">
                                <div id="order-list">
                                    <div class="h-100 d-flex align-items-center justify-content-center text-center p-20">
                                        <div>
                                            <div class="mb-3 mt-n5">
                                                <svg width="6em" height="6em" viewBox="0 0 16 16" class="text-gray-300" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                                    <path fill-rule="evenodd" d="M14 5H2v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V5zM1 4v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4H1z"/>
                                                    <path d="M8 1.5A2.5 2.5 0 0 0 5.5 4h-1a3.5 3.5 0 1 1 7 0h-1A2.5 2.5 0 0 0 8 1.5z"/>
                                                </svg>
                                            </div>
                                            <h5>Sipariş bulunamadı</h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="pos-sidebar-footer">
                            <div class="d-flex align-items-center mb-2">
                                <div>Alt Toplam</div>
                                <div class="flex-1 text-end h6 mb-0" id="subtotal">0 TL</div>
                            </div>
                            <div class="d-flex align-items-center">
                                <div>Vergi (%6)</div>
                                <div class="flex-1 text-end h6 mb-0" id="tax">0 TL</div>
                            </div>
                            <hr class="opacity-1 my-10px">
                            <div class="d-flex align-items-center mb-2">
                                <div>Toplam</div>
                                <div class="flex-1 text-end h4 mb-0" id="total">0 TL</div>
                            </div>
                            <div class="mt-3">
                                <div class="d-flex">
                                    <a href="#" class="btn btn-default w-70px me-10px d-flex align-items-center justify-content-center" onclick="clearOrder()">
                                        <span>
                                            <i class="fa fa-times fa-lg my-10px d-block"></i>
                                            <span class="small fw-semibold">Sıfırla</span>
                                        </span>
                                    </a>
                                    <a href="#" class="btn btn-theme flex-fill d-flex align-items-center justify-content-center" onclick="submitOrder()">
                                        <span>
                                            <i class="fa fa-cash-register fa-lg my-10px d-block"></i>
                                            <span class="small fw-semibold">Sipariş Oluştur</span>
                                        </span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mobil Sidebar Toggler -->
                <a href="#" class="pos-mobile-sidebar-toggler" data-toggle-class="pos-mobile-sidebar-toggled" data-toggle-target="#pos">
                    <i class="fa fa-shopping-bag"></i>
                    <span class="badge" id="mobile-order-count">0</span>
                </a>

                <!-- Özellik ve Boyut Seçim Modal -->
                <div class="modal fade" id="productOptionsModal" tabindex="-1" aria-labelledby="productOptionsModalLabel">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="productOptionsModalLabel">Ürün Seçenekleri</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Özellikler</label>
                                    <div id="feature-options"></div>
                                    <div id="feature-error" class="text-danger" style="display: none;">Zorunlu özellik seçimi gerekli!</div>
                                </div>
                                <div class="form-group">
                                    <label>Boyut</label>
                                    <div id="size-options"></div>
                                    <div id="size-error" class="text-danger" style="display: none;">Boyut verisi yüklenemedi!</div>
                                </div>
                                <div class="form-group">
                                    <label>Miktar</label>
                                    <input type="number" id="modal-quantity" class="form-control" value="1" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Notlar</label>
                                    <textarea id="product-notes" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="button" class="btn btn-primary" id="add-product-btn" onclick="addProductWithOptions()">Ekle</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/perfect-scrollbar/1.5.5/perfect-scrollbar.min.js"></script>
    <script src="/ac/assets/js/vendor.min.js"></script>
    <script src="/ac/assets/js/app.min.js"></script>
    <script>
        let orderItems = [];
        let isSubmitting = false;
        let currentProduct = null;

        $(document).ready(function() {
            console.log('jQuery loaded, document ready, time: ' + new Date().toLocaleString());

            $('.nav-link[data-filter]').click(function(e) {
                e.preventDefault();
                $('.nav-link').removeClass('active');
                $(this).addClass('active');
                const filter = $(this).data('filter');
                if (filter === 'all') {
                    $('.pos-product').parent().show();
                } else {
                    $('.pos-product').parent().hide();
                    $(`.pos-product[data-type="${filter}"]`).parent().show();
                }
            });

            $('.pos-product:not(.not-available)').click(debounce(function() {
                const productId = $(this).data('product-id');
                console.log('Ürün seçildi: product_id=' + productId);
                openProductModal(productId);
            }, 300));

            $('#productOptionsModal').on('show.bs.modal', function() {
                $(this).removeAttr('inert');
            });

            $('#productOptionsModal').on('hide.bs.modal', function() {
                $(this).attr('inert', '');
                $('#feature-options, #size-options').html('');
                $('#product-notes').val('');
                $('#modal-quantity').val('1');
                $('#feature-error, #size-error').hide();
            });

            function debounce(func, wait) {
                let timeout;
                return function(...args) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            }
        });

        function openProductModal(productId) {
            console.log('openProductModal called:', productId);
            $.ajax({
                url: 'pos_takeaway.php',
                type: 'POST',
                data: {
                    csrf_token: '<?php echo $csrf_token; ?>',
                    action: 'get_product',
                    ajax: '1',
                    product_id: productId
                },
                dataType: 'json',
                success: function(response) {
                    console.log('openProductModal AJAX success:', response);
                    if (response.success) {
                        currentProduct = response.data;
                        if (currentProduct.stock_quantity <= 0) {
                            showToast(`Hata: ${currentProduct.name} stokta yok!`, 'danger');
                            return;
                        }
                        showProductOptionsModal();
                    } else {
                        showToast(response.message || 'Ürün yüklenemedi!', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('openProductModal AJAX error:', xhr.status, xhr.responseText);
                    showToast('Hata: ' + (xhr.responseJSON?.message || 'Sunucu hatası!'), 'danger');
                }
            });
        }

        function showProductOptionsModal() {
            if (!currentProduct) {
                console.error('Hata: currentProduct null');
                showToast('Ürün seçimi başarısız!', 'danger');
                return;
            }

            const features = currentProduct.features || [];
            const sizes = currentProduct.sizes || [];
            const featureOptions = $('#feature-options');
            const sizeOptions = $('#size-options');
            const addButton = $('#add-product-btn');

            featureOptions.html('');
            sizeOptions.html('');
            $('#product-notes').val('');
            $('#modal-quantity').val('1');
            $('#feature-error').hide();
            $('#size-error').hide();

            console.log('Features for product', currentProduct.id, ':', features);
            console.log('Sizes for product', currentProduct.id, ':', sizes);

            // Özellikleri render et
            if (features.length > 0) {
                const mandatoryFeatures = features.filter(f => f.is_mandatory);
                const optionalFeatures = features.filter(f => !f.is_mandatory);

                if (mandatoryFeatures.length > 0) {
                    featureOptions.append('<h6>Zorunlu Seçimler</h6>');
                    mandatoryFeatures.forEach(feature => {
                        if (feature.stock_quantity > 0) {
                            const additionalPrice = parseFloat(feature.additional_price) || 0;
                            if (isNaN(additionalPrice)) {
                                console.error('Invalid additional_price for feature:', feature);
                                showToast('Hata: Geçersiz özellik fiyatı!', 'danger');
                                return;
                            }
                            featureOptions.append(`
                                <div class="form-check feature-radio">
                                    <input class="form-check-input" type="radio" name="feature_mandatory" 
                                           id="feature-${feature.id}" value="${feature.id}" 
                                           data-price="${additionalPrice}">
                                    <label class="form-check-label" for="feature-${feature.id}">
                                        ${feature.name} ${additionalPrice > 0 ? '(+' + additionalPrice.toFixed(2) + ' TL)' : ''} (Stok: ${feature.stock_quantity})
                                    </label>
                                </div>
                            `);
                        }
                    });
                }

                if (optionalFeatures.length > 0) {
                    featureOptions.append('<h6>İsteğe Bağlı Seçimler</h6>');
                    optionalFeatures.forEach(feature => {
                        if (feature.stock_quantity > 0) {
                            const additionalPrice = parseFloat(feature.additional_price) || 0;
                            if (isNaN(additionalPrice)) {
                                console.error('Invalid additional_price for feature:', feature);
                                showToast('Hata: Geçersiz özellik fiyatı!', 'danger');
                                return;
                            }
                            featureOptions.append(`
                                <div class="form-check feature-checkbox">
                                    <input class="form-check-input" type="checkbox" name="feature_optional" 
                                           id="feature-${feature.id}" value="${feature.id}" 
                                           data-price="${additionalPrice}">
                                    <label class="form-check-label" for="feature-${feature.id}">
                                        ${feature.name} ${additionalPrice > 0 ? '(+' + additionalPrice.toFixed(2) + ' TL)' : ''} (Stok: ${feature.stock_quantity})
                                    </label>
                                </div>
                            `);
                        }
                    });
                }
            } else {
                featureOptions.html('<p>Özellik yok</p>');
            }

            // Boyutları render et
            if (sizes.length > 0) {
                sizes.forEach(size => {
                    if (size.stock_quantity > 0) {
                        const additionalPrice = parseFloat(size.additional_price) || 0;
                        if (isNaN(additionalPrice)) {
                            console.error('Invalid additional_price for size:', size);
                            showToast('Hata: Geçersiz boyut fiyatı!', 'danger');
                            $('#size-error').show();
                            return;
                        }
                        sizeOptions.append(`
                            <div class="form-check size-radio">
                                <input class="form-check-input" type="radio" name="size" 
                                       id="size-${size.id}" value="${size.id}" 
                                       data-additional-price="${additionalPrice}">
                                <label class="form-check-label" for="size-${size.id}">
                                    ${size.name} ${additionalPrice > 0 ? '(+' + additionalPrice.toFixed(2) + ' TL)' : ''} (Stok: ${size.stock_quantity})
                                </label>
                            </div>
                        `);
                    }
                });
            } else {
                sizeOptions.html('<p>Boyut yok</p>');
            }

            addButton.prop('disabled', currentProduct.requires_features && features.some(f => f.is_mandatory));
            $('#productOptionsModalLabel').text(currentProduct.name + ' Seçenekleri');
            $('#productOptionsModal').modal('show');

            $('input[name="feature_mandatory"]').change(function() {
                addButton.prop('disabled', !$('input[name="feature_mandatory"]:checked').length);
                $('#feature-error').hide();
            });
        }

        function addProductWithOptions() {
            const featureIds = [];
            const mandatoryFeatureId = $('input[name="feature_mandatory"]:checked').val();
            const optionalFeatureIds = $('input[name="feature_optional"]:checked').map(function() {
                return $(this).val();
            }).get();
            if (mandatoryFeatureId) featureIds.push(mandatoryFeatureId);
            featureIds.push(...optionalFeatureIds);
            const sizeId = $('input[name="size"]:checked').val();
            const quantity = parseInt($('#modal-quantity').val()) || 1;
            const notes = $('#product-notes').val();

            if (currentProduct.requires_features && !mandatoryFeatureId) {
                $('#feature-error').show();
                return;
            }

            if (quantity < 1) {
                showToast('Hata: Geçersiz miktar!', 'danger');
                return;
            }

            if (currentProduct.stock_quantity < quantity) {
                showToast(`Hata: ${currentProduct.name} için yetersiz stok!`, 'danger');
                return;
            }

            let totalPrice = parseFloat(currentProduct.unit_price);
            let featureNames = [];
            let sizeName = '';

            if (featureIds.length > 0) {
                for (const featureId of featureIds) {
                    const feature = currentProduct.features.find(f => f.id == featureId);
                    if (feature) {
                        if (feature.stock_quantity < quantity) {
                            showToast(`Hata: ${feature.name} için yetersiz stok!`, 'danger');
                            return;
                        }
                        const additionalPrice = parseFloat(feature.additional_price) || 0;
                        totalPrice += additionalPrice;
                        featureNames.push(`${feature.name}${additionalPrice > 0 ? ' (+' + additionalPrice.toFixed(2) + ' TL)' : ''}`);
                    } else {
                        console.error('Feature not found:', featureId);
                        showToast('Hata: Özellik bulunamadı!', 'danger');
                        return;
                    }
                }
            }

            if (sizeId) {
                const size = currentProduct.sizes.find(s => s.id == sizeId);
                if (size) {
                    if (size.stock_quantity < quantity) {
                        showToast(`Hata: ${size.name} için yetersiz stok!`, 'danger');
                        return;
                    }
                    const additionalPrice = parseFloat(size.additional_price) || 0;
                    totalPrice += additionalPrice;
                    sizeName = `${size.name}${additionalPrice > 0 ? ' (+' + additionalPrice.toFixed(2) + ' TL)' : ''}`;
                } else {
                    console.error('Size not found:', sizeId);
                    showToast('Hata: Boyut bulunamadı!', 'danger');
                    return;
                }
            }

            const existingItem = orderItems.find(item => 
                item.id === currentProduct.id && 
                JSON.stringify(item.feature_ids) === JSON.stringify(featureIds) && 
                item.size_id == sizeId && 
                item.notes === notes
            );

            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                orderItems.push({
                    id: currentProduct.id,
                    name: currentProduct.name,
                    price: totalPrice,
                    image: currentProduct.image_url,
                    desc: currentProduct.description,
                    quantity: quantity,
                    notes: notes,
                    feature_ids: featureIds,
                    feature_name: featureNames.join(', '),
                    size_id: sizeId || null,
                    size_name: sizeName
                });
            }

            $('#productOptionsModal').modal('hide');
            updateOrderList();
        }

        function updateOrderList() {
            console.log('updateOrderList called, orderItems:', orderItems);
            const orderList = $('#order-list');
            const orderCount = $('#order-count, #mobile-order-count');
            const subtotalEl = $('#subtotal');
            const taxEl = $('#tax');
            const totalEl = $('#total');

            if (orderItems.length === 0) {
                orderList.html(`
                    <div class="h-100 d-flex align-items-center justify-content-center text-center p-20">
                        <div>
                            <div class="mb-3 mt-n5">
                                <svg width="6em" height="6em" viewBox="0 0 16 16" class="text-gray-300" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M14 5H2v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V5zM1 4v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4H1z"/>
                                    <path d="M8 1.5A2.5 2.5 0 0 0 5.5 4h-1a3.5 3.5 0 1 1 7 0h-1A2.5 2.5 0 0 0 8 1.5z"/>
                                </svg>
                            </div>
                            <h5>Sipariş bulunamadı</h5>
                        </div>
                    </div>
                `);
                orderCount.text('0');
                subtotalEl.text('0 TL');
                taxEl.text('0 TL');
                totalEl.text('0 TL');
                return;
            }

            let subtotal = 0;
            orderList.html('');
            orderItems.forEach((item, index) => {
                const itemTotal = (item.price * item.quantity).toFixed(2);
                subtotal += parseFloat(itemTotal);
                const options = [];
                if (item.feature_name) options.push(item.feature_name);
                if (item.size_name) options.push(item.size_name);
                const optionsText = options.length > 0 ? options.join(', ') : '-';
                orderList.append(`
                    <div class="pos-order">
                        <div class="pos-order-product">
                            <div class="img" style="background-image: url(${item.image})"></div>
                            <div class="flex-1">
                                <div class="h6 mb-1">${item.name}</div>
                                <div class="small">${item.price.toFixed(2)} TL</div>
                                <div class="small mb-2">Seçenekler: ${optionsText}</div>
                                <div class="small mb-2">Not: ${item.notes || '-'}</div>
                                <div class="d-flex">
                                    <a href="#" class="btn btn-secondary btn-sm" onclick="changeQuantity(${index}, -1); return false;"><i class="fa fa-minus"></i></a>
                                    <input type="text" class="form-control w-50px form-control-sm mx-2 bg-white bg-opacity-25 text-center" value="${item.quantity}" readonly>
                                    <a href="#" class="btn btn-secondary btn-sm" onclick="changeQuantity(${index}, 1); return false;"><i class="fa fa-plus"></i></a>
                                </div>
                            </div>
                        </div>
                        <div class="pos-order-price d-flex flex-column">
                            <div class="flex-1">${itemTotal} TL</div>
                            <div class="text-end">
                                <a href="#" class="btn btn-default btn-sm" onclick="removeItem(${index}); return false;"><i class="fa fa-trash"></i></a>
                            </div>
                        </div>
                    </div>
                `);
            });

            const tax = subtotal * 0.06;
            const total = subtotal + tax;
            orderCount.text(orderItems.length);
            subtotalEl.text(`${subtotal.toFixed(2)} TL`);
            taxEl.text(`${tax.toFixed(2)} TL`);
            totalEl.text(`${total.toFixed(2)} TL`);
        }

        function changeQuantity(index, delta) {
            console.log('changeQuantity called:', index, delta);
            if (orderItems[index].quantity + delta >= 1) {
                orderItems[index].quantity += delta;
            }
            updateOrderList();
        }

        function removeItem(index) {
            console.log('removeItem called:', index);
            orderItems.splice(index, 1);
            updateOrderList();
        }

        function clearOrder() {
            console.log('clearOrder called');
            orderItems = [];
            updateOrderList();
            showToast('Sipariş sıfırlandı!', 'success');
        }

        function submitOrder() {
            if (isSubmitting) {
                console.log('submitOrder: Already submitting, ignoring new request');
                return;
            }
            isSubmitting = true;

            console.log('submitOrder called, orderItems:', orderItems);
            if (orderItems.length === 0) {
                console.log('submitOrder: No items in order');
                showToast('Lütfen en az bir ürün ekleyin!', 'danger');
                isSubmitting = false;
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'submit_order');
            formData.append('ajax', '1'); // AJAX parametresi eklendi
            formData.append('products', JSON.stringify(orderItems.map(item => ({
                id: item.id,
                quantity: item.quantity,
                notes: item.notes,
                feature_ids: item.feature_ids,
                size_id: item.size_id
            }))));

            $.ajax({
                url: 'pos_takeaway.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    console.log('submitOrder AJAX success:', response);
                    if (response.success) {
                        showToast(response.message, 'success');
                        orderItems = [];
                        updateOrderList();
                    } else {
                        showToast(response.message || 'Sipariş oluşturulamadı!', 'danger');
                    }
                    isSubmitting = false;
                },
                error: function(xhr, status, error) {
                    console.error('submitOrder AJAX error:', xhr.status, xhr.responseText);
                    showToast('Hata: ' + (xhr.responseJSON?.message || 'Sunucu hatası!'), 'danger');
                    isSubmitting = false;
                }
            });
        }

        function showToast(message, type) {
            console.log('showToast called:', message, type);
            const toastContainer = $('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
            toastContainer.html(`
                <div class="toast show" role="alert">
                    <div class="toast-body bg-${type} text-white">${message}</div>
                </div>
            `);
            $('body').append(toastContainer);
            setTimeout(() => toastContainer.remove(), 3000);
        }
    </script>

    <?php display_footer(); ?>
</body>
</html>
<?php
ob_end_flush();
?>