<?php
    // Çıktı tamponunu başlat
    ob_start();

    session_start();

    require_once 'config.php';
    require_once 'template.php';
    require_once 'functions/common.php';
    require_once 'functions/pos.php';
    require_once 'functions/payments.php';
    require_once 'functions/notifications.php';


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

    $mode = $_GET['mode'] ?? 'table'; // table | takeaway | delivery

    // Kasa açılışı kontrolü (kasiyer için)
    $branch_id = get_current_branch();
    $personnel_id = $_SESSION['personnel_id'];
    $csrf_token = generate_csrf_token();


    $stmt = $db->prepare("SELECT is_logged_in FROM personnel WHERE id = ?");
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || $user['is_logged_in'] != 1) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }
    // TCMB API'den kur güncelle (güncel değilse)
    update_exchange_rates();

    // JS'ye aktarılacak kur (TCMB + Fallback)
    $exchangeRates = [
        'USD' => get_exchange_rate('USD') ?: 45.50,
        'EUR' => get_exchange_rate('EUR') ?: 49.20,
        'TL' => 1.0
    ];

    // Bölümler
    global $db;
    $query = "SELECT id, name FROM departments WHERE branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Masalar ve açılış saatleri
    $department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
    $query = "SELECT t.id, t.number, t.status, t.capacity, t.reserved_for, t.department_id, 
                    d.name AS department_name, 
                    s.sale_date AS open_time, 
                    COALESCE(s.total_amount, 0) AS total_amount, 
                    COALESCE(s.customer_count, 1) AS customer_count, 
                    COALESCE(s.payment_status, 'unpaid') AS payment_status, 
                    COALESCE(s.customer_name, '') AS customer_name 
            FROM tables t 
            LEFT JOIN departments d ON t.department_id = d.id 
            LEFT JOIN sales s ON t.id = s.table_id AND s.status = 'open' 
            WHERE t.branch_id = ?" . ($department_id ? " AND t.department_id = ?" : "");
    $stmt = $db->prepare($query);
    if ($department_id) {
        $stmt->bind_param("ii", $branch_id, $department_id);
    } else {
        $stmt->bind_param("i", $branch_id);
    }
    $stmt->execute();
    $tables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // Hata kontrolü ekle (döngüden önce)
    if (empty($tables)) {
        echo '<div class="alert alert-warning text-center">Masalar yüklenemedi! Veritabanı kontrol edin.</div>';
    }

    // Ürünler ve kategoriler
    $query = "SELECT id, name, icon FROM product_categories WHERE branch_id = ? ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $products = [];
    foreach ($categories as $cat) {
        $query = "SELECT id, name, unit_price, description, image_url, stock_quantity 
                FROM products WHERE category_id = ? AND branch_id = ? AND stock_quantity >= 0";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $cat['id'], $branch_id);
        $stmt->execute();
        $products[$cat['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // AKTİF VARDİYA KONTROL
    $active_shift = null;
    $stmt = $db->prepare("SELECT id, opening_balance FROM shifts WHERE personnel_id = ? AND branch_id = ? AND status = 'open'");
    $stmt->bind_param("ii", $_SESSION['personnel_id'], $branch_id);
    $stmt->execute();
    $active_shift = $stmt->get_result()->fetch_assoc();

    if (!$active_shift || ($active_shift['opening_balance'] ?? 0) <= 0) {
        header('Location: dashboard.php');
        exit;
    }
    if ($active_shift) {
        $_SESSION['shift_id'] = $active_shift['id'];  // ZORUNLU
    }

    if (
        !$active_shift || 
        ($active_shift['status'] ?? '') == 'closed' || 
        !is_null($active_shift['closing_balance'] ?? null)
    ) {
        session_unset();
        session_destroy();
        header("Location: dashboard.php?closed=1");
        exit;
    }

    // Müşteriler
    $query = "SELECT id, name FROM customers WHERE branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Ödeme yöntemleri
    $query = "SELECT id, name, currency, commission_rate FROM payment_methods WHERE branch_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $payment_methods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Loglama
    error_log("pos.php - Session branch_id: $branch_id, personnel_id: $personnel_id, department_id: $department_id, method: {$_SERVER['REQUEST_METHOD']}, get: " . print_r($_GET, true));

    // AJAX işlemleri
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
        ob_clean();
        header('Content-Type: application/json');
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
            exit;
        }
        if (isset($_POST['action']) && $_POST['action'] == 'get_table_order') {
            $table_id = intval($_POST['table_id']);
            if ($table_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz masa ID!']);
                exit;
            }
            // Masa durumunu kontrol et
            $query = "SELECT status, department_id FROM tables WHERE id = ? AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $table_id, $branch_id);
            $stmt->execute();
            $table = $stmt->get_result()->fetch_assoc();
            $table_status = $table['status'];
            $table_department_id = $table['department_id'];

            $query = "SELECT s.id, s.customer_id, s.total_amount, COALESCE(s.currency, 'TL') AS currency, COALESCE(s.payment_method_id, NULL) AS payment_method_id, s.order_type, 
                            si.product_id, si.quantity, si.unit_price, si.notes, p.name, p.image_url 
                    FROM sales s 
                    LEFT JOIN sale_items si ON s.id = si.sale_id 
                    LEFT JOIN products p ON si.product_id = p.id 
                    WHERE s.table_id = ? AND s.status = 'open' AND s.branch_id = ?";
            $stmt = $db->prepare($query);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Sorgu hazırlanamadı!', 'error' => $db->error]);
                exit;
            }
            $stmt->bind_param("ii", $table_id, $branch_id);
            if (!$stmt->execute()) {
                echo json_encode(['success' => false, 'message' => 'Sorgu çalıştırılamadı!', 'error' => $stmt->error]);
                exit;
            }
            $result = $stmt->get_result();
            $order = ['items' => [], 'table_status' => $table_status, 'sale_id' => null, 'department_id' => $table_department_id];
            while ($row = $result->fetch_assoc()) {
                $order['sale_id'] = $row['id'] ?? null;
                $order['customer_id'] = $row['customer_id'] ?? null;
                $order['total_amount'] = $row['total_amount'] ?? 0;
                $order['currency'] = $row['currency'] ?? 'TL';
                $order['payment_method_id'] = $row['payment_method_id'] ?? null;
                $order['order_type'] = $row['order_type'] ?? 'table';
                if ($row['product_id']) {
                    $order['items'][] = [
                        'product_id' => $row['product_id'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'notes' => $row['notes'],
                        'name' => $row['name'],
                        'image_url' => $row['image_url'] ?? 'https://placehold.co/150'
                    ];
                }
            }
            echo json_encode(['success' => true, 'data' => $order]);
            exit;        
        } elseif (isset($_POST['action']) && $_POST['action'] == 'process_sale') {
            ob_clean();
            header('Content-Type: application/json');
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF hatası!']);
                exit;
            }

            $table_id = intval($_POST['table_id'] ?? 0);
            $customer_id = $_POST['customer_id'] ? intval($_POST['customer_id']) : 1;
            $payment_details = json_decode($_POST['payment_details'] ?? '[]', true);
            $installments = json_decode($_POST['installments'] ?? '{}', true);
            $user_rates = json_decode($_POST['user_rates'] ?? '{}', true); // {method_id: kullanıcı_kuru}

            if ($table_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz masa!']);
                exit;
            }

            // Açık satış kontrolü
            $query = "SELECT id, total_amount FROM sales WHERE table_id = ? AND status = 'open' AND branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $table_id, $branch_id);
            $stmt->execute();
            $sale = $stmt->get_result()->fetch_assoc();
            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Açık satış yok!']);
                exit;
            }
            $sale_id = $sale['id'];
            $sale_total = floatval($sale['total_amount']);

            $total_paid_tl = 0;
            $payments = [];

            foreach ($payment_details as $p) {
                $raw_method_id = $p['method_id'];
                $amount = floatval($p['amount']);
                $currency = $p['currency'] ?? 'TL';

                if ($amount <= 0) continue;

                // AÇIK HESAP KONTROLÜ
                if ($raw_method_id === 'open_account') {
                    if (!$customer_id || $customer_id <= 1) {
                        echo json_encode(['success' => false, 'message' => 'Açık hesap için müşteri seçin!']);
                        exit;
                    }
                    $method_id = 0;
                    $commission_rate = 0;
                    $installment = 1;
                    $user_rate = 1.0; // TL varsayılan
                } else {
                    $method_id = intval($raw_method_id);
                    $method = array_values(array_filter($payment_methods, fn($m) => $m['id'] == $method_id))[0] ?? null;
                    if (!$method) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz ödeme yöntemi!']);
                        exit;
                    }
                    $commission_rate = floatval($method['commission_rate']);
                    $installment = intval($installments[$method_id] ?? 1);
                    $user_rate = floatval($user_rates[$method_id] ?? get_exchange_rate($currency)); // Kullanıcı kuru veya varsayılan
                }

                // DÖVİZ → TL DÖNÜŞÜM (Kullanıcı Kuru ile)
                $amount_tl = ($currency === 'TL') ? $amount : ($amount * $user_rate);

                // Komisyon (Yöntem + Taksit)
                $commission = $amount_tl * ($commission_rate / 100);
                $installment_commission = 0;
                if ($installment > 1 && isset($method) && $method['name'] === 'Kredi Kartı') {
                    $rates = [2=>1.5, 3=>3, 6=>5, 9=>7, 12=>9];
                    $installment_commission = $amount_tl * ($rates[$installment] ?? 0) / 100;
                }
                $total_commission = $commission + $installment_commission;

                $total_paid_tl += $amount_tl + $total_commission;

                $payments[] = [
                    'method_id' => $method_id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'amount_tl' => $amount_tl,
                    'commission' => $total_commission,
                    'installment_count' => $installment,
                    'user_rate' => $user_rate // Log için
                ];
            }

            if (round($total_paid_tl, 2) < round($sale_total, 2)) {
                echo json_encode(['success' => false, 'message' => 'Ödeme yetersiz! Kalan: ' . number_format($sale_total - $total_paid_tl, 2) . ' TL']);
                exit;
            }

            // Ödemeleri kaydet
            foreach ($payments as $p) {
                $query = "INSERT INTO payments 
                        (sale_id, payment_method_id, amount, currency, commission, installment_count, user_rate, payment_date, branch_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    error_log("Prepare failed: " . $db->error);
                    echo json_encode(['success' => false, 'message' => 'Sorgu hatası!']);
                    exit;
                }
                $stmt->bind_param("iidsddii", $sale_id, $p['method_id'], $p['amount'], $p['currency'], $p['commission'], $p['installment_count'], $p['user_rate'], $branch_id);
                if (!$stmt->execute()) {
                    error_log("Ödeme kaydedilemedi: " . $stmt->error);
                    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası!']);
                    exit;
                }
            }

            // Satışı kapat
            $query = "UPDATE sales SET status = 'completed' WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $sale_id);
            $stmt->execute();

            // Masa boşalt
            $query = "UPDATE tables SET status = 'available' WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $table_id);
            $stmt->execute();

            add_notification("Ödeme alındı: #$sale_id (TL: " . number_format($total_paid_tl, 2) . ")", 'success', $branch_id);
            echo json_encode(['success' => true, 'message' => 'Ödeme başarıyla tamamlandı! Toplam TL: ' . number_format($total_paid_tl, 2)]);
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] == 'start_break') {
            start_break($personnel_id);
            echo json_encode(['success' => true, 'message' => 'Mola başlatıldı!']);
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] == 'refresh_tables') {
            $department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;
            $query = "SELECT t.id, t.number, t.status, t.capacity, t.reserved_for, t.department_id, 
                    d.name AS department_name, 
                    s.sale_date AS open_time,
                    COALESCE(s.total_amount, 0) AS total_amount,
                    COALESCE(s.customer_count, 0) AS customer_count,
                    COALESCE(s.payment_status, 'unpaid') AS payment_status
            FROM tables t 
            LEFT JOIN departments d ON t.department_id = d.id 
            LEFT JOIN sales s ON t.id = s.table_id AND s.status = 'open' 
            WHERE t.branch_id = ?" . ($department_id ? " AND t.department_id = ?" : "");
            $stmt = $db->prepare($query);
            if ($department_id) {
                $stmt->bind_param("ii", $branch_id, $department_id);
            } else {
                $stmt->bind_param("i", $branch_id);
            }
            $stmt->execute();
            $tables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'tables' => $tables]);
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] === 'read_nfc') {
            ob_clean(); // Önceki tamponu temizle
            header('Content-Type: application/json');

            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF hatası']);
                exit;
            }

            $card_number = trim($_POST['card_number'] ?? '');

            if (empty($card_number)) {
                echo json_encode(['success' => false, 'message' => 'Kart numarası boş']);
                exit;
            }

            $query = "SELECT 
                        cc.customer_id, 
                        c.name, 
                        COALESCE(cp.points, 0) as points 
                    FROM customer_cards cc
                    JOIN customers c ON cc.customer_id = c.id
                    LEFT JOIN customer_promotions cp ON c.id = cp.customer_id
                    WHERE cc.card_number = ? AND cc.status = 'active' LIMIT 1";

            $stmt = $db->prepare($query);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Sorgu hatası: ' . $db->error]);
                exit;
            }

            $stmt->bind_param("s", $card_number);
            $stmt->execute();
            $result = $stmt->get_result();
            $card = $result->fetch_assoc();

            if ($card) {
                echo json_encode([
                    'success' => true,
                    'customer_id' => $card['customer_id'],
                    'name' => $card['name'],
                    'points' => (int)$card['points']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Kart bulunamadı veya aktif değil']);
            }
            exit; // Kritik – script burada bitir
        } elseif (isset($_POST['action']) && $_POST['action'] === 'save_customer_name') {
            header('Content-Type: application/json');
        
            // CSRF kontrol
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF hatası']);
                exit;
            }

            $table_id = (int)$_POST['table_id'];
            $name = trim($_POST['name']);

            // Açık satış var mı?
            $stmt = $db->prepare("SELECT id FROM sales WHERE table_id = ? AND status = 'open'");
            $stmt->bind_param("i", $table_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $sale = $result->fetch_assoc();

            if ($sale) {
                // VAR → GÜNCELLE
                $stmt = $db->prepare("UPDATE sales SET customer_name = ? WHERE id = ?");
                $stmt->bind_param("si", $name ?: null, $sale['id']);
            } else {
                // YOK → YENİ SATIŞ OLUŞTUR (isim kaydı için)
                $stmt = $db->prepare("INSERT INTO sales 
                    (branch_id, table_id, personnel_id, status, customer_name, total_amount, customer_count, payment_status, sale_date) 
                    VALUES (?, ?, ?, 'open', ?, 0, 1, 'unpaid', NOW())");
                $stmt->bind_param("iiis", $branch_id, $table_id, $personnel_id, $name ?: null);
            }

            $executed = $stmt->execute();

            echo json_encode([
                'success' => $executed,
                'message' => $executed ? 'İsim kaydedildi!' : 'Veritabanı hatası!',
                'debug' => $sale ? 'güncellendi' : 'yeni satış oluşturuldu'
            ]);
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] === 'save_invoice') {
            header('Content-Type: application/json');
            
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false]);
                exit;
            }

            $sale_id = intval($_POST['sale_id']);
            $tax_id = $_POST['tax_id'];
            $tax_office = $_POST['tax_office'];
            $address = $_POST['address'];

            $stmt = $db->prepare("INSERT INTO order_invoices (sale_id, tax_id, tax_office, address) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $sale_id, $tax_id, $tax_office, $address);
            $success = $stmt->execute();

            echo json_encode(['success' => $success]);
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] === 'save_order_notes') {
            header('Content-Type: application/json');

            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF hatası']);
                exit;
            }

            $table_id = intval($_POST['table_id']);
            $notes = trim($_POST['notes']);

            // Açık satış varsa notu kaydet
            $stmt = $db->prepare("UPDATE sales SET notes = ? WHERE table_id = ? AND status = 'open'");
            $stmt->bind_param("si", $notes, $table_id);
            $success = $stmt->execute();

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Not kaydedildi!' : 'Hata oluştu'
            ]);
            exit;
        }
    }

    display_header("Alçitepe Cafe - " . ($mode === 'table' ? 'Masa' : ($mode === 'takeaway' ? 'Gel-Al' : 'Paket')));
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Masa Satışı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <style>
        .pos-checkout-table { cursor: pointer; }
        .pos-checkout-table.available { background: #e0f7fa; }
        .pos-checkout-table.in-use { background: #ffebee; }
        .pos-checkout-table.disabled { background: #f5f5f5; }
        .pos-sidebar { width: 400px; position: fixed; right: 0; top: 0; bottom: 0; }
        .pos-sidebar-body { overflow-y: auto; }
        .btn-theme:disabled { background-color: #6c757d; cursor: not-allowed; opacity: 0.65; }
        .btn-department.active { background-color: #007bff; color: white; }
        .btn-department { margin-right: 5px; }
        .cancel-btn { margin-left: auto; }
        @media (max-width: 767px) {
            .pos-sidebar { width: 100%; transform: translateX(100%); transition: transform 0.3s; }
            .pos-mobile-sidebar-toggled .pos-sidebar { transform: translateX(0); }
            .btn-department { margin-bottom: 5px; }
            .cancel-btn { margin-top: 10px; }
        }
        .nav-item { margin-right: 10px; }
        .nav-link { display: flex; align-items: center; padding: 8px; border-radius: 5px; }
        .nav-link:hover { background: #f8f9fa; }
        .modal-xl { max-width: 900px; }
        .payment-row { margin-bottom: 15px; }
        .department-name { color: #007bff; font-weight: bold; }
        #numpad button { height: 50px; font-size: 1.2rem; }
        .logo-text { font-size: 2rem; font-weight: 800; color: #fff; }
        .pos-product { cursor: pointer; transition: all 0.3s; }
        .pos-product:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
        .total-price { font-size: 3rem; font-weight: 900; color: #00ff88; }
        #barcode-input { position: fixed; top: -100px; opacity: 0; }
        .mode-btn { padding: 15px; font-size: 1.2rem; }
    </style>
</head>
<body>
<div id="app" class="app app-content-full-height app-without-sidebar app-without-header">
    <input type="text" id="barcode-input" autofocus>

    <!-- MOD SEÇİCİ -->
    <div class="position-fixed top-0 start-0 p-3 bg-dark text-white">
        <div class="btn-group">
            <a href="?mode=table" class="btn <?= $mode=='table'?'btn-primary':'btn-secondary' ?> mode-btn">Masa</a>
            <a href="?mode=takeaway" class="btn <?= $mode=='takeaway'?'btn-primary':'btn-secondary' ?> mode-btn">Gel-Al</a>
            <a href="?mode=delivery" class="btn <?= $mode=='delivery'?'btn-primary':'btn-secondary' ?> mode-btn">Paket</a>
        </div>
    </div>
    <div id="content" class="app-content p-0">
        <div class="pos pos-vertical pos-with-header pos-with-sidebar" id="pos">
            <div class="pos-header">
                <div class="logo">
                    <a href="pos.php">
                        <div class="logo-img"><i class="fa fa-bowl-rice" style="font-size: 1.5rem;"></i></div>
                        <div class="logo-text">SABL | POS Sistemi</div>
                    </a>
                </div>
                <div class="nav">
                    <div class="nav-item">
                        <a href="pos_delivery.php" class="nav-link" title="Paket Satış">
                            <i class="fa fa-box nav-icon"></i>
                            <span class="ms-2">Paket</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="pos_takeaway.php" class="nav-link" title="Gel Al Satış">
                            <i class="fa fa-shopping-bag nav-icon"></i>
                            <span class="ms-2">Gel Al</span>
                        </a>
                    </div>
                    <?php if ($_SESSION['personnel_type'] == 'cashier'): ?>
                    <div class="nav-item">
                        <a href="#" class="nav-link" title="Mola Modu" data-toggle="mola">
                            <i class="fa fa-coffee nav-icon"></i>
                            <span class="ms-2">Mola</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php
                        if ($active_shift) {
                            ?><a href="#" class="btn btn-danger btn-lg shadow" onclick="closeShiftNow()">
                                <i class="bi bi-box-arrow-right"></i> Kasa Kapat
                            </a><?php
                        }
                    ?>
                </div>
            </div>

            <div class="pos-content">
                <div class="pos-content-container p-3">
                    <div class="mb-3">
                        <label class="form-label">Bölüm Seç</label>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-secondary btn-department <?php echo $department_id == 0 ? 'active' : ''; ?>" 
                                    data-department-id="0" onclick="filterTables(0)">Tüm Bölümler</button>
                            <?php foreach ($departments as $dept): ?>
                                <button type="button" class="btn btn-outline-secondary btn-department <?php echo $department_id == $dept['id'] ? 'active' : ''; ?>" 
                                        data-department-id="<?php echo $dept['id']; ?>" 
                                        onclick="filterTables(<?php echo $dept['id']; ?>)">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row gx-3" id="table-list">
                        <?php foreach ($tables as $table): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 pb-3">
                                <div class="pos-checkout-table <?php echo $table['status'] == 'occupied' ? 'in-use' : ($table['reserved_for'] ? 'disabled' : 'available'); ?>">
                                    <a href="#" class="pos-checkout-table-container" data-toggle="select-table" data-table-id="<?php echo $table['id']; ?>">
                                        <div class="pos-checkout-table-header">
                                            <div class="status"><i class="fa fa-circle"></i></div>
                                            <div class="fw-semibold">Masa</div>
                                            <div class="fw-bold fs-1"><?php echo htmlspecialchars($table['number']); ?></div>
                                            <div class="fs-13px text-body text-opacity-50">
                                                <?php 
                                                if ($department_id == 0 && !empty($table['department_name'])) {
                                                    echo '<span class="department-name">'.htmlspecialchars($table['department_name']).'</span>';
                                                } else {
                                                    echo $table['reserved_for'] ? 'Rezerve: ' . htmlspecialchars($table['reserved_for']) : 'max ' . $table['capacity'] . ' kişi';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="pos-checkout-table-info small">
                                            <div class="row">
                                                <div class="col-6 d-flex justify-content-center">
                                                    <div class="w-20px"><i class="far fa-user text-body text-opacity-50"></i></div>
                                                    <div class="w-60px">
                                                        <?php echo $table['status'] == 'occupied' ? $table['customer_count'] . ' / ' . $table['capacity'] : '0 / ' . $table['capacity']; ?>
                                                    </div>
                                                </div>
                                                <div class="col-6 d-flex justify-content-center">
                                                    <div class="w-20px"><i class="far fa-clock text-body text-opacity-50"></i></div>
                                                    <div class="w-60px"><?php echo $table['open_time'] ? date('H:i', strtotime($table['open_time'])) : '-'; ?></div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-6 d-flex justify-content-center">
                                                    <div class="w-20px"><i class="fa fa-receipt text-body text-opacity-50"></i></div>
                                                    <div class="w-60px">
                                                        <?php echo $table['status'] == 'occupied' ? number_format($table['total_amount'], 2) . ' TL' : '-'; ?>
                                                    </div>
                                                </div>
                                                <div class="col-6 d-flex justify-content-center">
                                                    <div class="w-20px"><i class="fa fa-dollar-sign <?php echo $table['status'] == 'occupied' && $table['payment_status'] == 'completed' ? 'text-success' : 'text-body text-opacity-50'; ?>"></i></div>
                                                    <div class="w-60px <?php echo $table['status'] == 'occupied' && $table['payment_status'] == 'completed' ? 'text-success' : ''; ?>">
                                                        <?php echo $table['status'] == 'occupied' ? ($table['payment_status'] == 'completed' ? 'Ödendi' : 'Ödenmedi') : '-'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($table['status'] == 'occupied' && !empty($table['customer_name'])): ?>
                                                <div class="text-center mt-2">
                                                    <small class="fw-bold text-primary"><?php echo htmlspecialchars($table['customer_name']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="pos-sidebar" id="pos-sidebar">
                <div class="pos-sidebar-header">
                    <div class="back-btn">
                        <button type="button" data-toggle-class="pos-mobile-sidebar-toggled" data-toggle-target="#pos" class="btn">
                            <i class="fa fa-chevron-left"></i>
                        </button>
                    </div>
                    <div class="icon"><i class="fa fa-plate-wheat"></i></div>
                    <div class="title" id="sidebar-title" 
                        onclick="editTableName(this)" >
                        Masa Seç
                    </div>
                    <div class="order">Sipariş: <span class="fw-semibold" id="order-id">#0000</span></div>
                    <div class="cancel-btn" id="cancel-table-btn" style="display: none;">
                        <button type="button" class="btn btn-danger btn-sm" onclick="cancelTableSelection()">
                            <i class="fa fa-times"></i> Seçimi İptal Et
                        </button>
                    </div>
                </div>
                <hr class="m-0 opacity-1">
                <div class="pos-sidebar-body" data-scrollbar="true" data-height="100%">
                    <div id="order-list">
                        <div class="h-100 d-flex align-items-center justify-content-center text-center">
                            <div>
                                <div class="mb-3 mt-5">
                                    <svg width="6em" height="6em" viewBox="0 0 16 16" class="text-gray-300" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M14 5H2v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V5zM1 4v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4H1z"/>
                                        <path d="M8 1.5A2.5 2.5 0 0 0 5.5 4h-1a3.5 3.5 0 1 1 7 0h-1A2.5 2.5 0 0 0 8 1.5z"/>
                                    </svg>
                                </div>
                                <h5>Sipariş bulunamadı</h5>
                            </div>
                        </div>
                    </div>
                    <!--<div class="mt-3">
                        <label class="form-label">Sipariş Notu / Müşteri Notu</label>
                        <textarea class="form-control" id="order-notes" rows="3" placeholder="Alerji, ekstra sos, acil vs..."></textarea>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2 w-100" onclick="saveOrderNotes()">Notu Kaydet</button>
                    </div>-->
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
                    <div class="d-flex align-items-center">
                        <div>Komisyon</div>
                        <div class="flex-1 text-end h6 mb-0" id="commission">0 TL</div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div>İndirim</div>
                        <div class="flex-1 text-end h6 mb-0" id="discount">0 TL</div>
                    </div>
                    <hr class="m-0 opacity-1">
                    <div class="d-flex align-items-center mb-2">
                        <div>Toplam</div>
                        <div class="flex-1 text-end h4 mb-0" id="total">0 TL</div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex">
                            <select id="customer_id" class="form-select w-100 me-10px" onchange="updateCustomerOptions()">
                                <option value="">Müşteri Seç</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-flex mt-2">
                            <a href="#" class="btn btn-default w-70px me-10px d-flex align-items-center justify-content-center" onclick="addProductToTable()">
                                <span>
                                    <i class="fa fa-plus fa-lg my-10px d-block"></i>
                                    <span class="small fw-semibold">Ürün Ekle</span>
                                </span>
                            </a>
                            <a href="#" class="btn btn-default w-70px me-10px d-flex align-items-center justify-content-center" onclick="generateInvoice()">
                                <span>
                                    <i class="fa fa-receipt fa-lg my-10px d-block"></i>
                                    <span class="small fw-semibold">Fatura</span>
                                </span>
                            </a>
                            <a href="#" class="btn btn-theme flex-fill d-flex align-items-center justify-content-center" id="complete-sale-btn" onclick="openPaymentModal()">
                                <span>
                                    <i class="fa fa-cash-register fa-lg my-10px d-block"></i>
                                    <span class="small fw-semibold">Ödeme Al</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mola Modu Modal -->
        <?php if ($_SESSION['personnel_type'] == 'cashier'): ?>
        <div class="modal fade" id="modalMola">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Mola Modu</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Mola moduna geçmek için onaylayın. Ekran kilitlenecek ve tekrar giriş yapmanız gerekecek.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="button" class="btn btn-warning" onclick="startBreak()">Mola Başlat</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ÖDEME MODAL V3: Döviz Komisyonlu + ARIA UYUMLU -->
        <div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Ödeme Al (Döviz Destekli)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                        <label class="form-label">Ödeme Yöntemleri</label>
                        <div id="payment-rows"></div>
                        <button type="button" class="btn btn-outline-primary w-100 mt-2" onclick="addPaymentRow()">+ Ödeme Yöntemi Ekle</button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Hesap Böl</label>
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-secondary" onclick="divideAccount(2)">2/n</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="divideAccount(3)">3/n</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="divideAccount(4)">4/n</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="divideAccount(5)">5/n</button>
                            <button type="button" class="btn btn-outline-danger" onclick="almanUsulu()">Alman Usulü</button>
                        </div>
                    </div>

                    <!-- Alman Usulü Alanı (gizli) -->
                    <div class="mb-3 d-none" id="alman-usulu-section">
                        <label class="form-label">Herkes Kendi Yediğini Öder</label>
                        <div id="alman-usulu-items"></div>
                    </div>
                        <div class="mb-3 d-none" id="points-area">
                            <div class="alert alert-info">
                                <strong>Biriken Puan:</strong> <span id="cust-points">0</span>  
                                → Kullanılabilir: <span id="usable-points">0</span> ₺
                                <input type="number" class="form-control mt-2" id="use-points" value="0" min="0" placeholder="Kullanılacak puan">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h5>Toplam: <span id="modal-total" class="text-danger">0.00 TL</span></h5>
                                <h5>Kalan: <span id="modal-remaining" class="text-warning">0.00 TL</span></h5>
                            </div>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-success btn-sm" onclick="payAll()">Tümünü Öde</button>
                            </div>
                        </div>

                        <div id="payment-rows"></div>

                        <div class="mt-3">
                            <button class="btn btn-outline-primary btn-sm" onclick="addPaymentRow()">+ Ödeme Ekle</button>
                        </div>

                        <!-- Numpad -->
                        <div class="mt-4">
                            <div class="row g-2" id="numpad">
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('7')">7</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('8')">8</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('9')">9</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('4')">4</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('5')">5</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('6')">6</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('1')">1</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('2')">2</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('3')">3</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('C')">C</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('0')">0</button></div>
                                <div class="col-4"><button class="btn btn-light w-100" onclick="numpadInput('.')">.</button></div>
                                <div class="col-12"><button class="btn btn-danger w-100" onclick="numpadClear()">Temizle</button></div>
                            </div>
                        </div>
                        <div class="text-center mb-3">
                            <button type="button" class="btn btn-outline-success icon-btn" onclick="readNFCCard()">
                                <i class="fa fa-id-card fa-2x"></i>
                            </button>
                            <p class="small mt-1">NFC Kart Okut</p>
                        </div>

                        <div id="error-alert" class="alert alert-danger mt-3" style="display:none"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button class="btn btn-success" id="confirm-payment-btn" onclick="confirmPayment()">Ödemeyi Tamamla</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- KASA AÇILIŞ MODALI -->
        <div class="modal fade" id="openingBalanceModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Kasa Açılışı Yapın</h5>
                    </div>
                    <form id="openingForm">
                        <div class="modal-body text-center">
                            <p><strong>Kasa açılış tutarını girin:</strong></p>
                            <div class="input-group mb-3">
                                <input type="number" step="0.01" min="0" class="form-control form-control-lg text-center" id="opening_amount" placeholder="0.00" required>
                                <span class="input-group-text fs-5">TL</span>
                            </div>
                            <small class="text-muted d-block">Örnek: 1000.00 (bozuk para dahil)</small>
                        </div>
                        <div class="modal-footer justify-content-center">
                            <button type="submit" class="btn btn-success btn-lg px-5" id="openCashBtn">Kasa Aç</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- FATURA MODALI -->
        <div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Fatura Bilgileri</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">TC Kimlik / Vergi No *</label>
                            <input type="text" class="form-control" id="invoice_tax_id" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Vergi Dairesi *</label>
                            <input type="text" class="form-control" id="invoice_tax_office" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adres *</label>
                            <textarea class="form-control" id="invoice_address" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="button" class="btn btn-primary" onclick="saveInvoice()">Faturayı Kaydet</button>
                    </div>
                </div>
            </div>
        </div>
        <a href="#" data-click="scroll-top" class="btn-scroll-top fade"><i class="fa fa-arrow-up"></i></a>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.min.js"></script>

    <script>
        let orderItems = [];
        let selectedTableId = null;
        let selectedSaleId = null;
        let tableStatus = 'available';
        let saleId = null;
        let selectedDepartmentId = <?php echo $department_id; ?>;
        const paymentMethods = <?php echo json_encode($payment_methods); ?>;
        
        // GÜNCELLEME: TCMB API + Fallback → JS'ye aktarılıyor
        const exchangeRates = <?= json_encode($exchangeRates, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE) ?>;
        
        let currentInput = null;
        let paymentRows = 0;

        // DETAYLI DEBUG: exchangeRates içeriği görünür
        console.log('exchangeRates (PHP → JS):', JSON.parse(JSON.stringify(exchangeRates)));

        $(document).ready(function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tableIdFromUrl = urlParams.get('table_id');
            if (tableIdFromUrl) {
                selectedTableId = parseInt(tableIdFromUrl);
                const tableElement = $(`[data-table-id="${selectedTableId}"]`);
                if (tableElement.length) {
                    $('#sidebar-title').text(' ' + tableElement.find('.fw-bold').text());
                    $('#cancel-table-btn').show();
                    loadTableOrder(selectedTableId);
                }
            }
            updateCustomerOptions();
            $(document).on('click', '[data-toggle="select-table"]', function(e) {
                e.preventDefault();
                const newTableId = $(this).data('table-id');
                selectedTableId = newTableId;
                $('#sidebar-title').text(' ' + $(this).find('.fw-bold').text());
                $('#cancel-table-btn').show();
                window.history.pushState({}, '', `pos.php?department_id=${selectedDepartmentId}&table_id=${selectedTableId}`);
                loadTableOrder(selectedTableId);
            });
            $('[data-toggle="mola"]').click(function(e) {
                e.preventDefault();
                $('#modalMola').modal('show');
            });
            setInterval(refreshTables, 10000);
            window.addEventListener('message', function(event) {
                if (event.data.action === 'refreshTableOrder' && event.data.tableId) {
                    selectedTableId = parseInt(event.data.tableId);
                    $('#cancel-table-btn').show();
                    loadTableOrder(selectedTableId);
                }
            });

            // MODAL KAPANDIĞINDA FOCUS + ARIA TEMİZLE
            $('#paymentModal').on('hidden.bs.modal', function () {
                setTimeout(() => {
                    if (!$('.modal.show').length) {
                        $(document.body).removeClass('modal-open');
                        $('.modal-backdrop').remove();
                    }
                }, 150);
            });
        });

        function updateCustomerOptions() {
            const customerVal = $('#customer_id').val();
            $('.payment-row').each(function() {
                const select = $(this).find('.payment-method');
                const hasOpenAccount = select.find('option[value="open_account"]').length > 0;
                if (customerVal && !hasOpenAccount) {
                    select.append('<option value="open_account">Açık Hesap (TL)</option>');
                } else if (!customerVal && hasOpenAccount) {
                    select.find('option[value="open_account"]').remove();
                }
                updatePaymentRow(select[0]); // HER SATIR İÇİN KUR GÜNCELLE
            });
        }

        function cancelTableSelection() {
            selectedTableId = null;
            tableStatus = 'available';
            saleId = null;
            orderItems = [];
            $('#sidebar-title').text('Masa Seç');
            $('#order-id').text('#0000');
            $('#cancel-table-btn').hide();
            window.history.pushState({}, '', `pos.php?department_id=${selectedDepartmentId}`);
            updateOrderList();
            showToast('Masa seçimi iptal edildi!', 'success');
        }

        function filterTables(departmentId) {
            selectedDepartmentId = parseInt(departmentId) || 0;
            $('.btn-department').removeClass('active');
            $(`.btn-department[data-department-id="${selectedDepartmentId}"]`).addClass('active');
            window.history.pushState({}, '', `pos.php?department_id=${selectedDepartmentId}${selectedTableId ? '&table_id=' + selectedTableId : ''}`);
            refreshTables();
        }

        function refreshTables() {
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'refresh_tables');
            formData.append('ajax', '1');
            formData.append('department_id', selectedDepartmentId);

            $.ajax({
                url: 'pos.php', type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const tableList = $('#table-list');
                        tableList.html('');
                        response.tables.forEach(table => {
                            const statusClass = table.status === 'occupied' ? 'in-use' : (table.reserved_for ? 'disabled' : 'available');
                            const openTime = table.open_time ? new Date(table.open_time).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }) : '-';
                            const departmentDisplay = selectedDepartmentId == 0 && table.department_name ? table.department_name : (table.reserved_for ? 'Rezerve: ' + table.reserved_for : 'max ' + table.capacity + ' kişi');
                            tableList.append(`
                                <div class="col-xl-3 col-lg-4 col-md-6 pb-3">
                                    <div class="pos-checkout-table ${statusClass}">
                                        <a href="#" class="pos-checkout-table-container" data-toggle="select-table" data-table-id="${table.id}">
                                            <div class="pos-checkout-table-header">
                                                <div class="status"><i class="fa fa-circle"></i></div>
                                                <div class="fw-semibold">Masa</div>
                                                <div class="fw-bold fs-1">${table.number}</div>
                                                <div class="fs-13px text-body text-opacity-50">${departmentDisplay}</div>
                                            </div>
                                            <div class="pos-checkout-table-info small">
                                                <div class="row">
                                                    <!-- KİŞİ SAYISI -->
                                                    <div class="col-6 d-flex justify-content-center">
                                                        <div class="w-20px"><i class="far fa-user text-body text-opacity-50"></i></div>
                                                        <div class="w-60px">
                                                            ${table.status === 'occupied' 
                                                                ? (table.customer_count || 1) + ' / ' + table.capacity 
                                                                : '0 / ' + table.capacity}
                                                        </div>
                                                    </div>
                                                    <!-- AÇILIŞ SAATİ -->
                                                    <div class="col-6 d-flex justify-content-center">
                                                        <div class="w-20px"><i class="far fa-clock text-body text-opacity-50"></i></div>
                                                        <div class="w-60px">${openTime}</div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <!-- TUTAR -->
                                                    <div class="col-6 d-flex justify-content-center">
                                                        <div class="w-20px"><i class="fa fa-receipt text-body text-opacity-50"></i></div>
                                                        <div class="w-60px">
                                                            ${table.status === 'occupied' && table.total_amount > 0 
                                                                ? parseFloat(table.total_amount).toFixed(2) + ' TL' 
                                                                : '-'}
                                                        </div>
                                                    </div>
                                                    <!-- ÖDEME DURUMU -->
                                                    <div class="col-6 d-flex justify-content-center">
                                                        <div class="w-20px">
                                                            <i class="fa fa-dollar-sign ${table.status === 'occupied' && table.payment_status === 'completed' ? 'text-success' : 'text-body text-opacity-50'}"></i>
                                                        </div>
                                                        <div class="w-60px ${table.status === 'occupied' && table.payment_status === 'completed' ? 'text-success' : ''}">
                                                            ${table.status === 'occupied' 
                                                                ? (table.payment_status === 'completed' ? 'Ödendi' : 'Ödenmedi') 
                                                                : '-'}
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- MÜŞTERİ ADI (varsa göster) -->
                                                ${table.status === 'occupied' && table.customer_name ? 
                                                    `<div class="text-center mt-2">
                                                        <small class="fw-bold text-primary">${table.customer_name}</small>
                                                    </div>` : ''}
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            `);
                        });
                        if (selectedTableId) loadTableOrder(selectedTableId);
                    } else showToast('Masalar yenilenemedi: ' + (response.message || 'Bilinmeyen hata'), 'danger');
                },
                error: function(xhr) { showToast('Masalar yenilenemedi: ' + (xhr.responseJSON?.message || 'Bağlantı hatası'), 'danger'); }
            });
        }

        function addProductToTable() {
            if (!selectedTableId) return showToast('Lütfen bir masa seçin!', 'danger');
            window.open('add_product.php?table_id=' + selectedTableId, '_blank');
        }

        function loadTableOrder(tableId) {
            orderItems = [];
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'get_table_order');
            formData.append('ajax', '1');
            formData.append('table_id', tableId);

            $.ajax({
                url: 'pos.php', type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        tableStatus = response.data.table_status || 'available';
                        saleId = response.data.sale_id || null;
                        selectedDepartmentId = response.data.department_id || selectedDepartmentId;
                        $('.btn-department').removeClass('active');
                        $(`.btn-department[data-department-id="${selectedDepartmentId}"]`).addClass('active');
                        orderItems = response.data.items.map(item => ({
                            id: item.product_id,
                            name: item.name,
                            price: parseFloat(item.unit_price) || 0,
                            quantity: item.quantity,
                            notes: item.notes,
                            image: item.image_url
                        }));
                        $('#order-id').text(saleId ? '#' + saleId : '#0000');
                        updateOrderList();
                    } else {
                        tableStatus = 'available';
                        saleId = null;
                        orderItems = [];
                        $('#order-id').text('#0000');
                        updateOrderList();
                        showToast(response.message || 'Sipariş yüklenemedi!', 'danger');
                    }
                },
                error: function() { showToast('Sipariş yüklenemedi!', 'danger'); }
            });
        }

        function updateOrderList() {
            const orderList = $('#order-list');
            const subtotalEl = $('#subtotal');
            const taxEl = $('#tax');
            const totalEl = $('#total');
            const completeSaleBtn = $('#complete-sale-btn');

            if (selectedTableId) $('#cancel-table-btn').show(); else $('#cancel-table-btn').hide();
            if (!selectedTableId || tableStatus === 'available' || orderItems.length === 0) completeSaleBtn.addClass('disabled').attr('disabled', true);
            else completeSaleBtn.removeClass('disabled').attr('disabled', false);

            if (orderItems.length === 0) {
                orderList.html(`<div class="h-100 d-flex align-items-center justify-content-center text-center"><div><div class="mb-3 mt-5"><svg width="6em" height="6em" viewBox="0 0 16 16" class="text-gray-300" fill="currentColor"><path fill-rule="evenodd" d="M14 5H2v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V5zM1 4v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4H1z"/><path d="M8 1.5A2.5 2.5 0 0 0 5.5 4h-1a3.5 3.5 0 1 1 7 0h-1A2.5 2.5 0 0 0 8 1.5z"/></svg></div><h5>Sipariş bulunamadı</h5></div></div>`);
                subtotalEl.text('0 TL'); taxEl.text('0 TL'); totalEl.text('0 TL');
                return;
            }

            let subtotal = 0;
            orderList.html('');
            orderItems.forEach((item, index) => {
                const itemTotal = (item.price * item.quantity).toFixed(2);
                subtotal += parseFloat(itemTotal);
                orderList.append(`
                    <div class="pos-order">
                        <div class="pos-order-product">
                            <div class="img" style="background-image: url(${item.image})"></div>
                            <div class="flex-1">
                                <div class="h6 mb-1">${item.name}</div>
                                <div class="small">${item.price.toFixed(2)} TL</div>
                                <div class="small mb-2">${item.notes || '-'}</div>
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
            subtotalEl.text(`${subtotal.toFixed(2)} TL`);
            taxEl.text(`${tax.toFixed(2)} TL`);
            $('#commission').text('0 TL');
            $('#discount').text('0 TL');
            totalEl.text(`${total.toFixed(2)} TL`);
        }

        function changeQuantity(index, delta) {
            if (orderItems[index].quantity + delta >= 1) orderItems[index].quantity += delta;
            updateOrderList();
        }

        function removeItem(index) {
            orderItems.splice(index, 1);
            updateOrderList();
        }

        function generateInvoice() {
            if (orderItems.length === 0) return showToast('Sipariş bulunamadı!', 'danger');
            let content = `<h2>Fatura</h2><p>Sipariş No: ${$('#order-id').text()}</p><p>Tarih: ${new Date().toLocaleString()}</p><table border="1" style="width:100%; border-collapse: collapse;"><tr><th>Ürün</th><th>Adet</th><th>Birim</th><th>Not</th><th>Toplam</th></tr>`;
            let subtotal = 0;
            orderItems.forEach(item => {
                const total = (item.price * item.quantity).toFixed(2);
                subtotal += parseFloat(total);
                content += `<tr><td>${item.name}</td><td>${item.quantity}</td><td>${item.price.toFixed(2)} TL</td><td>${item.notes || '-'}</td><td>${total} TL</td></tr>`;
            });
            const tax = subtotal * 0.06;
            const grand = subtotal + tax;
            content += `</table><p>Alt Toplam: ${subtotal.toFixed(2)} TL</p><p>Vergi: ${tax.toFixed(2)} TL</p><p>Toplam: ${grand.toFixed(2)} TL</p>`;
            const win = window.open('', '_blank');
            win.document.write(`<html><head><title>Fatura</title><style>body{font-family:Arial;margin:20px;}table,th,td{border:1px solid #000;padding:8px;}</style></head><body>${content}<button onclick="window.print()">Yazdır</button></body></html>`);
            win.document.close();
        }

        function openPaymentModal() {
            if (!selectedTableId || orderItems.length === 0 || tableStatus === 'available') return showToast('Geçersiz işlem!', 'danger');
            const total = parseFloat($('#total').text()) || 0;
            if (total <= 0) return showToast('Toplam sıfır!', 'danger');
            $('#modal-total').text(total.toFixed(2) + ' TL');
            $('#modal-remaining').text(total.toFixed(2) + ' TL');
            $('#payment-rows').empty();
            paymentRows = 0;
            addPaymentRow(); // İLK SATIR OTOMATİK KUR SET EDİLECEK
            $('#paymentModal').modal('show');
        }

        function addPaymentRow() {
            paymentRows++;
            const rowId = `payrow-${paymentRows}`;
            const customerVal = $('#customer_id').val();
            const openAccountOption = customerVal ? '<option value="open_account">Açık Hesap (TL)</option>' : '';
            $('#payment-rows').append(`
                <div class="payment-row row align-items-end mb-3" id="${rowId}">
                    <div class="col-md-2">
                        <select class="form-select payment-method" onchange="updatePaymentRow(this)">
                            ${paymentMethods.map(m => `<option value="${m.id}" data-currency="${m.currency}" data-commission="${m.commission_rate}">${m.name} (${m.currency})</option>`).join('')}
                            ${openAccountOption}
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control payment-amount" min="0.01" step="0.01" placeholder="0.00" onfocus="setCurrentInput(this)" oninput="updateRemaining()">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control payment-currency" readonly>
                    </div>
                    <div class="col-md-1" id="rate-col-${rowId}" style="display:none">
                        <input type="number" class="form-control user-rate" step="0.01" placeholder="Kur" 
                               oninput="updateRemaining()">
                    </div>
                    <div class="col-md-1">
                        <select class="form-select installment-select" style="display:none">
                            <option value="1">Peşin</option>
                            ${[2,3,6,9,12].map(n => `<option value="${n}">${n} Taksit</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-danger btn-sm" onclick="$('#${rowId}').remove(); updateRemaining()">Sil</button>
                    </div>
                    <div class="col-md-2 small text-muted" id="tl-preview-${rowId}">TL: 0.00</div>
                </div>
            `);
            const lastSelect = $('#payment-rows .payment-method').last()[0];
            updatePaymentRow(lastSelect); // HEMEN KUR SET ET
        }

        function updatePaymentRow(select) {
            const row = $(select).closest('.payment-row');
            const methodId = select.value;
            const method = paymentMethods.find(m => m.id == methodId) || {currency: 'TL', name: ''};
            const currency = method.currency;
            row.find('.payment-currency').val(currency);
            
            const rateCol = row.find(`#rate-col-${row.attr('id')}`);
            const isForeign = currency !== 'TL';
            rateCol.toggle(isForeign);
            
            if (isForeign) {
                const defaultRate = exchangeRates[currency] || 1;
                const input = rateCol.find('.user-rate');
                input.val(defaultRate.toFixed(4)); // KURU SET ET
                input.attr('placeholder', defaultRate.toFixed(4));
            } else {
                rateCol.find('.user-rate').val('1');
            }
            
            row.find('.installment-select').toggle(method.name === 'Kredi Kartı');
            updateRemaining();
        }

        function payAll() {
            const remaining = parseFloat($('#modal-remaining').text());
            if (remaining <= 0) return;
            $('#payment-rows .payment-amount').first().val(remaining.toFixed(2));
            updateRemaining();
        }

        function setCurrentInput(input) { currentInput = input; }

        function numpadInput(digit) {
            if (!currentInput) return;
            let val = currentInput.value;
            if (digit === 'C') val = '';
            else if (digit === '.') { if (!val.includes('.')) val += digit; }
            else val += digit;
            currentInput.value = val;
            updateRemaining();
        }

        function numpadClear() {
            if (currentInput) currentInput.value = '';
            updateRemaining();
        }

        function updateRemaining() {
            const total = parseFloat($('#modal-total').text()) || 0;
            let paid = 0;
            $('.payment-row').each(function() {
                const amount = parseFloat($(this).find('.payment-amount').val()) || 0;
                const currency = $(this).find('.payment-currency').val();
                let rate = 1;
                if (currency !== 'TL') {
                    rate = parseFloat($(this).find('.user-rate').val()) || exchangeRates[currency] || 1;
                }
                const amountTl = amount * rate;
                const rowId = $(this).attr('id');
                $(`#tl-preview-${rowId}`).text(`TL: ${amountTl.toFixed(2)}`);
                paid += amountTl;
            });
            const remaining = total - paid;
            $('#modal-remaining').text(remaining.toFixed(2) + ' TL');
            $('#confirm-payment-btn').prop('disabled', remaining > 0.01);
            $('#error-alert').hide();
        }

        function confirmPayment() {
            $('#error-alert').hide();
            const paymentDetails = [], installments = {}, userRates = {};
            let hasValid = false;

            $('.payment-row').each(function() {
                const methodSelect = $(this).find('.payment-method');
                const methodId = methodSelect.val();
                const amount = parseFloat($(this).find('.payment-amount').val()) || 0;
                const currency = $(this).find('.payment-currency').val();
                if (amount > 0 && methodId && currency) {
                    hasValid = true;
                    paymentDetails.push({ method_id: methodId, amount, currency });
                    if ($(this).find('.installment-select').is(':visible')) {
                        installments[methodId] = $(this).find('.installment-select').val();
                    }
                    if (currency !== 'TL') {
                        userRates[methodId] = parseFloat($(this).find('.user-rate').val()) || exchangeRates[currency];
                    }
                }
            });

            if (!hasValid) return showError('En az bir ödeme girin!');
            const customerId = $('#customer_id').val() || 1;

            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'complete_sale');
            formData.append('ajax', '1'); // ZORUNLU!
            formData.append('table_id', selectedTableId);
            formData.append('customer_id', customerId);
            formData.append('payment_details', JSON.stringify(paymentDetails));
            formData.append('installments', JSON.stringify(installments));
            formData.append('user_rates', JSON.stringify(userRates));

            $.ajax({
                url: 'admin/products_handler.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        $('#paymentModal').modal('hide');
                        showInvoiceModal();
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showError(r.message);
                    }
                },
                error: function(xhr) {
                    console.error('HATA:', xhr.responseText);
                    showError('Bağlantı hatası!');
                }
            });
        }

        function showError(msg) {
            $('#error-alert').text(msg).show();
        }

        function startBreak() {
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'start_break');
            formData.append('ajax', '1');
            $.ajax({
                url: 'pos.php', type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                success: function(response) {
                    if (response.success) window.location.href = 'lock_screen.php';
                    else showToast(response.message || 'Mola başlatılamadı!', 'danger');
                },
                error: () => showToast('Hata!', 'danger')
            });
        }

        function requestShiftClose() {
            if (!confirm('Kasa kapanış talebi gönderilsin mi?')) return;

            $.post('admin/shift_handler.php', {
                action: 'request_close',
                ajax: 1,
                csrf_token: '<?= $csrf_token ?>',
                shift_id: <?= $active_shift['id'] ?? 0 ?>
            }, function(r) {
                if (r.success) {
                    showToast(r.message, 'success');
                } else {
                    showToast(r.message, 'danger');
                }
            }, 'json');
        }

        function showToast(message, type) {
            const toast = $(`<div class="toast-container position-fixed top-0 end-0 p-3"><div class="toast show"><div class="toast-body bg-${type} text-white">${message}</div></div></div>`);
            $('body').append(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        function closeShiftNow() {
            if (!confirm('Vardiyanızı kapatmak istediğinize emin misiniz?\nBu işlem geri alınamaz.')) return;

            const $btn = $(event.target).closest('a');
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kapatılıyor...');

            $.post('admin/shift_handler.php', {
                action: 'close_shift_direct',
                ajax: 1,
                csrf_token: '<?= $csrf_token ?>',
                shift_id: <?= $active_shift['id'] ?? 0 ?>
            }, function(r) {
                if (r.success) {
                    showToast('Vardiya kapatıldı. Oturum sonlandı.', 'success');
                    setTimeout(() => {
                        location.href = 'dashboard.php?shift_closed=1';
                    }, 1200);
                } else {
                    alert(r.message);
                    $btn.prop('disabled', false).html('<i class="bi bi-box-arrow-right"></i> Kasa Kapat');
                }
            }, 'json');
        }

        // NFC Kart Okuma
        function readNFCCard() {
            const cardNo = prompt('Kartı okutun veya numarayı yazın:');
            if (!cardNo) return;

            $.post('pos.php', {
                action: 'read_nfc',
                card_number: cardNo,
                csrf_token: '<?= $csrf_token ?>',
                ajax: '1'  // <-- BU SATIR ÖNEMLİ! Koşulu sağlar
            }, function(res) {
                if (res.success) {
                    $('#customer_id').val(res.customer_id);
                    $('#cust-points').text(res.points);
                    $('#usable-points').text((res.points / 100).toFixed(2));
                    $('#points-area').removeClass('d-none').show();
                    showToast('Kart okundu: ' + res.name + ' (' + res.points + ' puan)', 'success');
                } else {
                    showToast(res.message || 'Sunucu hatası!', 'danger');
                }
            }, 'json').fail(function(xhr, status, error) {
                console.error('AJAX Hatası Detay:', xhr.status, xhr.responseText, error);
                showToast('Sunucu hatası! Konsolu kontrol et.', 'danger');
            });
        }
        function editTableName(element) {
            if (!selectedTableId) {
                showToast('Önce masa seçin!', 'danger');
                return;
            }

            if (element.querySelector('input')) return;

            const current = element.textContent.trim();
            const input = document.createElement('input');
            input.type = 'text';
            input.value = current === 'Masa Seç' ? '' : current;
            input.className = 'form-control form-control-lg text-center fw-bold border-0 bg-transparent';
            input.style.fontSize = '1.8rem';
            input.style.width = '100%';

            const save = () => {
                const name = input.value.trim();
                element.textContent = name || 'Masa Seç';

                $.post('pos.php', {
                    action: 'save_customer_name',
                    table_id: selectedTableId,
                    name: name,
                    csrf_token: '<?= $csrf_token ?>'
                }, function(res) {
                    showToast(res.success ? 'İsim kaydedildi!' : 'Hata!', res.success ? 'success' : 'danger');
                });
            };

            input.addEventListener('keypress', e => e.key === 'Enter' && save());
            input.addEventListener('blur', save);

            element.textContent = '';
            element.appendChild(input);
            input.focus();
            input.select();
        }
        function showInvoiceModal() {
            $('#invoiceModal').modal('show');
        }

        function saveInvoice() {
            const taxId = $('#invoice_tax_id').val().trim();
            const taxOffice = $('#invoice_tax_office').val().trim();
            const address = $('#invoice_address').val().trim();

            if (!taxId || !taxOffice || !address) {
                showToast('Lütfen tüm alanları doldurun!', 'danger');
                return;
            }

            // Ödeme tamamlandıktan sonra sale_id'yi biliyorsan buraya ekle
            $.post('pos.php', {
                action: 'save_invoice',
                sale_id: selectedSaleId || 0,
                tax_id: taxId,
                tax_office: taxOffice,
                address: address,
                csrf_token: '<?= $csrf_token ?>'
            }, function(res) {
                if (res.success) {
                    showToast('Fatura kaydedildi!', 'success');
                    $('#invoiceModal').modal('hide');
                } else {
                    showToast(res.message || 'Hata!', 'danger');
                }
            }, 'json');
        }
        
        let cardBuffer = '';
        document.addEventListener('keypress', function(e) {
            cardBuffer += e.key;
            if (cardBuffer.length >= 10) { // UID uzunluğuna göre ayarla
                const cardNo = cardBuffer.trim();
                readNFCCard(cardNo); // fonksiyonunu çağır
                cardBuffer = '';
            }
        });

        function saveOrderNotes() {
            const notes = $('#order-notes').val().trim();
            if (!selectedTableId || !notes) {
                showToast('Masa seçin veya not yazın!', 'danger');
                return;
            }

            $.post('pos.php', {
                action: 'save_order_notes',
                table_id: selectedTableId,
                notes: notes,
                csrf_token: '<?= $csrf_token ?>'
            }, function(res) {
                if (res.success) {
                    showToast('Not kaydedildi!', 'success');
                } else {
                    showToast(res.message || 'Hata!', 'danger');
                }
            }, 'json');
        }
    </script>
    
    <?php display_footer(); ?>
</body>
</html>
<?php
ob_end_flush();
?>