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

// Oturum kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id'])) {
    header("Location: login.php");
    exit;
}

// Erişim kontrolü
if (!in_array($_SESSION['personnel_type'], ['admin', 'cashier'])) {
    header("Location: dashboard.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$table_id = isset($_GET['table_id']) ? intval($_GET['table_id']) : 0;
$csrf_token = generate_csrf_token();

// Loglama: Session ve table_id kontrolü
error_log("add_product.php - Session branch_id: $branch_id, personnel_id: $personnel_id, table_id: $table_id, method: {$_SERVER['REQUEST_METHOD']}, get: " . print_r($_GET, true));

// Masayı kontrol et
global $db;
$query = "SELECT id, number, status FROM tables WHERE id = ? AND branch_id = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("ii", $table_id, $branch_id);
$stmt->execute();
$table = $stmt->get_result()->fetch_assoc();
if (!$table) {
    error_log("Masa bulunamadı: table_id=$table_id, branch_id=$branch_id");
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => "Geçersiz masa! (table_id: $table_id, branch_id: $branch_id)"
        ]);
        exit;
    } else {
        header("Location: pos.php");
        exit;
    }
}

// Masa durumu kontrolü (rezerve masalara sipariş engelleme)
if ($table['status'] === 'reserved') {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => "Bu masa rezerve! Sipariş eklenemez."
        ]);
        exit;
    } else {
        header("Location: pos.php?error=reserved_table");
        exit;
    }
}

