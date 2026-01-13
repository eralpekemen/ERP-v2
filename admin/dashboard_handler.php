<?php
session_start();
require_once '../config.php';
require_once '../functions/common.php';

// Güvenlik
if (!isset($_SESSION['personnel_type']) || $_SESSION['personnel_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz']);
    exit;
}

$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf'] ?? '';

if (!validate_csrf_token($csrf)) {
    echo json_encode(['success' => false, 'message' => 'CSRF hatası']);
    exit;
}

$branch_id = get_current_branch();

// TEST: branch_id kontrol
if (!$branch_id) {
    echo json_encode(['success' => false, 'message' => 'Şube bulunamadı']);
    exit;
}

if ($action === 'list') {
    // Sorgu
    $sql = "
        SELECT 
            p.id,
            p.name,
            COALESCE(p.barcode, '') AS barcode,
            p.stock_quantity AS stock,
            p.unit_price AS price,
            COALESCE(c.name, 'Kategorisiz') AS category
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.branch_id = ?
        ORDER BY p.name ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Sorgu hatası: ' . $db->error]);
        exit;
    }

    $stmt->bind_param("i", $branch_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Yürütme hatası: ' . $stmt->error]);
        exit;
    }

    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);

    // DEBUG BİLGİSİ
    echo json_encode([
        'success' => true,
        'products' => $products,
        'debug' => [
            'branch_id' => $branch_id,
            'sql' => $sql,
            'count' => count($products),
            'time' => date('H:i:s')
        ]
    ]);
    exit;
}

// Geçersiz action
echo json_encode(['success' => false, 'message' => 'Geçersiz action']);
?>