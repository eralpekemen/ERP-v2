<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start();
    session_start();
    require_once 'config.php';
    require_once 'template.php';
    require_once 'functions/common.php';
    require_once 'functions/notifications.php';
    require_once 'functions/shifts.php';

    // Oturum kontrolü
    if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id'])) {
        error_log("dashboard.php: Session check failed, redirecting to login.php");
        header("Location: login.php");
        exit;
    }


    $force_close = isset($_GET['close']) && $_GET['close'] == '1';
    $branch_id = get_current_branch();
    $personnel_id = $_SESSION['personnel_id'];
    $csrf_token = generate_csrf_token();
    $personnel_type = $_SESSION['personnel_type'] ?? 'cashier';
    $personnel_name = $_SESSION['personnel_username'] ?? 'Kasiyer';

    // VARDİYA KONTROL
    $active_shift = get_active_shift($branch_id, $personnel_id);

    // GÜVENLİ DEĞERLER
    $opening_balance = $active_shift['opening_balance'] ?? 0;
    $shift_status = $active_shift['status'] ?? '';

    // VARDİYA AÇIK MI?
    $closing_requested = !empty($active_shift['closing_requested']);   // ← EKLENDİ
    $is_shift_open = $active_shift && ($active_shift['status'] ?? '') === 'open' && ($active_shift['opening_balance'] ?? 0) > 0;
    $shift_id = $active_shift['id'] ?? 0;

    $show_opening_modal = false;
    $show_closing_modal = false;

    $exchangeRates = [
        'USD' => get_exchange_rate('USD') ?? 34.50,
        'EUR' => get_exchange_rate('EUR') ?? 38.20
    ];
    $js_exchangeRates = json_encode($exchangeRates);

    if ($_SESSION['personnel_type'] == 'cashier') {
        if (!$active_shift || ($active_shift['opening_balance'] ?? 0) <= 0) {
        } elseif (is_null($active_shift['closing_balance'])) {
            $show_closing_modal = true;
        } else {
            // VARDİYA TAMAMEN KAPALI → DASHBOARD'TA KAL
        }
    } else {
        header("Location: admin_dashboard.php");
        exit;
    }

    // AJAX İŞLEMLER
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
        ob_clean();
        header('Content-Type: application/json');

        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
            exit;
        }

        if ($_POST['action'] == 'open_shift') {
            $opening_balance = floatval($_POST['opening_balance'] ?? 0);
            if ($opening_balance <= 0) {
                echo json_encode(['success' => false, 'message' => 'Açılış tutarı pozitif olmalı!']);
                exit;
            }

            // ZATEN AÇIK VARDİYA VARSA ENGELLE
            if ($is_shift_open) {
                echo json_encode(['success' => false, 'message' => 'Vardiya zaten açık!']);
                exit;
            }   

            if ($active_shift) {
                $stmt = $db->prepare("UPDATE shifts SET opening_balance = ?, start_time = NOW(), status = 'open' WHERE id = ?");
                $stmt->bind_param("di", $opening_balance, $active_shift['id']);
            } else {
                $stmt = $db->prepare("INSERT INTO shifts (personnel_id, branch_id, opening_balance, status, start_time) VALUES (?, ?, ?, 'open', NOW())");
                $stmt->bind_param("iid", $personnel_id, $branch_id, $opening_balance);
            }
            $stmt->execute();
            $new_shift_id = $active_shift['id'] ?? $db->insert_id;

            echo json_encode([
                'success' => true,
                'message' => 'Vardiya açıldı!',
                'shift_id' => $new_shift_id,
                'opening_balance' => $opening_balance
            ]);
            exit;
        }

        if ($_POST['action'] == 'close_shift') {
            $shift_id = intval($_POST['shift_id'] ?? 0);
            $closing_balance = floatval($_POST['closing_balance'] ?? 0);
            if ($closing_balance < 0 || $shift_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
                exit;
            }

            $stmt = $db->prepare("SELECT opening_balance FROM shifts WHERE id = ? AND personnel_id = ? AND branch_id = ? AND status = 'open'");
            $stmt->bind_param("iii", $shift_id, $personnel_id, $branch_id);
            $stmt->execute();
            $shift = $stmt->get_result()->fetch_assoc();

            if (!$shift) {
                echo json_encode(['success' => false, 'message' => 'Vardiya bulunamadı!']);
                exit;
            }

            $opening = $shift['opening_balance'] ?? 0;
            $difference = $closing_balance - $opening;

            $stmt = $db->prepare("UPDATE shifts SET closing_balance = ?, status = 'open', end_time = NOW() WHERE id = ?");
            $stmt->bind_param("di", $closing_balance, $shift_id);
            $stmt->execute();

            // Nakit hareketi
            $stmt_cash = $db->prepare("INSERT INTO cash_registers (shift_id, amount, transaction_type, payment_type, created_at, branch_id) VALUES (?, ?, 'closing', 'cash', NOW(), ?)");
            $stmt_cash->bind_param("idi", $shift_id, $closing_balance, $branch_id);
            $stmt_cash->execute();

            unset($_SESSION['shift_id']);
            echo json_encode([
                'success' => true,
                'message' => 'Vardiya kapatıldı!',
                'difference' => number_format($difference, 2),
                'shift_id' => $shift_id,  // EKLE
                'redirect' => 'daily_close.php'
            ]);
            exit;
        }
        if ($_POST['action'] == 'add_leave_request') {
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';
            $reason = $_POST['reason'] ?? '';
            if (empty($start) || empty($end) || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Tüm alanlar zorunlu!']);
                exit;
            }
            if (add_leave_request($personnel_id, $start, $end, $reason)) {
                add_notification("Yeni izin talebi: $start - $end", 'info', $branch_id);
                echo json_encode(['success' => true, 'message' => 'Talep gönderildi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Veritabanı hatası!']);
            }
            exit;
        }
        if ($_POST['action'] === 'request_close') {
            ob_clean();
            header('Content-Type: application/json');

            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF hatası']);
                exit;
            }

            if (!$is_shift_open) {
                echo json_encode(['success' => false, 'message' => 'Açık vardiya bulunamadı!']);
                exit;
            }

            if ($closing_requested) {
                echo json_encode(['success' => false, 'message' => 'Zaten kapanış talebiniz var!']);
                exit;
            }

            // Güvenli sorgu
            $stmt = $db->prepare("UPDATE shifts SET closing_requested = 1, request_time = NOW(),status = 'open' WHERE id = ? AND personnel_id = ?");
            $stmt->bind_param("ii", $shift_id, $personnel_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                add_notification(
                    "$personnel_name vardiyasını kapatmak istiyor (Vardiya #$shift_id)",
                    'closing_request',
                    $branch_id,
                    null,
                    'shift',
                    $shift_id
                );
                echo json_encode(['success' => true, 'message' => 'Kapanış talebiniz gönderildi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Talep gönderilemedi!']);
            }
            exit;
        }
        if ($_POST['action'] == 'get_leave_details') {
            $leave_id = intval($_POST['leave_id'] ?? 0);
            if ($leave_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz izin ID']);
                exit;
            }
            $query = "SELECT start_date, end_date, reason FROM leave_requests WHERE id = ? AND personnel_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $leave_id, $personnel_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $leave = $result->fetch_assoc();
            if ($leave) {
                echo json_encode([
                    'success' => true,
                    'start_date' => $leave['start_date'],
                    'end_date' => $leave['end_date'],
                    'reason' => $leave['reason']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'İzin talebi bulunamadı']);
            }
            exit;
        }
        if ($_POST['action'] === 'upload_document') {
            $document_type_id = intval($_POST['document_type_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $file = $_FILES['document_file'] ?? null;

            if ($document_type_id <= 0 || !$file || $file['error'] != 0) {
                echo json_encode(['success' => false, 'message' => 'Eksik veya hatalı dosya!']);
                exit;
            }

            $upload_dir = 'uploads/documents/' . $personnel_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $file_name = time() . '_' . basename($file['name']);
            $file_path = $upload_dir . $file_name;

            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                $stmt = $db->prepare("INSERT INTO personnel_documents (personnel_id, document_type_id, file_name, original_name, notes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $original_name = $file['name'];
                $stmt->bind_param("iisssi", $personnel_id, $document_type_id, $file_name, $original_name, $notes, $personnel_id);
                $stmt->execute();

                echo json_encode(['success' => true, 'message' => 'Evrak yüklendi!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi!']);
            }
            exit; // KRİTİK – HTML dönmesini engeller
        }
        
        echo json_encode(['success' => false, 'message' => 'Geçersiz action']);
        exit;
    }
    $notifications = get_notifications_for_personnel($personnel_id, 5);
    $tasks = get_personnel_tasks($personnel_id, 3);
    $leave_requests = get_leave_requests($personnel_id, 7);
    $sale_points = calculate_sales_points($personnel_id);
    display_header('Kasiyer Paneli');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasa Yönetim Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <!-- PerfectScrollbar CDN -->
<link href="https://cdn.jsdelivr.net/npm/perfect-scrollbar@1.5.5/css/perfect-scrollbar.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/perfect-scrollbar@1.5.5/dist/perfect-scrollbar.min.js"></script>
    <style>
        .card-icon { font-size: 2.5rem; opacity: 0.3; }
        .dashboard-container { max-width: 1000px; margin: 30px auto; margin-top:0px}
        .toast-container { z-index: 1055; }
        #cash_table input { max-width: 80px; }
    </style>
</head>
    <body>  
    <div id="app" class="app">
        <?php include('cashier_sidebar.php'); ?>
        <div id="content" class="app-content">
            <div class="dashboard-container">
                <!-- HOŞ GELDİN -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">Merhaba, <?php echo htmlspecialchars($personnel_name); ?>!</h1>
                    <div id="shift-status-container">
                        <?php if (!$is_shift_open): ?>
                            <button id="open-shift-btn" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#openingModal">
                                Vardiya Aç
                            </button>
                        <?php else: ?>                        
                            <div class="alert alert-success">
                                <strong>Vardiya #<?= $shift_id ?> aktif</strong><br>
                                Açılış bakiyesi: <?= number_format($active_shift['opening_balance'], 2) ?> ₺
                            </div>
                            <?php if ($closing_requested): ?>
                                <button class="btn btn-warning btn-lg px-5" disabled>
                                    <i class="fa fa-clock"></i> Kapanış Talebi Gönderildi (Bekleniyor)
                                </button>
                            <?php else: ?>
                                <button class="btn btn-danger btn-lg px-5" data-bs-toggle="modal" data-bs-target="#closeModal">
                                    <i class="fa fa-power-off"></i> Vardiyayı Kapatmak İstiyorum
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KARTLAR -->
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="card border-left-primary shadow h-100 vardiya-kart">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-xs text-primary fw-bold">VARDİYA</div>
                                    <div class="h5 mb-0">
                                        <?php if ($is_shift_open): ?>
                                            <?php echo number_format($opening_balance, 2); ?> TL
                                            <button class="btn btn-sm btn-danger float-end" data-bs-toggle="modal" data-bs-target="#closeModal">
                                                Kapat
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">Kapalı</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <i class="fa fa-clock card-icon text-primary"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-left-success shadow h-100">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-xs text-success fw-bold">PUANIM</div>
                                    <div class="h5 mb-0"><?php echo $sale_points; ?> P</div>
                                </div>
                                <i class="fa fa-star card-icon text-success"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-left-warning shadow h-100">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-xs text-warning fw-bold">MESAJLAR</div>
                                    <div class="h5 mb-0"><?php echo count($notifications); ?></div>
                                </div>
                                <a href="notifications.php" class="text-warning"><i class="fa fa-envelope card-icon"></i></a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HIZLI ERİŞİM -->
                <div class="card mt-4 shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Hızlı Erişim</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center g-3">
                            <div class="col-3">                            
                                <a href="pos.php" id="pos-btn" class="btn btn-primary btn-lg p-3 d-block
                                <?php echo !$is_shift_open ? 'disabled' : ''; ?>"
                                <?php echo !$is_shift_open ? 'disabled' : ''; ?>
                                <?php echo !$is_shift_open ? 'onclick="event.preventDefault(); showToast(\'Önce vardiya aç!\', \'warning\');"' : ''; ?>>
                                    <i class="bi bi-cash-register fs-1"></i><br>POS
                                </a>
                            </div>
                            <div class="col-3">
                                <a href="shift_management.php" class="btn btn-info btn-lg p-3 d-block text-white">
                                    <i class="bi bi-calendar-check fs-1"></i><br>Vardiyalar
                                </a>
                            </div>
                            <div class="col-3">
                                <a href="#" class="btn btn-warning btn-lg p-3 d-block" data-bs-toggle="modal" data-bs-target="#leaveModal">
                                    <i class="bi bi-calendar-x fs-1"></i><br>İzin
                                </a>
                            </div>
                            <div class="col-3">
                                <a href="#" class="btn btn-success btn-lg p-3 d-block" onclick="alert('Görevler yakında!')">
                                    <i class="bi bi-list-task fs-1"></i><br>Görevler
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SON İŞLEMLER -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header">
                                <h6>Son İzin Talepleri</h6>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($leave_requests as $req): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <small class="w-75"><?php echo $req['start_date']; ?> - <?php echo $req['end_date']; ?></small>
                                            <span class="badge bg-<?php echo $req['status'] == 'approved' ? 'success' : ($req['status'] == 'rejected' ? 'danger' : 'warning'); ?> text-right">
                                                <?php echo ucfirst($req['status']); ?>
                                            </span>
                                            |
                                            <span class="text-right">
                                                <a href="#" onclick="editLeave(<?php echo $personnel_id; ?>)">
                                                    <i class="fa fa-pen"></i>
                                                </a>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header">
                                <h6>Son Bildirimler</h6>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($notifications as $n): ?>
                                        <li class="list-group-item small"><?php echo htmlspecialchars($n['message']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AÇILIŞ MODALI -->
        <div class="modal fade" id="openingModal" data-bs-backdrop="true" data-bs-keyboard="false">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Kasa Açılışı</h5>
                    </div>
                    <form id="openingForm">
                        <div class="modal-body text-center">
                            <div class="input-group mb-3">
                                <input type="number" step="0.01" min="0" class="form-control form-control-lg text-center" id="opening_amount" placeholder="0.00" required>
                                <span class="input-group-text fs-5">TL</span>
                            </div>
                        </div>
                        <div class="modal-footer justify-content-center">
                            <button type="submit" class="btn btn-success btn-lg px-5">Aç</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- KAPANIŞ MODALI -->
        <div class="modal fade" id="closeModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Dikkat!</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <h4>Vardiyanızı kapatmak istediğinize emin misiniz?</h4>
                        <p>Talebiniz yöneticiye iletilecek.<br>Kasa sayımı yönetici tarafından yapılacak.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="button" class="btn btn-danger" id="sendRequest">Talebi Gönder</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="leaveModal">
            <div class="modal-dialog">
                <form id="leaveForm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5>İzin Talebi</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="add_leave_request">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="mb-3">
                                <label>Başlangıç</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Bitiş</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Sebep</label>
                                <textarea name="reason" class="form-control" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Gönder</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="editLeaveModal">
            <div class="modal-dialog">
                <form id="editLeaveForm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5>İzin Talebi Düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="edit_leave_id" name="leave_id">
                            <div class="mb-3">
                                <label>Başlangıç</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Bitiş</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Sebep</label>
                                <textarea name="reason" class="form-control" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                            <button type="submit" class="btn btn-primary">Güncelle</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- EVRAK YÜKLEME MODALI – Dinamik Türler -->
        <div class="modal fade" id="documentModal" tabindex="-1" aria-labelledby="documentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="documentModalLabel">Evrak Yükle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="uploadDocumentForm" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_document">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="mb-3">
                                <label for="document_type" class="form-label">Evrak Türü *</label>
                                <select class="form-select" id="document_type" name="document_type_id" required>
                                    <option value="">Seçin</option>
                                   <?php
                                    // Zaten yüklenmiş türleri çek (personnel_documents tablosundan)
                                    $loaded_types = [];
                                    $query_loaded = "SELECT document_type_id FROM personnel_documents WHERE personnel_id = ?";
                                    $stmt_loaded = $db->prepare($query_loaded);
                                    $stmt_loaded->bind_param("i", $personnel_id);
                                    $stmt_loaded->execute();
                                    $result_loaded = $stmt_loaded->get_result();
                                    while ($row_loaded = $result_loaded->fetch_assoc()) {
                                        $loaded_types[] = $row_loaded['document_type_id'];
                                    }

                                    // Tüm evrak türlerini çek, yüklenmemiş olanları göster
                                    $query = "SELECT id, name, is_required FROM document_types ORDER BY sort_order ASC";
                                    $result = $db->query($query);
                                    while ($row = $result->fetch_assoc()) {
                                        if (in_array($row['id'], $loaded_types)) {
                                            // Zaten yüklü → gösterme
                                            continue;
                                        }
                                        $required = $row['is_required'] ? ' (Zorunlu)' : '';
                                        echo "<option value='{$row['id']}'>{$row['name']}{$required}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="document_file" class="form-label">Dosya Seç *</label>
                                <input type="file" class="form-control" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="mb-3">
                                <label for="notes" class="form-label">Açıklama (opsiyonel)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="button" class="btn btn-primary" onclick="uploadDocument()">Yükle</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="assets/js/app.min.js"></script>
        <script>
            const csrfToken = <?= json_encode($csrf_token) ?>;
            const activeShiftId = <?= $active_shift ? $active_shift['id'] : 'null' ?>;
            const openingBalance = <?= $is_shift_open ? $opening_balance : 0 ?>;
            const exchangeRates = <?= $js_exchangeRates ?>;

            // TOAST
            function showToast(message, type = 'danger') {
                const toast = $(`
                    <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">${message}</div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                `);
                $('.toast-container').remove();
                $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
                $('.toast-container').append(toast);
                const bsToast = new bootstrap.Toast(toast);
                bsToast.show();
                return bsToast;
            }

            $('#openingForm').on('submit', function(e) {
                e.preventDefault();
                const amount = parseFloat($('#opening_amount').val());
                if (isNaN(amount) || amount < 0) return showToast('Geçerli tutar!', 'danger');
                
                const $btn = $(this).find('button');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

                $.post('dashboard.php', {
                    action: 'open_shift',
                    ajax: 1,
                    csrf_token: csrfToken,
                    opening_balance: amount
                }, function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        
                        // MODAL KAPAT + BACKDROP TEMİZLE
                        const modalEl = document.getElementById('openingModal');
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                        setTimeout(() => {
                            $('.modal-backdrop').remove();
                            $('body').removeClass('modal-open').css('padding-right', '');
                        }, 300);

                        // VARDİYA AÇ BUTONUNU SİL
                        $('#open-shift-btn').remove();


                        // BADGE EKLE (container’a)
                        const $container = $('#shift-status-container');
                        if ($container.find('.badge').length === 0) {
                            $container.html(`<span class="badge bg-success fs-6">Vardiya: #${r.shift_id}</span>`);
                        }

                        // VARDİYA KARTI GÜNCELLE
                        $('.vardiya-kart .h5').html(`
                            ${r.opening_balance.toFixed(2)} TL
                            <button class="btn btn-sm btn-danger float-end" onclick="openClosingModal()">
                                Kapat
                            </button>
                        `);

                        // POS BUTONUNU AKTİFLE
                        $('#pos-btn').removeClass('disabled').prop('disabled', false).off('click');
                        
                        // OTOMATİK SAYFA YENİLE (1 sn sonra)
                        setTimeout(() => location.reload(), 1000);
                    }
                }, 'json').fail(() => {
                    showToast('Bağlantı hatası!', 'danger');
                    $btn.prop('disabled', false).html('Aç');
                });
            });

            $('#leaveForm').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                $.ajax({
                    url: 'dashboard.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(r) {
                        try { r = JSON.parse(r); } catch(e) {}
                        showToast(r.message, r.success ? 'success' : 'danger');
                        if (r.success) {
                            $('#leaveModal').modal('hide');
                            setTimeout(() => location.reload(), 1000);
                        }
                    }
                });
            });
            // KAPANIŞ
            function openClosingModal() {
                if (!activeShiftId) {
                    showToast('Vardiya bulunamadı!', 'danger');
                    return;
                }

                // Modal verileri
                $('#modal_shift_id').text(activeShiftId);
                $('#modal_shift_id_hidden').val(activeShiftId);
                $('#modal_cashier').text('<?= htmlspecialchars($personnel_name) ?>');
                $('#modal_opening').text(openingBalance.toFixed(2));
                $('#modal_date').text(new Date().toLocaleString('tr-TR'));

                // Beklenen toplam (örnek: satışlar + açılış)
                const expected = openingBalance + 0; // TODO: Gerçek satış toplamı
                $('#expected_total').text(expected.toFixed(2) + ' ₺');

                // Temizle
                $('#cash_table input').val(0);
                $('#euro_count, #usd_count, #pos1, #pos2').val(0);
                updateCalculations();

                const modal = new bootstrap.Modal('#closingModal', { backdrop: 'static' });
                modal.show();
            }

            <?php if ($show_closing_modal || $force_close): ?>
            $(document).ready(() => openClosingModal());
            <?php endif; ?>

            $('#closing_amount').on('input', function() {
                const closing = parseFloat($(this).val()) || 0;
                const diff = closing - openingBalance;
                const $d = $('#difference_display').removeClass('d-none text-success text-danger');
                if (diff > 0) $d.addClass('text-success').text('+' + diff.toFixed(2) + ' TL (Fazla)');
                else if (diff < 0) $d.addClass('text-danger').text(diff.toFixed(2) + ' TL (Eksik)');
                else $d.addClass('text-success').text('Tam');
            });

            $('#closingForm').on('submit', function(e) {
                e.preventDefault();

                const closingBalance = parseFloat($('#final_total').text().replace(' ₺', '')) || 0;
                if (closingBalance <= 0) {
                    showToast('Kapanış tutarı 0 olamaz!', 'danger');
                    return;
                }

                const $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

                $.post('dashboard.php', {
                    action: 'close_shift',
                    ajax: 1,
                    csrf_token: csrfToken,
                    shift_id: activeShiftId,
                    closing_balance: closingBalance
                }, function(r) {
                    if (r.success && r.redirect) {
                        showToast(`${r.message} Fark: ${r.difference} TL`, 'success');
                        setTimeout(() => {
                            window.location.replace(r.redirect);
                        }, 800);
                    } else {
                        showToast(r.message || 'Hata!', 'danger');
                        $btn.prop('disabled', false).html('Kasa Kapanışını Onayla');
                    }
                }, 'json').fail(() => {
                    showToast('Bağlantı hatası!', 'danger');
                    $btn.prop('disabled', false).html('Kasa Kapanışını Onayla');
                });
            });
            function updateCalculations() {
                let cashTotal = 0;
                $('#cash_table .cash-input').each(function() {
                    const count = parseFloat($(this).val()) || 0;
                    const value = parseFloat($(this).data('value'));
                    const total = count * value;
                    $(this).closest('tr').find('.total').text(total.toFixed(2) + ' ₺');
                    cashTotal += total;
                });
                $('#cash_total').text(cashTotal.toFixed(2) + ' ₺');

                // Döviz
                const euroCount = parseFloat($('#euro_count').val()) || 0;
                const euroRate = parseFloat($('#euro_rate').val()) || exchangeRates.EUR;
                const euroTotal = euroCount * euroRate;
                $('#euro_total').text(euroTotal.toFixed(2) + ' ₺');

                const usdCount = parseFloat($('#usd_count').val()) || 0;
                const usdRate = parseFloat($('#usd_rate').val()) || exchangeRates.USD;
                const usdTotal = usdCount * usdRate;
                $('#usd_total').text(usdTotal.toFixed(2) + ' ₺');

                const forexTotal = euroTotal + usdTotal;
                $('#forex_total').text(forexTotal.toFixed(2) + ' ₺');

                // POS
                const pos1 = parseFloat($('#pos1').val()) || 0;
                const pos2 = parseFloat($('#pos2').val()) || 0;
                const posTotal = pos1 + pos2;
                $('#pos_total').text(posTotal.toFixed(2) + ' ₺');

                // GENEL TOPLAM
                const finalTotal = cashTotal + forexTotal + posTotal;
                $('#final_total').text(finalTotal.toFixed(2) + ' ₺');

                // FARK
                const difference = finalTotal - openingBalance;
                const $diff = $('#difference');
                $diff.text(difference.toFixed(2) + ' ₺');
                if (difference > 0) $diff.removeClass('text-danger').addClass('text-success');
                else if (difference < 0) $diff.removeClass('text-success').addClass('text-danger');
                else $diff.removeClass('text-success text-danger');
            }
            // Kapanış Talebi
            $('#sendRequest').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Gönderiliyor...');

                $.post('', {
                    ajax: 1,
                    action: 'request_close',
                    csrf_token: csrfToken
                })
                .done(function(r) {
                    showToast(r.message || 'İşlem tamamlandı', r.success ? 'success' : 'danger');
                    if (r.success) {
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        $btn.prop('disabled', false).html('Talebi Gönder');
                    }
                })
                .fail(function() {
                    showToast('Sunucu hatası! Lütfen tekrar deneyin.', 'danger');
                    $btn.prop('disabled', false).html('Talebi Gönder');
                });
            });

            function editLeave(leaveId) {
                $('#edit_leave_id').val(leaveId);

                $.post('dashboard.php', {
                    action: 'get_leave_details',
                    leave_id: leaveId,
                    csrf_token: csrfToken
                }, function(res) {
                    if (res.success) {
                        // Input name'leri doğru olsun
                        $('input[name="start_date"]').val(res.start_date);
                        $('input[name="end_date"]').val(res.end_date);
                        $('textarea[name="reason"]').val(res.reason);
                        showToast('İzin verileri yüklendi!', 'success');
                    } else {
                        showToast(res.message || 'Veriler yüklenemedi!', 'danger');
                    }
                }, 'json');

                $('#editLeaveModal').modal('show');
            }
            
            function uploadDocument() {
                const formData = new FormData($('#uploadDocumentForm')[0]);

                $.ajax({
                    url: 'dashboard.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(r) {
                        console.log('Yükleme Cevabı:', r);
                        showToast(r.message || 'İşlem tamamlandı', r.success ? 'success' : 'danger');
                        if (r.success) {
                            $('#documentModal').modal('hide');
                            setTimeout(() => location.reload(), 1500);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Hatası Detay:', xhr.status, xhr.responseText, error);
                        showToast('Sunucu hatası! Konsolu kontrol et.', 'danger');
                    }
                });
            }
        </script>
        <?php display_footer(); ?>
    </body>
</html>
<?php ob_end_flush(); ?>