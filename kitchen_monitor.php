<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Saat dilimini ayarla
date_default_timezone_set('Europe/Istanbul');

// Oturum ve mutfak personeli kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id']) || $_SESSION['personnel_type'] != 'kitchen') {
    header("Location: login.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// Loglama: Oturum bilgileri
error_log("kitchen_monitor.php - Session branch_id: $branch_id, personnel_id: $personnel_id, method: {$_SERVER['REQUEST_METHOD']}");

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

    // Ürün durumunu güncelle (Complete/Cancel)
    if ($_POST['action'] == 'update_item_status') {
        $sale_item_id = intval($_POST['sale_item_id']);
        $status = $_POST['status'] == 'completed' ? 'completed' : 'canceled';

        // Ürün durumunu güncelle
        $query = "UPDATE sale_items SET status = ? WHERE id = ? AND EXISTS (SELECT 1 FROM sales WHERE id = sale_id AND branch_id = ? AND status = 'open')";
        $stmt = $db->prepare($query);
        $stmt->bind_param("sii", $status, $sale_item_id, $branch_id);
        if ($stmt->execute()) {
            // Siparişin tamamlanma durumunu kontrol et
            $query = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                      FROM sale_items WHERE sale_id = (SELECT sale_id FROM sale_items WHERE id = ?)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $sale_item_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            // Eğer tüm ürünler tamamlandıysa veya iptal edildiyse, siparişi kapat
            if ($result['total'] == $result['completed'] || $result['total'] == $result['completed'] + $db->query("SELECT COUNT(*) FROM sale_items WHERE sale_id = (SELECT sale_id FROM sale_items WHERE id = $sale_item_id) AND status = 'canceled'")->fetch_row()[0]) {
                $query = "UPDATE sales SET status = 'closed' WHERE id = (SELECT sale_id FROM sale_items WHERE id = ?)";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $sale_item_id);
                $stmt->execute();
            }

            $response['success'] = true;
            $response['message'] = "Ürün durumu güncellendi: $status";
            error_log("update_item_status: sale_item_id=$sale_item_id, status=$status");
        } else {
            $response['message'] = 'Durum güncellenemedi: ' . $stmt->error;
            error_log("update_item_status: Hata, sale_item_id=$sale_item_id, error=" . $stmt->error);
        }
        echo json_encode($response);
        exit;
    }

    $response['message'] = 'Geçersiz işlem!';
    error_log("Hata: Geçersiz action, action={$_POST['action']}");
    echo json_encode($response);
    exit;
}