// POST isteğini yakala ve JSON yanıt dön
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_order') {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Loglama: POST verileri
    error_log("POST Request: table_id=$table_id, branch_id=$branch_id, post_data=" . print_r($_POST, true));

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Geçersiz CSRF token!';
        echo json_encode($response);
        exit;
    }

    // Ürünleri kontrol et
    $products_order = json_decode($_POST['products'] ?? '[]', true);
    if (empty($products_order)) {
        $response['message'] = 'Lütfen en az bir ürün ekleyin!';
        echo json_encode($response);
        exit;
    }

    // Açık siparişi kontrol et
    $query = "SELECT id, total_amount FROM sales WHERE table_id = ? AND status = 'open'";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();

    if (!$sale) {
        // Yeni satış kaydı oluştur
        $query = "INSERT INTO sales (branch_id, customer_id, total_amount, currency, order_type, table_id, status, sale_date, personnel_id) 
                  VALUES (?, 1, 0, 'TL', 'table', ?, 'open', NOW(), ?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iii", $branch_id, $table_id, $personnel_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Satış kaydı oluşturulamadı: ' . $stmt->error;
            error_log("Hata: Satış kaydı oluşturulamadı, error=" . $stmt->error);
            echo json_encode($response);
            exit;
        }
        $sale_id = $db->insert_id;
    } else {
        $sale_id = $sale['id'];
    }

    // Ürünleri ekle
    $success = true;
    $total_amount = 0;
    foreach ($products_order as $item) {
        $product_id = intval($item['id']);
        $quantity = intval($item['quantity']);
        $notes = $item['notes'] ?? '';
        $feature_id = isset($item['feature_id']) && $item['feature_id'] !== 'null' ? intval($item['feature_id']) : null;
        $size_id = isset($item['size_id']) && $item['size_id'] !== 'null' ? intval($item['size_id']) : null;

        // Ürün bilgilerini al
        $query = "SELECT unit_price, stock_quantity, requires_features FROM products WHERE id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $product_id, $branch_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product) {
            $success = false;
            $response['message'] = 'Ürün bulunamadı: product_id=' . $product_id;
            error_log("Hata: Ürün bulunamadı, product_id=$product_id");
            continue;
        }

        // Zorunlu özellik kontrolü
        if ($product['requires_features'] && !$feature_id) {
            $success = false;
            $response['message'] = 'Zorunlu özellik seçimi eksik: ' . ($item['name'] ?? 'Bilinmeyen ürün');
            error_log("Hata: Zorunlu özellik eksik, product_id=$product_id");
            continue;
        }

        // Özellik kontrolü
        $additional_price = 0;
        if ($feature_id) {
            $query = "SELECT additional_price, stock_quantity FROM product_features WHERE id = ? AND product_id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $feature_id, $product_id, $branch_id);
            $stmt->execute();
            $feature = $stmt->get_result()->fetch_assoc();
            if (!$feature || $feature['stock_quantity'] < $quantity) {
                $success = false;
                $response['message'] = 'Özellik stokta yok veya yetersiz: feature_id=' . $feature_id;
                error_log("Hata: Özellik stokta yok, feature_id=$feature_id, stock_quantity=" . ($feature['stock_quantity'] ?? 'null'));
                continue;
            }
            $additional_price += floatval($feature['additional_price']);
        }

        // Boyut kontrolü
        if ($size_id) {
            $query = "SELECT additional_price, stock_quantity FROM product_sizes WHERE id = ? AND product_id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $size_id, $product_id, $branch_id);
            $stmt->execute();
            $size = $stmt->get_result()->fetch_assoc();
            if (!$size || $size['stock_quantity'] < $quantity) {
                $success = false;
                $response['message'] = 'Boyut stokta yok veya yetersiz: size_id=' . $size_id;
                error_log("Hata: Boyut stokta yok, size_id=$size_id, stock_quantity=" . ($size['stock_quantity'] ?? 'null'));
                continue;
            }
            $additional_price += floatval($size['additional_price']);
        }

        // Stok kontrolü
        if ($product['stock_quantity'] < $quantity) {
            $success = false;
            $response['message'] = 'Yetersiz stok: product_id=' . $product_id;
            error_log("Hata: Yetersiz stok, product_id=$product_id, stock_quantity={$product['stock_quantity']}");
            continue;
        }

        // Toplam fiyat hesapla
        $unit_price = $product['unit_price'] + $additional_price;
        $item_total = $unit_price * $quantity;

        // Sale item ekle
        $query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, notes) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iiids", $sale_id, $product_id, $quantity, $unit_price, $notes);
        if (!$stmt->execute()) {
            $success = false;
            $response['message'] = 'Ürün eklenemedi: ' . $stmt->error;
            error_log("Hata: Ürün eklenemedi, product_id=$product_id, error=" . $stmt->error);
            continue;
        }
        $sale_item_id = $db->insert_id;

        // Özellik ve boyut ekle
        if ($feature_id || $size_id) {
            $query = "INSERT INTO sale_item_features (sale_item_id, feature_id, size_id, additional_price) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iiid", $sale_item_id, $feature_id, $size_id, $additional_price);
            if (!$stmt->execute()) {
                $success = false;
                $response['message'] = 'Özellik/boyut eklenemedi: ' . $stmt->error;
                error_log("Hata: Özellik/boyut eklenemedi, sale_item_id=$sale_item_id, error=" . $stmt->error);
                continue;
            }
        }

        // Stok güncelle
        $query = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("iii", $quantity, $product_id, $branch_id);
        $stmt->execute();

        if ($feature_id) {
            $query = "UPDATE product_features SET stock_quantity = stock_quantity - ? WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $quantity, $feature_id, $branch_id);
            $stmt->execute();
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
        // Toplam tutarı güncelle
        $query = "UPDATE sales SET total_amount = total_amount + ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("di", $total_amount, $sale_id);
        if ($stmt->execute()) {
            // Masa durumunu güncelle
            $query = "UPDATE tables SET status = 'occupied' WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $table_id);
            $stmt->execute();

            // Personel puanlama
            $points = floor($total_amount / 10); // 10 TL için 1 puan
            $query = "INSERT INTO personnel_points (personnel_id, sale_id, points, branch_id) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iiii", $personnel_id, $sale_id, $points, $branch_id);
            if (!$stmt->execute()) {
                $response['message'] = 'Personel puanı eklenemedi: ' . $stmt->error;
                error_log("Hata: Personel puanı eklenemedi, sale_id=$sale_id, error=" . $stmt->error);
                echo json_encode($response);
                exit;
            }

            $response['success'] = true;
            $response['message'] = "Sipariş başarıyla oluşturuldu! Satış ID: $sale_id";
        } else {
            $response['message'] = 'Satış toplamı güncellenemedi: ' . $stmt->error;
            error_log("Hata: Satış toplamı güncellenemedi, sale_id=$sale_id, error=" . $stmt->error);
        }
    }

    echo json_encode($response);
    exit;
}

