<?php
require_once 'template.php';
require_once 'functions/branches.php';

display_header('Ürün Yönetimi');
$branch_id = get_current_branch();
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $category_id = intval($_POST['category_id']);
    $stock_quantity = floatval($_POST['stock_quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $point_value = floatval($_POST['point_value']);
    $cost_price = is_main_company() ? floatval($_POST['cost_price']) : 0;

    $db->begin_transaction();
    try {
        $query = "INSERT INTO products (name, category_id, stock_quantity, unit_price, point_value, cost_price) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param("siddid", $name, $category_id, $stock_quantity, $unit_price, $point_value, $cost_price);
        $stmt->execute();
        $db->commit();
        add_notification("Ürün eklendi: $name", 'success', $branch_id);
        log_action('product_added', "Ürün: $name, Şube ID: $branch_id");
    } catch (Exception $e) {
        $db->rollback();
        add_notification("Ürün ekleme başarısız: " . $e->getMessage(), 'error', $branch_id);
    }
}
?>

<div class="container">
    <h2>Ürün Yönetimi</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Ürün Adı</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Kategori</label>
            <select name="category_id" class="form-control" required>
                <option value="">Seçin</option>
                <?php
                global $db;
                $query = "SELECT id, name FROM product_categories";
                $result = $db->query($query);
                while ($category = $result->fetch_assoc()) {
                    echo "<option value='{$category['id']}'>{$category['name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label>Stok Miktarı</label>
            <input type="number" step="0.01" name="stock_quantity" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Birim Fiyat (TL)</label>
            <input type="number" step="0.01" name="unit_price" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Puan Değeri</label>
            <input type="number" step="0.01" name="point_value" class="form-control" required>
        </div>
        <?php if (is_main_company()) { ?>
        <div class="form-group">
            <label>Maliyet Fiyatı (TL)</label>
            <input type="number" step="0.01" name="cost_price" class="form-control" required>
        </div>
        <?php } ?>
        <button type="submit" class="btn btn-primary">Ürün Ekle</button>
    </form>

    <h3>Ürün Listesi</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Ürün Adı</th>
                <th>Kategori</th>
                <th>Stok</th>
                <th>Birim Fiyat</th>
                <th>Puan Değeri</th>
                <?php if (is_main_company()) { ?>
                <th>Maliyet</th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = "SELECT p.name, pc.name as category_name, p.stock_quantity, p.unit_price, p.point_value" . (is_main_company() ? ", p.cost_price" : "") . " 
                      FROM products p JOIN product_categories pc ON p.category_id = pc.id 
                      WHERE p.branch_id = ? ORDER BY p.id DESC LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($products as $product) {
                echo "<tr>
                    <td>{$product['name']}</td>
                    <td>{$product['category_name']}</td>
                    <td>{$product['stock_quantity']}</td>
                    <td>{$product['unit_price']}</td>
                    <td>{$product['point_value']}</td>";
                if (is_main_company()) {
                    echo "<td>{$product['cost_price']}</td>";
                }
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>