// Siparişleri al
$query = "SELECT s.id, s.order_type, s.sale_date, 
                 (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as total_items,
                 (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id AND si.status = 'completed') as completed_items
          FROM sales s 
          WHERE s.branch_id = ? AND s.status = 'open' 
          ORDER BY s.sale_date ASC";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Ürün detaylarını al
$orders = [];
foreach ($sales as &$sale) {
    $query = "SELECT si.id as sale_item_id, si.product_id, si.quantity, si.notes, si.status, 
                     p.name, p.image_url,
                     (SELECT GROUP_CONCAT(pf.name, ' (+', pf.additional_price, ' TL)') 
                      FROM sale_item_features sif 
                      JOIN product_features pf ON sif.feature_id = pf.id 
                      WHERE sif.sale_item_id = si.id) as features,
                     (SELECT ps.name FROM sale_item_features sif 
                      JOIN product_sizes ps ON sif.size_id = ps.id 
                      WHERE sif.sale_item_id = si.id LIMIT 1) as size_name
              FROM sale_items si 
              JOIN products p ON si.product_id = p.id 
              WHERE si.sale_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale['id']);
    $stmt->execute();
    $sale['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $orders[] = $sale;
}

// HTML çıktısı için header
display_header('Mutfak Ekranı');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Mutfak Sipariş Sistemi">
    <meta name="author" content="SABL">
    <title>Mutfak Ekranı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="/ac/assets/css/app.min.css" rel="stylesheet">
    <link href="/ac/assets/css/vendor.min.css" rel="stylesheet">
    <style>
        .pos-task-product.completed .pos-task-product-img .cover { opacity: 0.5; }
        .pos-task-product-img { position: relative; }
        .pos-task-product-img .cover { width: 100%; height: 150px; background-size: cover; background-position: center; border-radius: 8px; }
        .pos-task-product-img .caption { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.7); color: white; padding: 5px 10px; border-radius: 4px; }
        .pos-task-info .time { color: red; }
        .pos-task-info .badge { font-size: 14px; }
        .pos-task-product-action .btn { width: 100px; margin: 5px; }
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
                        <a href="pos.php">
                            <div class="logo-img"><i class="fa fa-bowl-rice" style="font-size: 1.5rem;"></i></div>
                            <div class="logo-text">SABL</div>
                        </a>
                    </div>
                    <div class="time" id="time">00:00</div>
                    <div class="nav">
                        <div class="nav-item">
                            <a href="kitchen_monitor.php" class="nav-link active">
                                <i class="far fa-clock nav-icon"></i>
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="pos_table_booking.php" class="nav-link">
                                <i class="far fa-calendar-check nav-icon"></i>
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="pos_menu_stock.php" class="nav-link">
                                <i class="fa fa-chart-pie nav-icon"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Siparişler -->
                <div class="pos-content">
                    <div class="pos-content-container p-0">
                        <?php if (empty($orders)): ?>
                            <div class="text-center p-5">
                                <h5>Aktif sipariş bulunamadı</h5>
                            </div>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <div class="pos-task">
                                    <div class="pos-task-info">
                                        <div class="h3 mb-1"><?php echo $order['order_type'] == 'takeaway' ? 'Paket Servis' : 'Masa ' . htmlspecialchars($order['id'] % 100); ?></div>
                                        <div class="mb-3">Sipariş No: #<?php echo 9000 + $order['id']; ?></div>
                                        <div class="mb-2">
                                            <span class="badge <?php echo $order['order_type'] == 'takeaway' ? 'text-bg-secondary' : 'bg-theme text-theme-color'; ?> rounded-1 fs-14px">
                                                <?php echo $order['order_type'] == 'takeaway' ? 'Paket Servis' : 'Restoran İçi'; ?>
                                            </span>
                                        </div>
                                        <div class="time"><?php echo date('H:i', strtotime($order['sale_date'])); ?> time</div>
                                    </div>
                                    <div class="pos-task-body">
                                        <div class="fs-16px mb-3">
                                            Tamamlanan: (<?php echo $order['completed_items']; ?>/<?php echo $order['total_items']; ?>)
                                        </div>
                                        <div class="row gx-4">
                                            <?php foreach ($order['items'] as $item): ?>
                                                <div class="col-lg-3 col-sm-6 mb-5 mb-lg-0">
                                                    <div class="pos-task-product <?php echo $item['status'] == 'completed' ? 'completed' : ''; ?>">
                                                        <div class="pos-task-product-img">
                                                            <div class="cover" style="background-image: url(<?php echo $item['image_url'] ?: 'https://placehold.co/150'; ?>)"></div>
                                                            <?php if ($item['status'] == 'completed'): ?>
                                                                <div class="caption">
                                                                    <div>Tamamlandı</div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="pos-task-product-info">
                                                            <div class="flex-1">
                                                                <div class="d-flex mb-1">
                                                                    <div class="fs-5 mb-0 fw-semibold flex-1"><?php echo htmlspecialchars($item['name']); ?></div>
                                                                    <div class="fs-5 mb-0 fw-semibold">x<?php echo $item['quantity']; ?></div>
                                                                </div>
                                                                <div class="text-body text-opacity-75">
                                                                    <?php
                                                                    $options = [];
                                                                    if ($item['size_name']) $options[] = $item['size_name'];
                                                                    if ($item['features']) $options[] = $item['features'];
                                                                    if ($item['notes']) $options[] = 'Not: ' . htmlspecialchars($item['notes']);
                                                                    echo $options ? implode('<br>', $options) : '-';
                                                                    ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="pos-task-product-action">
                                                            <a href="#" class="btn btn-theme fw-semibold <?php echo $item['status'] == 'completed' ? 'disabled' : ''; ?>" 
                                                               onclick="updateItemStatus(<?php echo $item['sale_item_id']; ?>, 'completed'); return false;">Tamamla</a>
                                                            <a href="#" class="btn btn-default fw-semibold <?php echo $item['status'] == 'completed' ? 'disabled' : ''; ?>" 
                                                               onclick="updateItemStatus(<?php echo $item['sale_item_id']; ?>, 'canceled'); return false;">İptal</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

            // Tema seçimi (mevcut JS dosyasına bağlı)
            $('[data-click="theme-selector"]').click(function() {
                const theme = $(this).data('theme');
                $('body').removeClass().addClass(theme);
                $('[data-click="theme-selector"]').removeClass('active');
                $(this).addClass('active');
            });
        });
        function updateItemStatus(saleItemId, status) {

            $.ajax({
                url: 'kitchen_monitor.php',
                type: 'POST',
                data: {
                    csrf_token: '<?php echo $csrf_token; ?>',
                    action: 'update_item_status',
                    sale_item_id: saleItemId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    console.log('updateItemStatus AJAX success:', response);
                    if (response.success) {
                        showToast(response.message, 'success');
                        location.reload(); // Sayfayı yenile
                    } else {
                        showToast(response.message || 'Durum güncellenemedi!', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('updateItemStatus AJAX error:', xhr.status, xhr.responseText);
                    showToast('Hata: ' + (xhr.responseJSON?.message || 'Sunucu hatası!'), 'danger');
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