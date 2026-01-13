<?php
ob_start();
session_start();
require_once '../config.php';
require_once '../functions/common.php';

if ($_SESSION['personnel_type'] !== 'admin' || !validate_csrf_token($_POST['csrf'] ?? '')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim!']);
    exit;
}

$branch_id = get_current_branch();
$action    = $_POST['action'] ?? '';
global $db;

switch ($action) {

    case 'get':
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($product_id <= 0) exit;

        $q = "SELECT 
                r.*, i.name AS ingredient_name, i.unit, i.unit_cost,
                (r.quantity * i.unit_cost) AS line_cost
              FROM recipes r 
              JOIN ingredients i ON r.ingredient_id = i.id 
              WHERE r.product_id = ? AND i.branch_id = ?";
        $s = $db->prepare($q);
        $s->bind_param("ii", $product_id, $branch_id);
        $s->execute();
        $items = $s->get_result()->fetch_all(MYSQLI_ASSOC);

        $total_cost = array_sum(array_column($items, 'line_cost'));

        $price_q = $db->prepare("SELECT unit_price FROM products WHERE id = ? AND branch_id = ?");
        $price_q->bind_param("ii", $product_id, $branch_id);
        $price_q->execute();
        $unit_price = $price_q->get_result()->fetch_row()[0] ?? 0;

        ob_clean();
        header('Content-Type: text/html');
        ?>
        <div class="card border-primary mb-3">
            <div class="card-body">
                <h6>Maliyet Analizi</h6>
                <div class="row text-center">
                    <div class="col">
                        <small>Fiyat</small><br>
                        <strong><?= number_format($unit_price, 2) ?> ₺</strong>
                    </div>
                    <div class="col">
                        <small>Maliyet</small><br>
                        <strong class="<?= $total_cost > $unit_price ? 'text-danger' : 'text-success' ?>">
                            <?= number_format($total_cost, 2) ?> ₺
                        </strong>
                    </div>
                    <div class="col">
                        <small>Kâr</small><br>
                        <strong class="<?= ($unit_price - $total_cost) < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= number_format($unit_price - $total_cost, 2) ?> ₺
                        </strong>
                    </div>
                    <div class="col">
                        <small>Marj</small><br>
                        <strong>
                            <span class="badge <?= ($unit_price > 0 && (($unit_price - $total_cost)/$unit_price) >= 0.3) ? 'bg-success' : 'bg-warning' ?>">
                                <?= $unit_price > 0 ? round((($unit_price - $total_cost)/$unit_price)*100, 1) : 0 ?>%
                            </span>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <p class="text-muted">Bu ürün için reçete tanımlanmamış.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr><th>Malzeme</th><th>Miktar</th><th>Birim</th><th>B. Fiyat</th><th>Toplam</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['ingredient_name']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= $item['unit'] ?></td>
                            <td><?= number_format($item['unit_cost'], 2) ?> ₺</td>
                            <td><?= number_format($item['line_cost'], 2) ?> ₺</td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="deleteRecipeItem(<?= $item['id'] ?>)">
                                    Sil
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-primary">
                            <th colspan="4">TOPLAM MALİYET</th>
                            <th><?= number_format($total_cost, 2) ?> ₺</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-primary" onclick="addRecipeItem(<?= $product_id ?>)">+ Malzeme Ekle</button>
        <?php
        exit;

    case 'add_item':
        $product_id     = intval($_POST['product_id'] ?? 0);
        $ingredient_id  = intval($_POST['ingredient_id'] ?? 0);
        $quantity       = floatval($_POST['quantity'] ?? 0);

        if ($product_id <= 0 || $ingredient_id <= 0 || $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
            exit;
        }

        $q = "INSERT INTO recipes (product_id, ingredient_id, quantity) VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
        $s = $db->prepare($q);
        $s->bind_param("iid", $product_id, $ingredient_id, $quantity);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Ekleme hatası!']);
        break;

    case 'delete_item':
        $id = intval($_POST['id'] ?? 0);
        $q = "DELETE FROM recipes WHERE id = ? AND EXISTS (SELECT 1 FROM products p WHERE p.id = recipes.product_id AND p.branch_id = ?)";
        $s = $db->prepare($q);
        $s->bind_param("ii", $id, $branch_id);
        $ok = $s->execute();

        echo json_encode($ok ? ['success' => true] : ['success' => false]);
        break;

    case 'add':
    $product_id = intval($_POST['product_id'] ?? 0);
    $ingredients = json_decode($_POST['ingredients'] ?? '[]', true);

    if ($product_id <= 0 || empty($ingredients)) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz veri!']);
        exit;
    }

    // Önce eski reçeteyi sil
    $db->query("DELETE FROM recipes WHERE product_id = $product_id");

    $stmt = $db->prepare("INSERT INTO recipes (product_id, ingredient_id, quantity) VALUES (?, ?, ?)");
    $ok = true;
    foreach ($ingredients as $ing) {
        $ing_id = intval($ing['ingredient_id']);
        $qty = floatval($ing['quantity']);
        if ($ing_id > 0 && $qty > 0) {
            $stmt->bind_param("iid", $product_id, $ing_id, $qty);
            if (!$stmt->execute()) $ok = false;
        }
    }

    echo json_encode($ok ? ['success' => true, 'message' => 'Reçete oluşturuldu!']
                       : ['success' => false, 'message' => 'Kayıt hatası!']);
    exit;
    default:
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
}