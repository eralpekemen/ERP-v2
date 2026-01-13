<?php
ob_start();
session_start();
require_once '../config.php';
require_once '../functions/common.php';

header('Content-Type: application/json');

if ($_SESSION['personnel_type'] !== 'admin' || !validate_csrf_token($_POST['csrf'] ?? '')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Yetkisiz!']);
    exit;
}

$branch_id = get_current_branch();
$action = $_POST['action'] ?? '';
global $db;

ob_clean();

if ($action === 'list') {
    $stmt = $db->prepare("SELECT *, CASE WHEN current_qty <= min_qty THEN 1 ELSE 0 END AS is_low FROM ingredients WHERE branch_id = ? ORDER BY name");
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    exit;
}
switch ($action) {
    case 'list':
        $stmt = $db->prepare("SELECT *, CASE WHEN current_qty <= min_qty THEN 1 ELSE 0 END AS is_low FROM ingredients WHERE branch_id = ? ORDER BY name");
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;

    case 'add':
            $name      = trim($_POST['name'] ?? '');
            $unit      = trim($_POST['unit'] ?? 'adet');
            $min_qty   = floatval($_POST['min_qty'] ?? 0);
            $current_qty = floatval($_POST['current_qty'] ?? 0);
            $unit_cost = floatval($_POST['unit_cost'] ?? 0);

            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'Malzeme adı zorunlu!']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO ingredients (name, unit, current_qty, min_qty, unit_cost, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdddi", $name, $unit, $current_qty, $min_qty, $unit_cost, $branch_id);
            $ok = $stmt->execute();
            $id = $db->insert_id;

            if ($ok && $current_qty > 0) {
                log_movement($id, 'in', $current_qty, 'İlk giriş', null);
            }

            echo json_encode(
                $ok 
                    ? ['success' => true, 'id' => $id, 'message' => 'Malzeme eklendi.']
                    : ['success' => false, 'message' => 'Ekleme hatası!']
            );
        exit;

    case 'edit':
        $id        = intval($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $unit      = trim($_POST['unit'] ?? 'adet');
        $min_qty   = floatval($_POST['min_qty'] ?? 0);
        $unit_cost = floatval($_POST['unit_cost'] ?? 0);

        if ($id <= 0 || $name === '') {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
            exit;
        }

        $q = "UPDATE ingredients SET name = ?, unit = ?, min_qty = ?, unit_cost = ? 
              WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ssddii", $name, $unit, $min_qty, $unit_cost, $id, $branch_id);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true, 'message' => 'Malzeme güncellendi.']
                           : ['success' => false, 'message' => 'Güncelleme hatası!']);
        exit;

    case 'stock_in':
        $id        = intval($_POST['id'] ?? 0);
        $qty       = floatval($_POST['qty'] ?? 0);
        $reason    = trim($_POST['reason'] ?? 'Stok girişi');
        $unit_cost = floatval($_POST['unit_cost'] ?? 0);

        if ($id <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz miktar!']);
            exit;
        }

        if ($unit_cost > 0) {
            $u = $db->prepare("UPDATE ingredients SET unit_cost = ? WHERE id = ? AND branch_id = ?");
            $u->bind_param("dii", $unit_cost, $id, $branch_id);
            $u->execute();
        }

        $q = "UPDATE ingredients SET current_qty = current_qty + ? WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);  // HATA BURADAYDI → DÜZELTİLDİ
        $s->bind_param("dii", $qty, $id, $branch_id);
        $ok = $s->execute();

        if ($ok) log_movement($id, 'in', $qty, $reason, null);

        echo json_encode($ok ? ['success' => true, 'message' => 'Stok artırıldı.']
                           : ['success' => false, 'message' => 'Stok hatası!']);
        exit;

    case 'stock_out':
        $id     = intval($_POST['id'] ?? 0);
        $qty    = floatval($_POST['qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Manuel çıkış');

        if ($id <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz miktar!']);
            exit;
        }

        $check = $db->prepare("SELECT current_qty FROM ingredients WHERE id = ? AND branch_id = ?");
        $check->bind_param("ii", $id, $branch_id);
        $check->execute();
        $current = $check->get_result()->fetch_row()[0] ?? 0;

        if ($current < $qty) {
            echo json_encode(['success' => false, 'message' => 'Yetersiz stok!']);
            exit;
        }

        $q = "UPDATE ingredients SET current_qty = current_qty - ? WHERE id = ? AND branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("dii", $qty, $id, $branch_id);
        $ok = $s->execute();

        if ($ok) log_movement($id, 'out', $qty, $reason, null);

        echo json_encode($ok ? ['success' => true, 'message' => 'Stok azaltıldı.']
                           : ['success' => false, 'message' => 'Stok hatası!']);
        exit;

    case 'log':
        $id = intval($_POST['id'] ?? 0);
        $q = "SELECT * FROM ingredient_movements WHERE ingredient_id = ? ORDER BY created_at DESC LIMIT 50";
        $s = $db->prepare($q);
        $s->bind_param("i", $id);
        $s->execute();
        $res = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($res);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        exit;
}
?>