// Ürünler, kategoriler, özellikler ve boyutlar
$query = "SELECT id, name, icon FROM product_categories WHERE branch_id = ? ORDER BY name";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$products = [];
$product_features = [];
$product_sizes = [];
foreach ($categories as $cat) {
    $query = "SELECT id, name, unit_price, description, image_url, requires_features,
                 COALESCE(status, 'active') as status
          FROM products 
          WHERE category_id = ? AND branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $cat['id'], $branch_id);
    $stmt->execute();
    $products[$cat['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Özellikleri al
    foreach ($products[$cat['id']] as $product) {
        $query = "SELECT id, name, additional_price, is_mandatory, stock_quantity 
                  FROM product_features WHERE product_id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $product['id'], $branch_id);
        $stmt->execute();
        $features = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($features as &$feature) {
            $feature['additional_price'] = floatval($feature['additional_price']);
        }
        $product_features[$product['id']] = $features;

        // Boyutları al
        $query = "SELECT id, name, additional_price, stock_quantity 
                  FROM product_sizes WHERE product_id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $product['id'], $branch_id);
        $stmt->execute();
        $sizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($sizes as &$size) {
            $size['additional_price'] = floatval($size['additional_price']);
        }
        $product_sizes[$product['id']] = $sizes;
    }
}

// Veritabanı verilerini logla
error_log("product_features: " . print_r($product_features, true));
error_log("product_sizes: " . print_r($product_sizes, true));

