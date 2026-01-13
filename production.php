<?php
require_once 'functions/production.php';
require_once 'template.php';

display_header('Üretim Yönetimi');
$branch_id = get_current_branch();
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = intval($_POST['product_id']);
    $quantity = floatval($_POST['quantity']);
    $result = create_production_order($product_id, $branch_id, $quantity);
    if ($result) {
        echo "<div class='alert alert-success'>Üretim emri oluşturuldu! Emir ID: $result</div>";
    } else {
        echo "<div class='alert alert-danger'>Üretim emri oluşturma başarısız!</div>";
    }
}
?>

<div class="container">
    <h2>Üretim Yönetimi</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Ürün Seç</label>
            <select name="product_id" class="form-control" required>
                <option value="">Seçin</option>
                <?php
                global $db;
                $query = "SELECT id, name FROM products WHERE branch_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($products as $product) {
                    echo "<option value='{$product['id']}'>{$product['name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label>Miktar</label>
            <input type="number" step="0.01" name="quantity" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Üretim Emri Oluştur</button>
    </form>
    
    <h3>Üretim Emirleri</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Ürün</th>
                <th>Miktar</th>
                <th>Durum</th>
                <th>Oluşturulma</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = "SELECT p.name, pr.quantity, pr.status, pr.created_at 
                      FROM production pr JOIN products p ON pr.product_id = p.id 
                      WHERE pr.branch_id = ? ORDER BY pr.created_at DESC LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($orders as $order) {
                echo "<tr>
                    <td>{$order['name']}</td>
                    <td>{$order['quantity']}</td>
                    <td>{$order['status']}</td>
                    <td>{$order['created_at']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>