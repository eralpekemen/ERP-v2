<?php
ob_start();
session_start();

require_once '../config.php';

require_once '../functions/common.php';

require_once '../functions/notifications.php'; 

// AJAX KONTROL
if (!isset($_POST['ajax']) || $_POST['ajax'] != '1') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

// CSRF KONTROL
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
    exit;
}

// OTURUM KONTROL
if (!isset($_SESSION['personnel_id']) || !in_array($_SESSION['personnel_type'], ['admin', 'cashier', 'shift_supervisor'])) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$action = $_POST['action'] ?? '';

ob_clean();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    ob_clean(); // Çıktı tamponunu temizle
    // ... diğer kontroller
}
function get_shift_sales($shift_id) {
    global $db;
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE shift_id = ?");
    $stmt->bind_param("i", $shift_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_row()[0];
}
function exit_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
switch ($action) {
    case 'open_shift':
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);
        $personnel_id = $_SESSION['personnel_id'];
        $branch_id = get_current_branch();

        // Aktif vardiya kontrol
        $stmt = $db->prepare("SELECT id, opening_balance FROM shifts WHERE personnel_id = ? AND branch_id = ? AND status = 'open'");
        $stmt->bind_param("ii", $personnel_id, $branch_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();

        if ($shift) {
            $current_opening = $shift['opening_balance'] ?? 0;
            if ($current_opening > 0) {
                echo json_encode(['success' => false, 'message' => 'Kasa zaten açık!']);
                exit;
            }

            // Güncelle
            $stmt = $db->prepare("UPDATE shifts SET opening_balance = ? WHERE id = ?");
            $stmt->bind_param("di", $opening_balance, $shift['id']);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Kasa açılışı tamamlandı!']);
        } else {
            // Yeni vardiya
            $stmt = $db->prepare("INSERT INTO shifts (personnel_id, branch_id, opening_balance, status, start_time) VALUES (?, ?, ?, 'open', NOW())");
            $stmt->bind_param("iid", $personnel_id, $branch_id, $opening_balance);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Vardiya başlatıldı, kasa açılışı yapıldı!']);
        }
        break;

    case 'close_shift':
        $shift_id = intval($_POST['shift_id'] ?? 0);
        $closing_balance = floatval($_POST['closing_balance'] ?? 0);

        if ($shift_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz vardiya ID']);
            exit;
        }

        $stmt = $db->prepare("SELECT opening_balance FROM shifts WHERE id = ? AND personnel_id = ? AND branch_id = ? AND status = 'open'");
        $stmt->bind_param("iii", $shift_id, $personnel_id, $branch_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();

        if (!$shift) {
            echo json_encode(['success' => false, 'message' => 'Vardiya bulunamadı veya zaten kapalı!']);
            exit;
        }

        $opening = $shift['opening_balance'] ?? 0;
        $difference = $closing_balance - $opening;

        $stmt = $db->prepare("UPDATE shifts SET closing_balance = ?, status = 'closed', end_time = NOW() WHERE id = ?");
        $stmt->bind_param("di", $closing_balance, $shift_id);
        $stmt->execute();

        // Nakit hareketi kaydet
        $stmt_cash = $db->prepare("INSERT INTO cash_registers (shift_id, amount, transaction_type, payment_type, created_at, branch_id) VALUES (?, ?, 'closing', 'cash', NOW(), ?)");
        $stmt_cash->bind_param("idi", $shift_id, $closing_balance, $branch_id);
        $stmt_cash->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Vardiya kapandı!',
            'difference' => number_format($difference, 2)
        ]);
        break;

    case 'request_close':
        $shift_id = intval($_POST['shift_id'] ?? 0);
        if ($shift_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz vardiya!']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM shifts WHERE id = ? AND personnel_id = ? AND branch_id = ? AND status = 'open'");
        $stmt->bind_param("iii", $shift_id, $personnel_id, $branch_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Vardiya bulunamadı!']);
            exit;
        }

        // Talep kaydı
        $stmt = $db->prepare("INSERT INTO shift_close_requests (shift_id, requested_by, requested_at, branch_id) VALUES (?, ?, NOW(), ?)");
        $stmt->bind_param("iii", $shift_id, $personnel_id, $branch_id);
        $stmt->execute();

        // Bildirim → Müdür
        add_notification("Kasiyer kasa kapatma talebinde bulundu (Vardiya #$shift_id)", 'warning', $branch_id);

        echo json_encode(['success' => true, 'message' => 'Kapanış talebi gönderildi!']);
        exit;
        break;

    case 'approve_close':
        if ($_SESSION['personnel_type'] != 'admin') {
            echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']);
            exit;
        }

        $request_id = intval($_POST['request_id'] ?? 0);
        $closing_balance = floatval($_POST['closing_balance'] ?? 0);

        $stmt = $db->prepare("SELECT scr.shift_id, s.opening_balance, s.personnel_id 
                              FROM shift_close_requests scr 
                              JOIN shifts s ON scr.shift_id = s.id 
                              WHERE scr.id = ? AND scr.branch_id = ? AND s.status = 'open'");
        $stmt->bind_param("ii", $request_id, $branch_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();

        if (!$req) {
            echo json_encode(['success' => false, 'message' => 'Talep bulunamadı!']);
            exit;
        }

        $diff = $closing_balance - ($req['opening_balance'] ?? 0);

        // Vardiyayı kapat
        $stmt = $db->prepare("UPDATE shifts SET closing_balance = ?, status = 'closed', end_time = NOW() WHERE id = ?");
        $stmt->bind_param("di", $closing_balance, $req['shift_id']);
        $stmt->execute();

        $cashier_id = $req['personnel_id'];
        $db->query("DELETE FROM sessions WHERE personnel_id = $cashier_id"); // session tablosu varsa
        // veya
        $db->query("UPDATE personnel SET is_logged_in = 0 WHERE id = $cashier_id");

        // Talep sil
        $stmt = $db->prepare("DELETE FROM shift_close_requests WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();

        // Bildirim
        add_notification("Vardiya kapatıldı! Fark: " . number_format($diff, 2) . " TL", 'success', $branch_id);

        echo json_encode([
            'success' => true,
            'message' => 'Vardiya kapatıldı!',
            'difference' => number_format($diff, 2),
            'redirect' => 'cashier_dashboard.php'
        ]);

        exit;
        break;

    case 'get_close_requests':
        if ($_SESSION['personnel_type'] != 'admin') {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz!']);
            exit;
        }
        $stmt = $db->prepare("SELECT scr.id, scr.shift_id, s.opening_balance, p.name AS cashier, scr.requested_at
                      FROM shift_close_requests scr
                      JOIN shifts s ON scr.shift_id = s.id
                      JOIN personnel p ON s.personnel_id = p.id
                      WHERE scr.branch_id = ? AND s.status = 'open'
                      ORDER BY scr.requested_at DESC");
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $html = '';
            $count = count($requests);

            if ($count == 0) {
                $html = '<p class="text-muted">Aktif kapanış talebi yok.</p>';
            } else {
                foreach ($requests as $req) {
                    $html .= '<div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <strong>#'.$req['shift_id'].'</strong> - '.$req['cashier'].'<br>
                                    <small>Açılış: '.number_format($req['opening_balance'], 2).' TL</small>
                                </div>
                                <button class="btn btn-danger btn-sm" onclick="openCloseShiftModal(shift_id)">
                                    Kapat
                                </button>
                              </div>';
                }
            }

            echo json_encode(['success' => true, 'html' => $html, 'count' => $count]);
            exit;
        break;

    case 'close_shift_direct':
        $shift_id = intval($_POST['shift_id'] ?? 0);
            if ($shift_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz vardiya!']);
                exit;
            }

            $stmt = $db->prepare("SELECT id, opening_balance, personnel_id FROM shifts WHERE id = ? AND branch_id = ? AND personnel_id = ? AND status = 'open'");
            $stmt->bind_param("iii", $shift_id, $branch_id, $personnel_id);
            $stmt->execute();
            $shift = $stmt->get_result()->fetch_assoc();

            if (!$shift) {
                echo json_encode(['success' => false, 'message' => 'Vardiya bulunamadı veya zaten kapalı!']);
                exit;
            }

            // VARDİYAYI KAPAT (closing_balance NULL → Müdür girecek)
            $stmt = $db->prepare("UPDATE shifts SET status = 'closed',closing_balance = NULL, end_time = NOW() WHERE id = ?");
            $stmt->bind_param("i", $shift_id);
            $stmt->execute();

            // Kasiyeri logout
            $db->query("UPDATE personnel SET is_logged_in = 0 WHERE id = " . $shift['personnel_id']);

            // MÜDÜRE BİLDİRİM
            add_notification("Kasiyer vardiyasını kapattı (Vardiya #$shift_id). Kapanış bakiyesini girin.", 'warning', $branch_id);

            echo json_encode(['success' => true]);
            exit;
        break;

        case 'get_pending_closes':
            $html = '';
            $query = "SELECT s.id, s.opening_balance, p.username 
              FROM shifts s 
              JOIN personnel p ON s.personnel_id = p.id 
              WHERE s.branch_id = ? AND s.closing_requested = 1 AND s.status = 'open'";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $res = $stmt->get_result();

                $html = '';
                $count = 0;
                while ($r = $res->fetch_assoc()) {
                    $count++;
                    $html .= '<div class="alert alert-warning mb-2 p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>' . htmlspecialchars($r['username']) . '</strong> vardiyasını kapatmak istiyor<br>
                                    <small>Vardiya #' . $r['id'] . ' – Açılış: ' . number_format($r['opening_balance'],2) . ' ₺</small>
                                </div>
                                <button class="btn btn-success btn-sm" onclick="openCloseShiftModal(' . $r['id'] . ')">
                                    Sayım Yap
                                </button>
                              </div>';
                }
                if ($count == 0) $html = '<p class="text-center text-muted">Kapanış talebi yok</p>';

            exit_json(['success' => true, 'html' => $html, 'count' => $count]);
        exit;   

    case 'enter_closing_balance':
            $shift_id = intval($_POST['shift_id'] ?? 0);
            $closing_balance = floatval($_POST['closing_balance'] ?? 0);
            $branch_id = get_current_branch();

            if ($shift_id <= 0 || $closing_balance < 0) {
                exit_json(['success' => false, 'message' => 'Geçersiz veri!']);
            }

            $stmt = $db->prepare("SELECT s.opening_balance, p.name AS cashier, s.start_time 
                                  FROM shifts s 
                                  JOIN personnel p ON s.personnel_id = p.id 
                                  WHERE s.id = ? AND s.branch_id = ? AND s.status = 'closed' AND s.closing_balance IS NULL");
            $stmt->bind_param("ii", $shift_id, $branch_id);
            $stmt->execute();
            $shift = $stmt->get_result()->fetch_assoc();

            if (!$shift) {
                exit_json(['success' => false, 'message' => 'Vardiya bulunamadı veya zaten kapatılmış!']);
            }

            $sales = get_shift_sales($shift_id);
            $expected = $shift['opening_balance'] + $sales;
            $difference = $closing_balance - $expected;

            $stmt = $db->prepare("UPDATE shifts SET closing_balance = ?, closed_at = NOW() WHERE id = ?");
            $stmt->bind_param("di", $closing_balance, $shift_id);
            $success = $stmt->execute();

            if ($success) {
                // Bildirim ekle
                add_notification("Vardiya #$shift_id kapatıldı. Fark: " . number_format($difference, 2) . " TL", 'info', $branch_id);
                
                exit_json([
                    'success' => true,
                    'message' => 'Kapanış kaydedildi!',
                    'difference' => number_format($difference, 2),
                    'redirect' => 'cashier_dashboard.php'
                ]);
            } else {
                exit_json(['success' => false, 'message' => 'Veritabanı hatası!']);
            }
        exit;

    case 'get_shift_totals':
        $shift_id = intval($_POST['shift_id'] ?? 0);
        $branch_id = get_current_branch();

        $stmt = $db->prepare("SELECT opening_balance FROM shifts WHERE id = ? AND branch_id = ? AND status = 'closed' AND closing_balance IS NULL");
        $stmt->bind_param("ii", $shift_id, $branch_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();

        if (!$shift) {
            exit_json(['success' => false]);
        }

        $sales = get_shift_sales($shift_id);
        $expected = $shift['opening_balance'] + $sales;

        exit_json(['success' => true, 'expected' => $expected]);
        exit;

    case 'get_shift_info':
        $shift_id = intval($_POST['shift_id'] ?? 0);
        $stmt = $db->prepare("SELECT s.*, p.username, p.name FROM shifts s JOIN personnel p ON s.personnel_id = p.id WHERE s.id = ? AND s.branch_id = ?");
        $stmt->bind_param("ii", $shift_id, $branch_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();

        if (!$shift || $shift['status'] !== 'open' || $shift['closing_requested'] != 1) {
            exit_json(['success' => false, 'message' => 'Geçersiz vardiya']);
        }

        exit_json([
            'success' => true,
            'shift' => $shift
        ]);
        exit;

    case 'close_shift_by_admin':
        $shift_id = intval($_POST['shift_id'] ?? 0);
        $closing_balance = floatval($_POST['closing_balance'] ?? 0);

        $stmt = $db->prepare("SELECT opening_balance FROM shifts WHERE id = ? AND branch_id = ? AND status = 'open' AND closing_requested = 1");
        $stmt->bind_param("ii", $shift_id, $branch_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();

        if (!$shift) {
            exit_json(['success' => false, 'message' => 'Vardiya kapatılamadı']);
        }

        $difference = $closing_balance - $shift['opening_balance'];

        $stmt = $db->prepare("UPDATE shifts SET 
            closing_balance = ?, 
            status = 'closed', 
            closed_at = NOW(), 
            closing_requested = 0 
            WHERE id = ?");
        $stmt->bind_param("di", $closing_balance, $shift_id);
        $success = $stmt->execute();

        if ($success) {
            add_notification(
                "Vardiya #$shift_id yönetici tarafından kapatıldı. Fark: " . number_format($difference, 2) . " ₺",
                'info',
                $branch_id
            );
        }

        exit_json([
            'success' => $success,
            'message' => $success ? 'Vardiya başarıyla kapatıldı!' : 'Hata oluştu',
            'difference' => number_format($difference, 2)
        ]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        break;
    }

exit;
?>