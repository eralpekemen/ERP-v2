<?php
    ob_start();
    session_start();
    require_once 'config.php';
    require_once 'template.php';
    require_once 'functions/common.php';
    require_once 'functions/pos.php';
    // Saat dilimi
    date_default_timezone_set('Europe/Istanbul');
    // Oturum & Yetki Kontrolü
    if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id']) || $_SESSION['personnel_type'] != 'admin') {
        header("Location: login.php");
        exit;
    }
    $branch_id = get_current_branch();
    $personnel_id = $_SESSION['personnel_id'];
    $personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';
    $csrf_token = generate_csrf_token();
    $active_shift = get_active_shift($branch_id, $personnel_id);
    $shift_id = $active_shift['id'] ?? 0;
    // === DÜŞÜK MALZEME STOĞU ===
    $low_ingredients = 0;
    if (table_exists('ingredients')) {
        $query = "SELECT COUNT(*) FROM ingredients WHERE branch_id = ? AND current_qty <= min_qty";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $low_ingredients = $stmt->get_result()->fetch_row()[0];
    }
    // === AJAX: Manuel Kur Güncelleme ===
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == 'update_rates') {
        ob_clean();
        header('Content-Type: application/json');
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
            exit;
        }
        $usd = floatval($_POST['usd_rate'] ?? 0);
        $eur = floatval($_POST['eur_rate'] ?? 0);
        if ($usd <= 0 || $eur <= 0) {
            echo json_encode(['success' => false, 'message' => 'Kur değerleri pozitif olmalı!']);
            exit;
        }
        $query = "INSERT INTO exchange_rates (currency, rate, last_updated) VALUES
                  ('USD', ?, NOW()), ('EUR', ?, NOW())
                  ON DUPLICATE KEY UPDATE rate = VALUES(rate), last_updated = NOW()";
        $stmt = $db->prepare($query);
        $stmt->bind_param("dd", $usd, $eur);
        $success = $stmt->execute();
        if ($success) {
            error_log("Manuel kur güncellendi: USD=$usd, EUR=$eur (Branch: $branch_id)");
            echo json_encode(['success' => true, 'message' => 'Kurlar güncellendi!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Veritabanı hatası!']);
        }
        exit;
    }
    // === AJAX: TCMB'den Kur Çek ===
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_tcmb') {
        ob_clean();
        header('Content-Type: application/json');
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'CSRF hatası!']);
            exit;
        }
        $result = update_exchange_rates_with_error();
        if ($result['success']) {
            $usd = get_exchange_rate('USD');
            $eur = get_exchange_rate('EUR');
            error_log("TCMB'den kur güncellendi: USD=$usd, EUR=$eur (Branch: $branch_id)");
            echo json_encode(['success' => true, 'message' => "Kurlar güncellendi: USD=$usd, EUR=$eur"]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['error'],
                'debug' => $result['debug'] ?? ''
            ]);
        }
        exit;
    }
    // Mevcut kurlar
    $exchangeRates = [
        'USD' => get_exchange_rate('USD') ?: 45.1234,
        'EUR' => get_exchange_rate('EUR') ?: 48.5678
    ];
    // Bugünün tarihi
    $today = date('Y-m-d');
    // === SATIŞ ÖZETİ ===
    $query = "SELECT
                COUNT(*) AS total_sales,
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                SUM(CASE WHEN order_type = 'takeaway' THEN 1 ELSE 0 END) AS takeaway_count,
                SUM(CASE WHEN order_type = 'dine-in' THEN 1 ELSE 0 END) AS dinein_count
              FROM sales
              WHERE branch_id = ? AND DATE(sale_date) = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("is", $branch_id, $today);
    $stmt->execute();
    $sales_summary = $stmt->get_result()->fetch_assoc();
    // === DÜŞÜK STOKLU ÜRÜNLER ===
    $query = "SELECT name, stock_quantity
              FROM products
              WHERE branch_id = ? AND stock_quantity < 10
              ORDER BY stock_quantity ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $low_stock = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // === PERSONEL PERFORMANSI ===
    $query = "SELECT p.username, COALESCE(SUM(pp.points), 0) AS total_points
              FROM personnel p
              LEFT JOIN personnel_points pp ON p.id = pp.personnel_id AND DATE(pp.created_at) = ?
              WHERE p.branch_id = ?
              GROUP BY p.id
              ORDER BY total_points DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bind_param("si", $today, $branch_id);
    $stmt->execute();
    $personnel_performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // === AKTİF SİPARİŞLER ===
    $query = "SELECT s.id, s.order_type, s.sale_date,
                     (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS total_items,
                     (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id AND si.status = 'completed') AS completed_items
              FROM sales s
              WHERE s.branch_id = ? AND s.status = 'open'
              ORDER BY s.sale_date ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $active_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // Header
    display_header('Yönetici Paneli');
    ?>
<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <title>SABL | Yönetici Paneli</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="assets/css/vendor.min.css" rel="stylesheet">
        <link href="assets/css/app.min.css" rel="stylesheet">
        <style>
            .exchange-card input { max-width: 120px; }
            .exchange-card .btn { min-width: 100px; }
            .exchange-card .form-text { font-size: 0.85rem; }
            .exchange-card .input-group { max-width: 200px; }
            #exchange-debug { font-size: 0.8rem; color: #666; white-space: pre-wrap; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; }
            .card h5 { color: #333; }
            .badge { font-size: 0.9rem; }
            .bg-gradient-custom-teal { background: linear-gradient(135deg, #0d6efd, #0dcaf0); }
        </style>
    </head>
    <body>
        <div id="app" class="app">
            <div id="header" class="app-header">
                <div class="mobile-toggler">
                    <button type="button" class="menu-toggler" data-toggle="sidebar-mobile">
                        <span class="bar"></span>
                        <span class="bar"></span>
                    </button>
                </div>
                <div class="brand">
                    <div class="desktop-toggler">
                        <button type="button" class="menu-toggler" data-toggle="sidebar-minify">
                            <span class="bar"></span>
                            <span class="bar"></span>
                        </button>
                    </div>
                    <a href="index.php" class="brand-logo">
                        <img src="assets/img/logo.png" class="invert-dark" alt height="20">
                    </a>
                </div>
                <div class="menu">
                    <form class="menu-search" method="POST" name="header_search_form">
                        <div class="menu-search-icon"><i class="fa fa-search"></i></div>
                        <div class="menu-search-input">
                            <input type="text" class="form-control" placeholder="Arama...">
                        </div>
                    </form>
                    <div class="menu-item dropdown">
                        <a href="#" data-bs-toggle="dropdown" data-display="static" class="menu-link">
                            <div class="menu-icon"><i class="fa fa-bell nav-icon"></i></div>
                            <div class="menu-label">3</div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-notification">
                            <h6 class="dropdown-header text-body-emphasis mb-1">Bildirimler</h6>
                            <a href="#" class="dropdown-notification-item">
                                <div class="dropdown-notification-icon">
                                    <i class="fa fa-receipt fa-lg fa-fw text-success"></i>
                                </div>
                                <div class="dropdown-notification-info">
                                    <div class="title">Your store has a new order for 2 items totaling $1,299.00</div>
                                    <div class="time">just now</div>
                                </div>
                                <div class="dropdown-notification-arrow">
                                    <i class="fa fa-chevron-right"></i>
                                </div>
                            </a>
                            <a href="#" class="dropdown-notification-item">
                                <div class="dropdown-notification-icon">
                                    <i class="far fa-user-circle fa-lg fa-fw text-muted"></i>
                                </div>
                                <div class="dropdown-notification-info">
                                    <div class="title">3 new customer account is created</div>
                                    <div class="time">2 minutes ago</div>
                                </div>
                                <div class="dropdown-notification-arrow">
                                    <i class="fa fa-chevron-right"></i>
                                </div>
                            </a>
                            <a href="#" class="dropdown-notification-item">
                                <div class="dropdown-notification-icon">
                                    <img src="assets/img/icon/android.svg" alt width="26">
                                </div>
                                <div class="dropdown-notification-info">
                                    <div class="title">Your android application has been approved</div>
                                    <div class="time">5 minutes ago</div>
                                </div>
                                <div class="dropdown-notification-arrow">
                                    <i class="fa fa-chevron-right"></i>
                                </div>
                            </a>
                            <a href="#" class="dropdown-notification-item">
                                <div class="dropdown-notification-icon">
                                    <img src="assets/img/icon/paypal.svg" alt width="26">
                                </div>
                                <div class="dropdown-notification-info">
                                    <div class="title">Paypal payment method has been enabled for your store</div>
                                    <div class="time">10 minutes ago</div>
                                </div>
                                <div class="dropdown-notification-arrow">
                                    <i class="fa fa-chevron-right"></i>
                                </div>
                            </a>
                            <div class="p-2 text-center mb-n1">
                                <a href="#" class="text-body-emphasis text-opacity-50 text-decoration-none">See all</a>
                            </div>
                        </div>
                    </div>
                    <div class="menu-item dropdown">
                        <a href="#" data-bs-toggle="dropdown" data-display="static" class="menu-link">
                            <div class="menu-img online">
                                <img src="assets/img/user/user.jpg" alt class="ms-100 mh-100 rounded-circle">
                            </div>
                            <div class="menu-text"><?php echo htmlspecialchars($personnel_name); ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end me-lg-3">
                            <a class="dropdown-item d-flex align-items-center" href="profile.php">Profil<i class="fa fa-user-circle fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <a class="dropdown-item d-flex align-items-center" href="inbox.php">Mesajlar <i class="fa fa-envelope fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <a class="dropdown-item d-flex align-items-center" href="calendar.php">Takvim <i class="fa fa-calendar-alt fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <a class="dropdown-item d-flex align-items-center" href="settings.php">Ayarlar <i class="fa fa-wrench fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item d-flex align-items-center" href="logout.php">Çıkış <i class="fa fa-toggle-off fa-fw ms-auto text-body text-opacity-50"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php include('sidebar.php'); ?>
            <div id="content" class="app-content">
                <h1 class="page-header mb-3">
                Merhaba, <?php echo htmlspecialchars($personnel_name); ?>. <small>bu gün nasılsın?</small>
                </h1>
                <div class="row">
                    <div class="col-xl-12 mb-3">
                        <div class="card mb-3">
                            <div class="card-header d-flex justify-content-between">
                                <h5>Kapanış Bekleyen Vardiyalar</h5>
                                <span class="badge bg-warning" id="pending-close-count" style="display:none;">0</span>
                            </div>
                            <div class="card-body" id="pending-close-list">
                                <p class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-xl-6 mb-3">
                        <div class="card h-100 overflow-hidden">
                            <div class="card-img-overlay d-block d-lg-none bg-blue rounded"></div>
                            <div class="card-img-overlay d-none d-md-block bg-blue rounded mb-n1 mx-n1" style="background-image: url(assets/img/bg/wave-bg.png); background-position: right bottom; background-repeat: no-repeat; background-size: 100%;"></div>
                            <div class="card-img-overlay d-none d-md-block bottom-0 top-auto">
                                <div class="row">
                                    <div class="col-md-8 col-xl-6"></div>
                                    <div class="col-md-4 col-xl-6 mb-n2">
                                        <img src="assets/img/page/dashboard.svg" alt class="d-block ms-n3 mb-5" style="max-height: 310px">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body position-relative text-white text-opacity-70">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex">
                                            <div class="me-auto">
                                                <h5 class="text-white text-opacity-80 mb-3">Bugünkü Gelir</h5>
                                                <h3 class="text-white mt-n1 mb-1"><?php echo number_format($sales_summary['total_revenue'], 2); ?> ₺</h3>
                                                <p class="mb-1 text-white text-opacity-60 text-truncate">
                                                    Toplam <b><?php echo $sales_summary['total_sales']; ?></b> satış
                                                </p>
                                            </div>
                                        </div>
                                        <hr class="bg-white bg-opacity-75 mt-3 mb-3">
                                        <div class="row">
                                            <div class="col-6 col-lg-5">
                                                <div class="mt-1">
                                                    <i class="fa fa-fw fa-utensils fs-28px text-black text-opacity-50"></i>
                                                </div>
                                                <div class="mt-1">
                                                    <div>Yerinde Yemek</div>
                                                    <div class="fw-600 text-white"><?php echo $sales_summary['dinein_count']; ?> adet</div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-lg-5">
                                                <div class="mt-1">
                                                    <i class="fa fa-fw fa-shopping-bag fs-28px text-black text-opacity-50"></i>
                                                </div>
                                                <div class="mt-1">
                                                    <div>Paket Servis</div>
                                                    <div class="fw-600 text-white"><?php echo $sales_summary['takeaway_count']; ?> adet</div>
                                                </div>
                                            </div>
                                        </div>
                                        <hr class="bg-white bg-opacity-75 mt-3 mb-3">
                                        <div class="mt-3 mb-2">
                                            <a href="report_daily.php" class="btn btn-yellow btn-rounded btn-sm ps-5 pe-5 pt-2 pb-2 fs-14px fw-600">
                                                <i class="fa fa-chart-line me-2"></i> Raporu Görüntüle
                                            </a>
                                        </div>
                                        <p class="fs-12px">
                                            Günlük satış raporu için tıklayın.
                                        </p>
                                    </div>
                                    <div class="col-md-4 d-none d-md-block" style="min-height: 380px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="row">
                            <!-- 1. Yeni Siparişler -->
                            <div class="col-sm-6">
                                <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-orange" style="min-height: 199px;">
                                    <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                        <img src="assets/img/icon/order.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">
                                    </div>
                                    <div class="card-body position-relative">
                                        <h5 class="text-white text-opacity-80 mb-3 fs-16px">Yeni Siparişler</h5>
                                        <h3 class="text-white mt-n1"><?php echo $sales_summary['total_sales']; ?></h3>
                                        <div class="progress bg-black bg-opacity-50 mb-2" style="height: 6px">
                                            <div class="progress-bar progress-bar-striped bg-white" style="width: <?php echo min(100, $sales_summary['total_sales'] * 2); ?>%"></div>
                                        </div>
                                        <div class="text-white text-opacity-80 mb-4">
                                            <?php
                                                $last_week = date('Y-m-d', strtotime('-7 days'));
                                                $query = "SELECT COUNT(*) FROM sales WHERE branch_id = ? AND DATE(sale_date) = ?";
                                                $stmt = $db->prepare($query);
                                                $stmt->bind_param("is", $branch_id, $last_week);
                                                $stmt->execute();
                                                $last_week_count = $stmt->get_result()->fetch_row()[0];
                                                $change = $last_week_count > 0 ? (($sales_summary['total_sales'] - $last_week_count) / $last_week_count) * 100 : 0;
                                                $icon = $change >= 0 ? 'fa-caret-up' : 'fa-caret-down';
                                                $color = $change >= 0 ? 'text-success' : 'text-danger';
                                            ?>
                                            <i class="fa <?php echo $icon; ?> <?php echo $color; ?>"></i>
                                            <b><?php echo number_format(abs($change), 1); ?>%</b>
                                            geçen haftaya göre
                                        </div>
                                        <div><a href="report_daily.php" class="text-white d-flex align-items-center text-decoration-none">Rapor → <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                                    </div>
                                </div>
                                <!-- 2. Aktif Müşteri (Yerinde Yemek) -->
                                <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-teal" style="min-height: 199px;">
                                    <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                        <img src="assets/img/icon/visitor.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">
                                    </div>
                                    <div class="card-body position-relative">
                                        <h5 class="text-white text-opacity-80 mb-3 fs-16px">Yerinde Yemek</h5>
                                        <h3 class="text-white mt-n1"><?php echo $sales_summary['dinein_count']; ?></h3>
                                        <div class="progress bg-black bg-opacity-50 mb-2" style="height: 6px">
                                            <div class="progress-bar progress-bar-striped bg-white" style="width: <?php echo min(100, $sales_summary['dinein_count'] * 3); ?>%"></div>
                                        </div>
                                        <div class="text-white text-opacity-80 mb-4">
                                            <i class="fa fa-users text-info"></i> bugün aktif
                                        </div>
                                        <div><a href="report_daily.php#dinein" class="text-white d-flex align-items-center text-decoration-none">Detay → <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                                    </div>
                                </div>
                            </div>
                            <!-- 3. Paket Servis -->
                            <div class="col-sm-6">
                                <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-pink" style="min-height: 199px;">
                                    <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                        <img src="assets/img/icon/email.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">
                                    </div>
                                    <div class="card-body position-relative">
                                        <h5 class="text-white text-opacity-80 mb-3 fs-16px">Paket Servis</h5>
                                        <h3 class="text-white mt-n1"><?php echo $sales_summary['takeaway_count']; ?></h3>
                                        <div class="progress bg-black bg-opacity-50 mb-2" style="height: 6px">
                                            <div class="progress-bar progress-bar-striped bg-white" style="width: <?php echo min(100, $sales_summary['takeaway_count'] * 3); ?>%"></div>
                                        </div>
                                        <div class="text-white text-opacity-80 mb-4">
                                            <i class="fa fa-truck text-warning"></i> bugün gönderildi
                                        </div>
                                        <div><a href="report_daily.php#takeaway" class="text-white d-flex align-items-center text-decoration-none">Detay → <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                                    </div>
                                </div>
                                <!-- 4. Düşük Malzeme -->
                                <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-indigo" style="min-height: 199px;">
                                    <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                        <!--<img src="assets/img/icon/low-stock.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">-->
                                    </div>
                                    <div class="card-body position-relative">
                                        <h5 class="text-white text-opacity-80 mb-3 fs-16px">Düşük Malzeme</h5>
                                        <h3 class="text-white mt-n1"><?php echo $low_ingredients; ?></h3>
                                        <div class="progress bg-black bg-opacity-50 mb-2" style="height: 6px">
                                            <div class="progress-bar progress-bar-striped bg-white" style="width: <?php echo min(100, $low_ingredients * 5); ?>%"></div>
                                        </div>
                                        <div class="text-white text-opacity-80 mb-4">
                                            <?php echo $low_ingredients > 0 ? '<i class="fa fa-exclamation-triangle text-danger"></i> dikkat!' : '<i class="fa fa-check text-success"></i> yeterli'; ?>
                                        </div>
                                        <div><a href="menu_management.php#tab-ingredients" class="text-white d-flex align-items-center text-decoration-none">Stok → <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-orange" style="min-height: 199px;">
                            <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                <!--<img src="assets/img/icon/low-stock.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">-->
                            </div>
                            <div class="card-body position-relative">
                                <h5 class="text-white text-opacity-80 mb-3 fs-16px">Düşük Stok</h5>
                                <h3 class="text-white mt-n1"><?php echo $low_ingredients; ?></h3>
                                <div class="text-white text-opacity-80 mb-4">malzeme azaldı</div>
                                <div><a href="menu_management.php#tab-ingredients" class="text-white d-flex align-items-center text-decoration-none">Stok Kontrol <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card mb-3 overflow-hidden fs-13px border-0 bg-gradient-custom-teal" style="min-height: 199px;">
                            <div class="card-img-overlay mb-n4 me-n4 d-flex" style="bottom: 0; top: auto;">
                                <!--<img src="assets/img/icon/personnel.svg" alt class="ms-auto d-block mb-n3" style="max-height: 105px">-->
                            </div>
                            <div class="card-body position-relative">
                                <h5 class="text-white text-opacity-80 mb-3 fs-16px">Personel Puanı</h5>
                                <h3 class="text-white mt-n1"><?php echo array_sum(array_column($personnel_performance, 'total_points')); ?></h3>
                                <div class="text-white text-opacity-80 mb-4">bugün kazanıldı</div>
                                <div><a href="personnel_reports.php" class="text-white d-flex align-items-center text-decoration-none">Detaylar <i class="fa fa-chevron-right ms-2 text-white text-opacity-50"></i></a></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-xl-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <h5 class="mb-0">Düşük Stoklu Ürünler</h5>
                                    <a href="menu_management.php#tab-products" class="ms-auto text-decoration-none">Tümü →</a>
                                </div>
                                <div>
                                    <?php if (empty($low_stock)): ?>
                                        <p class="text-success">Tüm stoklar yeterli!</p>
                                    <?php else: foreach ($low_stock as $item): ?>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                                            <span class="text-danger"><?php echo $item['stock_quantity']; ?> adet</span>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">En Çok Satanlar</h5>
                                        <div class="fs-13px">Bu haftanın en çok satılan ürünleri</div>
                                    </div>
                                    <a href="product/product_reports.php" class="text-decoration-none">Tümü →</a>
                                </div>
                                <?php
                                // Bu haftanın başlangıç ve bitiş tarihi
                                $start_of_week = date('Y-m-d', strtotime('monday this week'));
                                $end_of_week = date('Y-m-d', strtotime('sunday this week'));
                                $query = "SELECT p.name, p.image_url, COALESCE(SUM(si.quantity), 0) AS total_sold
                                          FROM products p
                                          LEFT JOIN sale_items si ON p.id = si.product_id
                                          LEFT JOIN sales s ON si.sale_id = s.id
                                          WHERE p.branch_id = ?
                                            AND s.sale_date >= ? AND s.sale_date <= ?
                                          GROUP BY p.id
                                          ORDER BY total_sold DESC
                                          LIMIT 5";
                                $stmt = $db->prepare($query);
                                $stmt->bind_param("iss", $branch_id, $start_of_week, $end_of_week);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $bestsellers = $result->fetch_all(MYSQLI_ASSOC);
                                if (empty($bestsellers)) {
                                    echo '<div class="text-center text-muted py-4">Bu hafta satış yok.</div>';
                                } else {
                                    foreach ($bestsellers as $i => $item):
                                        $image = !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : 'https://placehold.co/250x250?text=Login+Background';
                                        ?>
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="d-flex align-items-center justify-content-center me-3 w-50px h-50px bg-white p-3px rounded overflow-hidden">
                                                <img src="<?php echo $image; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="ms-100 mh-100 object-fit-cover">
                                            </div>
                                            <div class="flex-grow-1">
                                                <div>
                                                    <?php if ($i === 0): ?>
                                                        <div class="text-primary fs-10px fw-600">LİDER SATIŞ</div>
                                                    <?php endif; ?>
                                                    <div class="text-body fw-600"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <div class="fs-13px"><?php echo number_format($item['total_sold']); ?> adet</div>
                                                </div>
                                            </div>
                                            <div class="ps-3 text-center">
                                                <div class="text-body fw-600"><?php echo $item['total_sold']; ?></div>
                                                <div class="fs-13px">satış</div>
                                            </div>
                                        </div>
                                    <?php
                                        endforeach;
                                    }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-xl-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">Son Satışlar</h5>
                                        <div class="fs-13px">Bugünkü son 5 işlem</div>
                                    </div>
                                    <a href="report_daily.php" class="text-decoration-none">Tümü →</a>
                                </div>
                                <div class="table-responsive mb-n2">
                                    <table class="table table-borderless mb-0">
                                        <thead>
                                            <tr class="text-body">
                                                <th class="ps-0">No</th>
                                                <th>Sipariş</th>
                                                <th class="text-center">Durum</th>
                                                <th class="text-end pe-0">Tutar</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recentTransactions">
                                            <?php
                                            $query = "SELECT s.id, s.order_type, s.total_amount, s.status, s.sale_date,
                                                             (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
                                                      FROM sales s
                                                      WHERE s.branch_id = ? AND DATE(s.sale_date) = ?
                                                      ORDER BY s.sale_date DESC
                                                      LIMIT 5";
                                            $stmt = $db->prepare($query);
                                            $stmt->bind_param("is", $branch_id, $today);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            $transactions = $result->fetch_all(MYSQLI_ASSOC);
                                            if (empty($transactions)) {
                                                echo '<tr><td colspan="4" class="text-center text-muted">Bugün işlem yok</td></tr>';
                                            } else {
                                                foreach ($transactions as $i => $t) {
                                                    $status_badge = match($t['status']) {
                                                        'completed' => '<span class="badge bg-success bg-opacity-20 text-success" style="min-width:60px;">Tamamlandı</span>',
                                                        'open' => '<span class="badge bg-warning bg-opacity-20 text-warning" style="min-width:60px;">Devam Ediyor</span>',
                                                        'cancelled' => '<span class="badge bg-dark bg-opacity-10 text-body" style="min-width:60px;">İptal</span>',
                                                        default => '<span class="badge bg-secondary bg-opacity-20 text-secondary" style="min-width:60px;">Bilinmiyor</span>'
                                                    };
                                                    $order_type = $t['order_type'] == 'dine-in' ? 'Yerinde' : 'Paket';
                                                    $time_ago = time_elapsed_string($t['sale_date']);
                                                    echo "<tr>
                                                            <td class='ps-0'>" . ($i + 1) . ".</td>
                                                            <td>
                                                                <div class='d-flex align-items-center'>
                                                                    <div class='w-40px h-40px rounded bg-light d-flex align-items-center justify-content-center me-3'>
                                                                        <i class='fa " . ($t['order_type'] == 'dine-in' ? 'fa-utensils' : 'fa-shopping-bag') . " text-primary'></i>
                                                                    </div>
                                                                    <div class='flex-grow-1'>
                                                                        <div class='fw-600 text-body'>#$t[id] - $order_type</div>
                                                                        <div class='fs-13px'>$t[item_count] ürün · $time_ago</div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class='text-center'>$status_badge</td>
                                                            <td class='text-end pe-0 fw-600'>" . number_format($t['total_amount'], 2) . " ₺</td>
                                                          </tr>";
                                                    }
                                                }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <h5 class="mb-0">Aktif Siparişler</h5>
                                    <a href="orders.php" class="ms-auto text-decoration-none">Tümü →</a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-borderless mb-0">
                                        <tbody>
                                            <?php if (empty($active_orders)): ?>
                                                <tr><td class="text-center text-muted">Aktif sipariş yok</td></tr>
                                            <?php else: foreach ($active_orders as $order): ?>
                                                <tr>
                                                    <td><strong>#<?php echo $order['id']; ?></strong></td>
                                                    <td><?php echo $order['order_type'] == 'dine-in' ? 'Yerinde' : 'Paket'; ?></td>
                                                    <td class="text-end"><?php echo $order['completed_items']; ?>/<?php echo $order['total_items']; ?></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KASA SAYIM MODAL -->
            <div class="modal fade" id="closeShiftModal" tabindex="-1" data-bs-backdrop="static" >
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title"><i class="fa fa-cash-register"></i> Vardiya Kapanış Sayımı</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="closingForm">
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="alert alert-info">
                                            <strong id="modal-cashier-name">Ahmet Yılmaz</strong><br>
                                            <small>Vardiya: <span id="modal-shift-id">0</span> | 
                                            Açılış: <span id="modal-opening">0.00</span> ₺</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <h4>Fark: <span id="difference-display" class="fw-bold text-danger">0.00 ₺</span></h4>
                                    </div>
                                </div>

                                <hr>

                                <h6><i class="fa fa-coins"></i> Nakit Sayımı</h6>
                                <table class="table table-sm" id="cash_table">
                                    <tbody>
                                        <tr><td>200 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="200" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>100 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="100" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>50 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="50" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>20 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="20" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>10 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="10" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>5 ₺</td><td><input type="number" class="form-control form-control-sm cash-input" data-value="5" min="0" value="0"></td><td class="total text-end">0.00 ₺</td></tr>
                                        <tr><td>Madeni Para</td><td><input type="number" step="0.01" class="form-control form-control-sm" id="coins" value="0"></td><td id="coins_total" class="text-end">0.00 ₺</td></tr>
                                    </tbody>
                                </table>

                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <label>Döviz USD</label>
                                        <input type="number" class="form-control" id="usd_count" value="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label>EUR</label>
                                        <input type="number" class="form-control" id="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label>POS Toplam</label>
                                        <input type="number" step="0.01" class="form-control" id="pos_total" value="0">
                                    </div>
                                </div>

                                <div class="mt-4 p-3 bg-light rounded">
                                    <div class="row text-center">
                                        <div class="col">
                                            <h5>Nakit Toplam</h5>
                                            <h4 id="cash_total_display">0.00 ₺</h4>
                                        </div>
                                        <div class="col">
                                            <h5>Döviz Toplam</h5>
                                            <h4 id="forex_total_display">0.00 ₺</h4>
                                        </div>
                                        <div class="col">
                                            <h5>POS Toplam</h5>
                                            <h4 id="pos_total_display">0.00 ₺</h4>
                                        </div>
                                    </div>
                                    <hr>
                                    <h3 class="text-center">GENEL TOPLAM: <span id="final_total" class="text-primary">0.00 ₺</span></h3>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="submit" class="btn btn-danger btn-lg px-5">
                                    <i class="fa fa-check"></i> Vardiyayı Kapat
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <a href="#" data-click="scroll-top" class="btn-scroll-top fade"><i class="fa fa-arrow-up"></i></a>
        </div>

        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="assets/js/vendor.min.js"></script>
        <script src="assets/js/app.min.js"></script>

        <script>
            // Kapanış bekleyenleri yükle
            function loadPendingCloses() {
                $('#pending-close-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>');

                $.post('admin/shift_handler.php', {
                    action: 'get_pending_closes',
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>'
                })
                .done(function(res) {
                    if (res.success) {
                        $('#pending-close-list').html(res.html);
                        $('#pending-close-count').text(res.count).toggle(res.count > 0);
                    } else {
                        $('#pending-close-list').html('<p class="text-danger">Sunucu hatası</p>');
                    }
                })
                .fail(function() {
                    $('#pending-close-list').html('<p class="text-danger">Bağlantı hatası</p>');
                });
            }

            // Sayfa yüklendiğinde ve her 10 saniyede bir
            $(function() {
                loadPendingCloses();
                setInterval(loadPendingCloses, 10000);
            });
            // Modal açma (vardiya kapanış isteği)
            function openCloseModal(requestId, shiftId, cashier, opening) {
                console.log('Modal açılıyor, shiftId =', shiftId); // ← BU SATIRI EKLE
                $('#request_id').val(requestId);
                $('#shift_id').val(shiftId);
                $('#cashier_name').text(cashier);
                $('#opening_balance_display').text(parseFloat(opening).toFixed(2));
                $('#closing_amount').val('').focus();
                $('#difference_display').text('');
                $('#closeShiftModal').modal('show');
            }

            // Kapanış miktarını girerken fark hesapla
            $('#closing_amount').on('input', function() {
                const closing = parseFloat($(this).val()) || 0;
                const opening = parseFloat($('#opening_balance_display').text()) || 0;
                const diff = closing - opening;
                const $diff = $('#difference_display').removeClass('text-success text-danger');
                if (diff > 0) $diff.addClass('text-success').text('+' + diff.toFixed(2) + ' TL (Fazla)');
                else if (diff < 0) $diff.addClass('text-danger').text(diff.toFixed(2) + ' TL (Eksik)');
                else $diff.addClass('text-success').text('Tam');
            });

            // Vardiya kapatma onayı
            $('#closeShiftForm').on('submit', function(e) {
                e.preventDefault();
                const closing = parseFloat($('#closing_amount').val());
                if (isNaN(closing) || closing < 0) return alert('Geçerli tutar girin!');
                const $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kapatılıyor...');
                $.post('admin/shift_handler.php', {
                    action: 'approve_close',
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>',
                    request_id: $('#request_id').val(),
                    closing_balance: closing
                }, function(r) {
                    if (r.success) {
                        alert(r.message + ' Fark: ' + r.difference + ' TL');
                        $('#closeShiftModal').modal('hide');
                        loadPendingCloses();
                    } else {
                        alert(r.message);
                    }
                    $btn.prop('disabled', false).html('Kapat');
                }, 'json');
            });

            // Kasa kapanış modalı
            let closingModal = null;
            function enterClosingBalance(shiftId, opening, cashier, date) {
                console.log('Modal açılıyor, shiftId:', shiftId);   
                $('#modal_shift_id').text(shiftId);
                $('#modal_shift_id_hidden').val(shiftId);
                $('#modal_cashier').text(cashier);
                $('#modal_opening').text(parseFloat(opening).toFixed(2));
                $('#modal_date').text(date);

                // Tüm alanları sıfırla
                $('#cash_table input').val(0);
                $('#euro_count, #usd_count, #pos1, #pos2').val(0);
                $('#euro_rate').val('<?= $exchangeRates['EUR'] ?>');
                $('#usd_rate').val('<?= $exchangeRates['USD'] ?>');

                // Beklenen toplamı çek
                $.get('admin/shift_handler.php', {
                    action: 'get_shift_totals',
                    shift_id: shiftId,
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>'
                }, function(r) {
                    if (r.success) {
                        const expected = parseFloat(r.expected);
                        $('#expected_total').text(expected.toFixed(2) + ' ₺').data('value', expected);
                        updateAll();
                    }
                }, 'json');

                // Modal aç
                closingModal = new bootstrap.Modal(document.getElementById('closingBalanceModal'));
                closingModal.show();

                // Hesaplama olayları (her açılışta yeniden bağla)
                $(document).off('input', '#cash_table input, #euro_count, #usd_count, #pos1, #pos2, #euro_rate, #usd_rate');
                $(document).on('input', '#cash_table input, #euro_count, #usd_count, #pos1, #pos2, #euro_rate, #usd_rate', updateAll);
            }

            // Hesaplama fonksiyonları
            function updateCash() {
                let total = 0;
                $('#cash_table .cash-input').each(function() {
                    const val = parseFloat($(this).val()) || 0;
                    const unit = parseFloat($(this).data('value'));
                    const lineTotal = val * unit;
                    $(this).closest('tr').find('.total').text(lineTotal.toFixed(2) + ' ₺');
                    total += lineTotal;
                });
                $('#cash_total').text(total.toFixed(2) + ' ₺');
                return total;
            }

            function updateForex() {
                const euro = (parseFloat($('#euro_count').val()) || 0) * (parseFloat($('#euro_rate').val()) || 0);
                const usd = (parseFloat($('#usd_count').val()) || 0) * (parseFloat($('#usd_rate').val()) || 0);
                $('#euro_total').text(euro.toFixed(2) + ' ₺');
                $('#usd_total').text(usd.toFixed(2) + ' ₺');
                const total = euro + usd;
                $('#forex_total').text(total.toFixed(2) + ' ₺');
                return total;
            }

            function updatePOS() {
                const pos1 = parseFloat($('#pos1').val()) || 0;
                const pos2 = parseFloat($('#pos2').val()) || 0;
                const total = pos1 + pos2;
                $('#pos_total').text(total.toFixed(2) + ' ₺');
                return total;
            }

            function updateAll() {
                const cash = updateCash();
                const forex = updateForex();
                const pos = updatePOS();
                const final = cash + forex + pos;
                $('#final_total').text(final.toFixed(2) + ' ₺');
                updateDifference();
            }

            function updateDifference() {
                const final = parseFloat($('#final_total').text().replace(' ₺', '')) || 0;
                const expected = parseFloat($('#expected_total').data('value')) || 0;
                const diff = final - expected;
                const $diff = $('#difference');
                $diff.removeClass('text-success text-danger');
                if (diff > 0) {
                    $diff.addClass('text-success').html('<i class="fa fa-arrow-up"></i> +' + diff.toFixed(2) + ' ₺ (Fazla)');
                } else if (diff < 0) {
                    $diff.addClass('text-danger').html('<i class="fa fa-arrow-down"></i> ' + diff.toFixed(2) + ' ₺ (Eksik)');
                } else {
                    $diff.addClass('text-success').html('<i class="fa fa-check"></i> Tam');
                }
            }

            // Kapanış onayla
            $('#closingBalanceForm').on('submit', function(e) {
                e.preventDefault();
                const shiftId = $('#modal_shift_id_hidden').val();
                const closing = parseFloat($('#final_total').text().replace(' ₺', ''));
                if (closing <= 0) return alert('Kapanış tutarı girin!');
                const $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kaydediliyor...');
                $.post('admin/shift_handler.php', {
                    action: 'enter_closing_balance',
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>',
                    shift_id: shiftId,
                    closing_balance: closing
                }, function(r) {
                    $btn.prop('disabled', false).html('Kasa Kapanışını Onayla');
                    if (r.success) {
                        alert('Kasa kapanışı tamamlandı! Fark: ' + r.difference + ' ₺');
                        closingModal.hide();
                        loadPendingCloses();
                    } else {
                        alert('Hata: ' + (r.message || 'Bilinmiyor'));
                    }
                }, 'json');
            });
            let currentShiftId = 0;

            function openCloseShiftModal(shiftId) {
                currentShiftId = shiftId;

                $.post('admin/shift_handler.php', {
                    action: 'get_shift_info',
                    shift_id: shiftId,
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>'
                }, function(r) {
                    if (r.success) {
                        const s = r.shift;
                        $('#modal-cashier-name').text(s.username || s.name);
                        $('#modal-shift-id').text(s.id);
                        $('#modal-opening').text(parseFloat(s.opening_balance).toLocaleString('tr-TR', {minimumFractionDigits: 2}));
                        $('#difference-display').text('0.00 ₺');

                        // Temizle
                        $('#cash_table input').val(0);
                        $('#usd_count, #eur_count, #pos_total').val(0);
                        updateCalculations();

                        $('#closeShiftModal').modal('show');
                    }
                }, 'json');
            }

            function updateCalculations() {
                let cash = 0;

                // Nakit (kağıt paralar)
                $('#cash_table .cash-input').each(function() {
                    const count = parseFloat($(this).val()) || 0;
                    const value = parseFloat($(this).data('value'));
                    const total = count * value;
                    $(this).closest('tr').find('.total').text(total.toFixed(2) + ' ₺');
                    cash += total;
                });

                // Madeni para (coins)
                const coins = parseFloat($('#coins').val()) || 0;
                cash += coins;
                $('#coins_total').text(coins.toFixed(2) + ' ₺'); // bu satır eksikse ekle

                $('#cash_total_display').text(cash.toFixed(2) + ' ₺');

                // Döviz
                const usd = parseFloat($('#usd_count').val()) || 0;
                const eur = parseFloat($('#eur_count').val()) || 0;
                const forex = (usd * 34.8) + (eur * 38.5); // güncel kur
                $('#forex_total_display').text(forex.toFixed(2) + ' ₺');

                // POS
                const pos = parseFloat($('#pos_total').val()) || 0;
                $('#pos_total_display').text(pos.toFixed(2) + ' ₺');

                // GENEL TOPLAM
                const final = cash + forex + pos;
                $('#final_total').text(final.toFixed(2) + ' ₺');

                // FARK HESAPLAMA
                const openingText = $('#modal-opening').text().replace(/\./g, '').replace(',', '.');
                const opening = parseFloat(openingText) || 0;
                const diff = final - opening;

                $('#difference-display')
                    .text((diff >= 0 ? '+' : '') + diff.toFixed(2) + ' ₺')
                    .removeClass('text-success text-danger')
                    .addClass(diff >= 0 ? 'text-success' : 'text-danger');
            }

            // Canlı hesaplama
            $(document).on('input', '#cash_table input, #usd_count, #eur_count, #pos_total, #coins', updateCalculations);

            $('#closingForm').on('submit', function(e) {
                e.preventDefault();
                const final = parseFloat($('#final_total').text().replace(/\./g,'').replace(' ₺',''));

                const btn = $(this).find('button[type="submit"]').prop('disabled', true).html('Kapatılıyor...');

                $.post('admin/shift_handler.php', {
                    action: 'close_shift_by_admin',
                    ajax: 1,
                    csrf_token: '<?= $csrf_token ?>'
                    closing_balance: final
                }, function(r) {
                    showToast(r.message + (r.difference ? ' | Fark: ' + r.difference + ' ₺' : ''), r.success ? 'success' : 'danger');
                    if (r.success) {
                        $('#closeShiftModal').modal('hide');
                        loadPendingCloses(); // listeyi yenile
                    } else {
                        btn.prop('disabled', false).html('<i class="fa fa-check"></i> Vardiyayı Kapat');
                    }
                }, 'json');
            });

            // Sayfa yüklendiğinde
            $(document).ready(function() {
                loadPendingCloses();
            });
        </script>
    </body>
</html>