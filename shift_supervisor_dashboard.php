<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Saat dilimini ayarla
date_default_timezone_set('Europe/Istanbul');

// Oturum ve vardiya sorumlusu kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id']) || $_SESSION['personnel_type'] != 'shift_supervisor') {
    header("Location: login.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// Loglama: Oturum bilgileri
error_log("shift_supervisor_dashboard.php - Session branch_id: $branch_id, personnel_id: $personnel_id, method: {$_SERVER['REQUEST_METHOD']}");

// Satış özeti
$today = date('Y-m-d');
$query = "SELECT 
            COUNT(*) as total_sales, 
            SUM(total_amount) as total_revenue, 
            SUM(CASE WHEN order_type = 'takeaway' THEN 1 ELSE 0 END) as takeaway_count,
            SUM(CASE WHEN order_type = 'dine-in' THEN 1 ELSE 0 END) as dinein_count
          FROM sales WHERE branch_id = ? AND DATE(sale_date) = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("is", $branch_id, $today);
$stmt->execute();
$sales_summary = $stmt->get_result()->fetch_assoc();

// Düşük stoklu ürünler
$query = "SELECT name, stock_quantity FROM products WHERE branch_id = ? AND stock_quantity < 10 ORDER BY stock_quantity ASC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$low_stock = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Personel performansı
$query = "SELECT p.username, SUM(pp.points) as total_points 
          FROM personnel_points pp 
          JOIN personnel p ON pp.personnel_id = p.id 
          WHERE pp.branch_id = ? AND DATE(pp.created_at) = ?
          GROUP BY pp.personnel_id 
          ORDER BY total_points DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bind_param("is", $branch_id, $today);
$stmt->execute();
$personnel_performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Aktif siparişler
$query = "SELECT s.id, s.order_type, s.sale_date, 
                 (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as total_items,
                 (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id AND si.status = 'completed') as completed_items
          FROM sales s 
          WHERE s.branch_id = ? AND s.status = 'open' 
          ORDER BY s.sale_date ASC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$active_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// HTML çıktısı için header
display_header('Vardiya Sorumlusu Paneli');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vardiya Sorumlusu Paneli">
    <meta name="author" content="SABL">
    <title>Vardiya Sorumlusu Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="/ac/assets/css/app.min.css" rel="stylesheet">
    <link href="/ac/assets/css/vendor.min.css" rel="stylesheet">
    <style>
        .dashboard-card { padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .dashboard-card h5 { margin-bottom: 15px; }
        .low-stock-item, .personnel-item, .order-item { padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
        .order-item .badge { font-size: 14px; }
        .time { font-size: 1.2rem; font-weight: bold; }
    </style>
</head>
<body class="pace-top">
<div id="app" class="app app-content-full-height app-without-sidebar app-without-header">
    <div id="content" class="app-content p-0">
        <div class="pos pos-vertical pos-with-header" id="pos">
            <div class="pos-container">
                <!-- Başlık -->
                <div class="pos-header">
                    <div class="logo">
                        <a href="shift_supervisor_dashboard.php">
                            <div class="logo-img"><i class="fa fa-bowl-rice" style="font-size: 1.5rem;"></i></div>
                            <div class="logo-text">SABL</div>
                        </a>
                    </div>
                    <div class="time" id="time">00:00</div>
                    <div class="nav">
                        <div class="nav-item">
                            <a href="shift_supervisor_dashboard.php" class="nav-link active">
                                <i class="fa fa-tachometer-alt nav-icon"></i>
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="pos_takeaway.php" class="nav-link">
                                <i class="fa fa-shopping-bag nav-icon"></i>
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="kitchen_monitor.php" class="nav-link">
                                <i class="far fa-clock nav-icon"></i>
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="logout.php" class="nav-link">
                                <i class="fa fa-sign-out-alt nav-icon"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Dashboard İçeriği -->
                <div class="pos-content">
                    <div class="pos-content-container p-3">
                        <h2 class="mb-4">Vardiya Sorumlusu Paneli</h2>
                        <div class="row g-4">
                            <!-- Satış Özeti -->
                            <div class="col-lg-4 col-md-6">
                                <div class="dashboard-card bg-light">
                                    <h5>Satış Özeti (Bugün)</h5>
                                    <p><strong>Toplam Satış:</strong> <?php echo $sales_summary['total_sales']; ?> sipariş</p>
                                    <p><strong>Toplam Gelir:</strong> <?php echo number_format($sales_summary['total_revenue'] ?? 0, 2); ?> TL</p>
                                    <p><strong>Paket Servis:</strong> <?php echo $sales_summary['takeaway_count']; ?> sipariş</p>
                                    <p><strong>Restoran İçi:</strong> <?php echo $sales_summary['dinein_count']; ?> sipariş</p>
                                </div>
                            </div>

                            <!-- Düşük Stoklu Ürünler -->
                            <div class="col-lg-4 col-md-6">
                                <div class="dashboard-card bg-light">
                                    <h5>Düşük Stoklu Ürünler</h5>
                                    <?php if (empty($low_stock)): ?>
                                        <p>Stok sorunu yok.</p>
                                    <?php else: ?>
                                        <?php foreach ($low_stock as $item): ?>
                                            <div class="low-stock-item">
                                                <span><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span class="float-end text-danger">Stok: <?php echo $item['stock_quantity']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Personel Performansı -->
                            <div class="col-lg-4 col-md-6">
                                <div class="dashboard-card bg-light">
                                    <h5>Personel Performansı (Bugün)</h5>
                                    <?php if (empty($personnel_performance)): ?>
                                        <p>Performans verisi yok.</p>
                                    <?php else: ?>
                                        <?php foreach ($personnel_performance as $person): ?>
                                            <div class="personnel-item">
                                                <span><?php echo htmlspecialchars($person['username']); ?></span>
                                                <span class="float-end"><?php echo $person['total_points']; ?> puan</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Aktif Siparişler -->
                            <div class="col-lg-12">
                                <div class="dashboard-card bg-light">
                                    <h5>Aktif Siparişler</h5>
                                    <?php if (empty($active_orders)): ?>
                                        <p>Aktif sipariş yok.</p>
                                    <?php else: ?>
                                        <?php foreach ($active_orders as $order): ?>
                                            <div class="order-item">
                                                <div>
                                                    <strong><?php echo $order['order_type'] == 'takeaway' ? 'Paket Servis' : 'Masa ' . ($order['id'] % 100); ?></strong> 
                                                    - Sipariş No: #<?php echo 9000 + $order['id']; ?>
                                                    <span class="badge <?php echo $order['order_type'] == 'takeaway' ? 'text-bg-secondary' : 'bg-theme text-theme-color'; ?> ms-2">
                                                        <?php echo $order['order_type'] == 'takeaway' ? 'Paket Servis' : 'Restoran İçi'; ?>
                                                    </span>
                                                </div>
                                                <div>Tamamlanan: <?php echo $order['completed_items']; ?>/<?php echo $order['total_items']; ?></div>
                                                <div>Sipariş Zamanı: <?php echo date('H:i', strtotime($order['sale_date'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Yukarı Kaydır Butonu -->
    <a href="#" data-click="scroll-top" class="btn-scroll-top fade"><i class="fa fa-arrow-up"></i></a>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    <script src="/ac/assets/js/vendor.min.js"></script>
    <script src="/ac/assets/js/app.min.js"></script>
    <script>
        $(document).ready(function() {
            // Saat güncelleme
            function updateTime() {
                const now = new Date();
                const time = now.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
                $('#time').text(time);
            }
            updateTime();
            setInterval(updateTime, 1000);

            // Tema seçimi
            $('[data-click="theme-selector"]').click(function() {
                const theme = $(this).data('theme');
                $('body').removeClass().addClass(theme);
                $('[data-click="theme-selector"]').removeClass('active');
                $(this).addClass('active');
            });
        });

        function showToast(message, type) {
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