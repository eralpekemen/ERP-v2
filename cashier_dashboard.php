<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

date_default_timezone_set('Europe/Istanbul');

if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id']) || $_SESSION['personnel_type'] != 'cashier') {
    header("Location: login.php");
    exit;
}

$branch_id = $_SESSION['branch_id']; // get_current_branch yerine direkt session
$personnel_id = $_SESSION['personnel_id'];
$personnel_name = $_SESSION['personnel_username'] ?? 'Kasiyer';
$csrf_token = generate_csrf_token();

// Verileri çek (yeni fonksiyonlar common.php'ye eklenecek)
$personnel = get_personnel_by_id($personnel_id);
$active_shift = get_active_shift($personnel_id);
$sale_points = calculate_sales_points($personnel_id);
$notifications = get_notifications_for_personnel($personnel_id);
$tasks = get_personnel_tasks($personnel_id);
$leave_requests = get_leave_requests($personnel_id);
$documents = get_personnel_documents($personnel_id);

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF!']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'add_leave_request':
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';
            $reason = $_POST['reason'] ?? '';
            if (add_leave_request($personnel_id, $start, $end, $reason)) {
                add_notification("Yeni izin talebi: $start - $end (Personel #$personnel_id)", 'info', $branch_id);
                echo json_encode(['success' => true, 'message' => 'Talep gönderildi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Hata!']);
            }
            exit;

        case 'upload_document':
            $type = $_POST['document_type'] ?? '';
            if (isset($_FILES['file']) && upload_document($personnel_id, $_FILES['file'], $type)) {
                echo json_encode(['success' => true, 'message' => 'Belge yüklendi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Yükleme hatası!']);
            }
            exit;

        case 'update_profile':
            $username = $_POST['username'] ?? '';
            $photo = isset($_FILES['photo']) ? upload_photo($_FILES['photo'], $personnel_id) : null;
            if (update_personnel_profile($personnel_id, $username, $photo)) {
                $_SESSION['personnel_username'] = $username;
                echo json_encode(['success' => true, 'message' => 'Profil güncellendi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Hata!']);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
            exit;
    }
}

// Header (admin_dashboard gibi, ama header.php yok, inline yap)
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>SABL | Kasiyer Paneli</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap JS vb. -->
</head>
<body>
    <!-- HEADER (mevcut pos.php veya admin_dashboard'tan kopya) -->
    <div id="header" class="app-header">
        <div class="brand">
            <a href="cashier_dashboard.php" class="brand-logo">
                <img src="assets/img/logo.png" alt height="20">
            </a>
        </div>
        <div class="menu">
            <!-- Şube dropdown (branches.php'den) -->
            <div class="menu-item dropdown">
                <a href="#" class="menu-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-building me-1"></i> Şube: <?php echo get_branch_name($branch_id); ?>
                </a>
                <div class="dropdown-menu">
                    <?php
                    $branches = get_all_branches();
                    foreach ($branches as $b) {
                        echo '<a class="dropdown-item" href="switch_branch.php?id='.$b['id'].'">'.$b['name'].'</a>';
                    }
                    ?>
                </div>
            </div>
            <!-- Bildirim -->
            <div class="menu-item dropdown">
                <a href="#" data-bs-toggle="dropdown" class="menu-link">
                    <i class="fa fa-bell nav-icon"></i>
                    <span class="menu-label"><?php echo count($notifications); ?></span>
                </a>
                <div class="dropdown-menu dropdown-notification">
                    <h6 class="dropdown-header">Bildirimler</h6>
                    <?php foreach ($notifications as $n): ?>
                        <a href="#" class="dropdown-notification-item">
                            <div class="title"><?php echo htmlspecialchars($n['title']); ?></div>
                            <div class="time"><?php echo $n['created_at']; ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Profil -->
            <div class="menu-item dropdown">
                <a href="#" data-bs-toggle="dropdown" class="menu-link">
                    <div class="menu-text"><?php echo htmlspecialchars($personnel_name); ?></div>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">Profil</a>
                    <a class="dropdown-item" href="logout.php">Çıkış</a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'cashier_sidebar.php'; ?>

    <div id="content" class="app-content">
        <h1 class="page-header">Merhaba, <?php echo htmlspecialchars($personnel_name); ?>.</h1>
        <div class="row">
            <!-- Kartlar -->
            <div class="col-xl-6">
                <div class="card mb-3">
                    <div class="card-header"><h5>Vardiyalarım</h5></div>
                    <div class="card-body">
                        <?php if ($active_shift): ?>
                            <p>Aktif Vardiya: #<?php echo $active_shift['id']; ?> - Açılış: <?php echo number_format($active_shift['opening_balance'], 2); ?> TL</p>
                            <button class="btn btn-danger" onclick="requestClose(<?php echo $active_shift['id']; ?>)">Vardiya Kapat</button>
                        <?php else: ?>
                            <p>Aktif vardiya yok. <a href="shift_management.php" class="btn btn-success">Vardiya Aç</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card mb-3">
                    <div class="card-header"><h5>Satış Puanım</h5></div>
                    <div class="card-body">
                        <h3><?php echo $sale_points; ?> Puan</h3>
                    </div>
                </div>
            </div>
            <!-- Diğer kartlar (mesajlar, görevler, izinler, belgeler) benzer şekilde -->
            <div class="col-xl-6">
                <div class="card mb-3">
                    <div class="card-header"><h5>Mesajlar</h5></div>
                    <div class="card-body">
                        <ul class="list-group">
                            <?php foreach ($notifications as $n): ?>
                                <li class="list-group-item"><?php echo htmlspecialchars($n['message']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- Devam et... -->
        </div>
    </div>

    <!-- Modallar (izin, belge, profil) -->
    <div class="modal fade" id="leaveModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="leaveForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_leave_request">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <label>Başlangıç Tarihi</label>
                        <input type="date" name="start_date" required>
                        <label>Bitiş Tarihi</label>
                        <input type="date" name="end_date" required>
                        <label>Sebep</label>
                        <textarea name="reason" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Diğer modallar benzer şekilde -->

    <script>
        // AJAX formlar
        $('#leaveForm').submit(function(e) {
            e.preventDefault();
            let formData = new FormData(this);
            formData.append('ajax', '1');
            $.ajax({
                url: 'cashier_dashboard.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(r) {
                    alert(r.message);
                    if (r.success) location.reload();
                }
            });
        });
        // Benzer şekilde diğer formlar

        function requestClose(shiftId) {
            $.post('shift_handler.php', { action: 'request_close', shift_id: shiftId, ajax: 1, csrf_token: '<?php echo $csrf_token; ?>' }, function(r) {
                alert(r.message);
                if (r.success) location.reload();
            }, 'json');
        }
    </script>
</body>
</html>