// HTML çıktısı için header
display_header('Ürün Ekle - Masa ' . htmlspecialchars($table['number']));
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ürün Ekle - Masa <?php echo htmlspecialchars($table['number']); ?></title>
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
        .feature-radio, .size-radio { margin-right: 10px; }
        .pos-product.not-available::after {
            content: "SATIŞA KAPALI";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(220, 53, 69, 0.95);
            color: white;
            font-weight: bold;
            font-size: 1.4rem;
            padding: 12px 24px;
            border-radius: 12px;
            z-index: 10;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .navbar{
            display:none!important;
        }
        .has-search .form-control {
    padding-left: 2.375rem;
}

.has-search .form-control-feedback {
    position: absolute;
    z-index: 2;
    display: block;
    width: 2.375rem;
    height: 2.375rem;
    line-height: 2.375rem;
    text-align: center;
    pointer-events: none;
    color: #aaa;
}
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
                                        <a class="nav-link" href="#" data-filter="cat-<?php echo $cat['id']; ?>">
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
                        <div class="mt-2 mb-2 row">
                            <div class="col-lg-12">
                                <div class="form-group has-search">
                                    <span class="fa fa-search form-control-feedback"></span>                                    
                                    <input type="text" id="barcode-input" autofocus style="opacity: 1;" class="form-control w-100" placeholder="Barkod ile ürün ekle..." />
                                </div>
                            </div>
                        </div>
                        <div class="row gx-4">
                            <?php foreach ($products as $cat_id => $cat_products): ?>
                                <?php foreach ($cat_products as $product): 
                                        if($product['description'] === null) {
                                            $pDesc = $product['description'];
                                        }else{
                                            $pDesc = '';
                                        }
                                        if($product['image_url'] === null) {
                                            $pImageFile = 'includes/images/products/';
                                        }else{
                                            $pImageFile = '';
                                        }
                                    ?>
                                    <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-4 col-sm-6 pb-4" data-type="cat-<?php echo $cat_id; ?>">
                                        <div class="pos-product <?= (empty($product['status']) || $product['status'] !== 'active') ? 'not-available' : '' ?>" 
                                            data-product-id="<?= $product['id'] ?>" 
                                            <?= (empty($product['status']) || $product['status'] !== 'active') ? 'title="Satışa kapalı ürün"' : '' ?>
                                            <?= (!isset($product['status']) || $product['status'] !== 'active') ? 'title="Satışa kapalı ürün"' : '' ?>
                                            data-product-name="<?php echo htmlspecialchars($product['name']); ?>" 
                                            data-product-price="<?php echo $product['unit_price']; ?>" 
                                            data-product-image="<?php echo $pImageFile.$product['image_url'] ?: 'https://placehold.co/150'; ?>" 
                                            data-product-desc="<?php echo $pDesc; ?>"
                                            data-requires-features="<?php echo $product['requires_features'] ? 'true' : 'false'; ?>">
                                            <div class="img" style="background-image: url(<?php echo $product['image_url'] ?: 'https://placehold.co/150'; ?>)"></div>
                                            <div class="info">
                                                <div class="title"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <div class="desc"><?php echo $pDesc; ?></div>
                                                <div class="price"><?php echo number_format($product['unit_price'], 2); ?> TL</div>
                                            </div>
                                            <?php if ($product['status'] != 'active'): ?>
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
                            <div class="title">Masa <?php echo htmlspecialchars($table['number']); ?></div>
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
                                    <a href="#" class="btn btn-default w-70px me-10px d-flex align-items-center justify-content-center" onclick="window.close()">
                                        <span>
                                            <i class="fa fa-times fa-lg my-10px d-block"></i>
                                            <span class="small fw-semibold">İptal</span>
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
        const productFeatures = <?php echo json_encode($product_features, JSON_NUMERIC_CHECK); ?>;
        const productSizes = <?php echo json_encode($product_sizes, JSON_NUMERIC_CHECK); ?>;

        $(document).ready(function() {
            console.log('jQuery loaded, document ready, table_id: <?php echo $table_id; ?>, time: ' + new Date().toLocaleString());
            console.log('productFeatures:', productFeatures);
            console.log('productSizes:', productSizes);

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

            $('.pos-product:not(.not-available)').click(function() {
                currentProduct = {
                    id: $(this).data('product-id'),
                    name: $(this).data('product-name'),
                    price: parseFloat($(this).data('product-price')),
                    image: $(this).data('product-image'),
                    desc: $(this).data('product-desc'),
                    requiresFeatures: $(this).data('requires-features') === 'true'
                };
                console.log('Ürün seçildi:', currentProduct);
                showProductOptionsModal();
            });

            // Modal açıldığında inert özelliğini kaldır
            $('#productOptionsModal').on('show.bs.modal', function () {
                $(this).removeAttr('inert');
            });

            // Modal kapandığında inert özelliğini ekle
            $('#productOptionsModal').on('hide.bs.modal', function () {
                $(this).attr('inert', '');
            });
        });
        const tableId = <?php echo $table_id; ?>; // PHP'den gelen table_id
        console.log('Table ID:', tableId);

        function showProductOptionsModal() {
            if (!currentProduct) {
                console.error('Hata: currentProduct null');
                showToast('Ürün seçimi başarısız!', 'danger');
                return;
            }

            const features = productFeatures[currentProduct.id] || [];
            const sizes = productSizes[currentProduct.id] || [];
            const featureOptions = $('#feature-options');
            const sizeOptions = $('#size-options');
            const addButton = $('#add-product-btn');

            featureOptions.html('');
            sizeOptions.html('');
            $('#product-notes').val('');
            $('#feature-error').hide();
            $('#size-error').hide();

            console.log('Features for product', currentProduct.id, ':', features);
            console.log('Sizes for product', currentProduct.id, ':', sizes);

            if (features.length > 0) {
                features.forEach(feature => {
                    if (feature.stock_quantity > 0) {
                        const additionalPrice = parseFloat(feature.additional_price);
                        if (isNaN(additionalPrice)) {
                            console.error('Invalid additional_price for feature:', feature);
                            showToast('Hata: Geçersiz özellik fiyatı!', 'danger');
                            return;
                        }
                        featureOptions.append(`
                            <div class="form-check feature-radio">
                                <input class="form-check-input" type="radio" name="feature" 
                                       id="feature-${feature.id}" value="${feature.id}" 
                                       data-price="${additionalPrice}">
                                <label class="form-check-label" for="feature-${feature.id}">
                                    ${feature.name} ${additionalPrice > 0 ? '(+' + additionalPrice.toFixed(2) + ' TL)' : ''}
                                </label>
                            </div>
                        `);
                    }
                });
            } else {
                featureOptions.html('<p>Özellik yok</p>');
            }

            if (sizes.length > 0) {
                sizes.forEach(size => {
                    if (size.stock_quantity > 0) {
                        const additionalPrice = parseFloat(size.additional_price);
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
                                    ${size.name} ${additionalPrice > 0 ? '(+' + additionalPrice.toFixed(2) + ' TL)' : ''}
                                </label>
                            </div>
                        `);
                    }
                });
            } else {
                sizeOptions.html('<p>Boyut yok</p>');
            }

            addButton.prop('disabled', currentProduct.requiresFeatures && features.length > 0);
            $('#productOptionsModalLabel').text(currentProduct.name + ' Seçenekleri');
            $('#productOptionsModal').modal('show');

            $('input[name="feature"]').change(function() {
                addButton.prop('disabled', false);
                $('#feature-error').hide();
            });
        }

        function addProductWithOptions() {
            const featureId = $('input[name="feature"]:checked').val();
            const sizeId = $('input[name="size"]:checked').val();
            const notes = $('#product-notes').val();
            const requiresFeatures = currentProduct.requiresFeatures;

            if (requiresFeatures && !featureId) {
                $('#feature-error').show();
                return;
            }

            let totalPrice = currentProduct.price;
            let featureName = '';
            let sizeName = '';

            if (featureId) {
                const feature = productFeatures[currentProduct.id]?.find(f => f.id == featureId);
                if (feature) {
                    totalPrice += parseFloat(feature.additional_price);
                    featureName = feature.name;
                } else {
                    console.error('Feature not found:', featureId);
                    showToast('Hata: Özellik bulunamadı!', 'danger');
                    return;
                }
            }

            if (sizeId) {
                const size = productSizes[currentProduct.id]?.find(s => s.id == sizeId);
                if (size) {
                    totalPrice += parseFloat(size.additional_price);
                    sizeName = size.name;
                } else {
                    console.error('Size not found:', sizeId);
                    showToast('Hata: Boyut bulunamadı!', 'danger');
                    return;
                }
            }

            const existingItem = orderItems.find(item => 
                item.id === currentProduct.id && 
                item.feature_id == featureId && 
                item.size_id == sizeId
            );

            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                orderItems.push({
                    id: currentProduct.id,
                    name: currentProduct.name,
                    price: totalPrice,
                    image: currentProduct.image,
                    desc: currentProduct.desc,
                    quantity: 1,
                    notes: notes,
                    feature_id: featureId || null,
                    feature_name: featureName,
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
            formData.append('products', JSON.stringify(orderItems));

            $.ajax({
                url: 'add_product.php?table_id=<?php echo $table_id; ?>',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    console.log('submitOrder AJAX success:', response);
                    if (response.success) {
                        showToast(response.message, 'success');
                        if (window.opener) {
                            window.opener.postMessage({
                                action: 'refreshTableOrder',
                                tableId: <?php echo $table_id; ?>
                            }, '*');
                            console.log('Message sent to pos.php: refreshTableOrder, tableId: <?php echo $table_id; ?>');
                        }
                        setTimeout(() => {
                            window.close();
                        }, 1000);
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

        let barcode = '';

document.addEventListener('keydown', function(e) {
    // Sadece rakam ve harf ekle
    if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
        barcode += e.key;
    }

    // Enter tuşuna basınca barkodu işle
    if (e.key === 'Enter') {
        const cleanBarcode = barcode.trim();
        if (cleanBarcode.length >= 8) { // Barkod en az 8 karakter olsun
            console.log('Barkod:', cleanBarcode);
            searchProductByBarcode(cleanBarcode);
        }
        barcode = ''; // Buffer'ı temizle
        e.preventDefault(); // Enter'ın başka iş yapmasını engelle
    }
});

function searchProductByBarcode(barcode) {
    console.log('Aranan Barkod:', barcode);

    $.post('add_product.php', {
        action: 'search_by_barcode',
        barcode: barcode,
        table_id: <?php echo $table_id; ?>, // Masa ID'sini otomatik gönder
        csrf_token: '<?= $csrf_token ?>'
    }, function(res) {
        console.log('Sunucu Cevabı:', res);
        if (res.success && res.product) {
            showToast('Ürün bulundu: ' + res.product.name, 'success');
            // Ürünü sepete ekle (senin ekleme kodun varsa buraya koy)
            // Örnek basit ekleme:
            $('#cart-list').append(`
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <span>${res.product.name}</span>
                        <span>${res.product.unit_price} TL</span>
                    </div>
                </div>
            `);
        } else {
            showToast(res.message || 'Ürün bulunamadı!', 'danger');
        }
    }, 'json').fail(function() {
        showToast('Sunucu hatası!', 'danger');
    });
}   

        function addProductToCart(productId, productName, unitPrice) {
            // Sepet listesine ekle (senin sistemine uyarla)
            const cartItem = `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <span>${productName}</span>
                        <span>${unitPrice} TL</span>
                    </div>
                </div>
            `;
            $('#cart-list').append(cartItem); // Sepet ID'sini senin sayfana göre değiştir
            showToast('Ürün sepete eklendi!', 'success');
        }
    </script>

    <?php display_footer(); ?>
</body>
</html>
<?php
    if (isset($_POST['action']) && $_POST['action'] === 'search_by_barcode') {
        header('Content-Type: application/json');

        $barcode = trim($_POST['barcode'] ?? '');
        $table_id = intval($_POST['table_id'] ?? 0);

        if (empty($barcode) || $table_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz barkod veya masa ID!']);
            exit;
        }

        $query = "SELECT id, name, unit_price FROM products WHERE barcode = ? AND branch_id = ? AND stock_quantity > 0 LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param("si", $barcode, $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if ($product) {
            echo json_encode([
                'success' => true,
                'product' => $product
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ürün bulunamadı']);
        }
        exit;
    }
    ob_end_flush();
?>