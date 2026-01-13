<?php
require_once 'template.php';

display_header('Ürün Kategorileri');
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $point_multiplier = floatval($_POST['point_multiplier']);
    $query = "INSERT INTO product_categories (name, point_multiplier) VALUES (?, ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("sd", $name, $point_multiplier);
    if ($stmt->execute()) {
        add_notification("Kategori eklendi: $name", 'success', get_current_branch());
        log_action('category_added', "Kategori: $name");
    } else {
        add_notification("Kategori ekleme başarısız!", 'error', get_current_branch());
    }
}
?>

<div class="container">
    <h2>Ürün Kategorileri</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Kategori Adı</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Puan Çarpanı</label>
            <input type="number" step="0.01" name="point_multiplier" class="form-control" value="1.0" required>
        </div>
        <button type="submit" class="btn btn-primary">Kategori Ekle</button>
    </form>

    <h3>Kategori Listesi</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Kategori Adı</th>
                <th>Puan Çarpanı</th>
            </tr>
        </thead>
        <tbody>
            <?php
            global $db;
            $query = "SELECT name, point_multiplier FROM product_categories ORDER BY id DESC LIMIT 20";
            $result = $db->query($query);
            while ($category = $result->fetch_assoc()) {
                echo "<tr>
                    <td>{$category['name']}</td>
                    <td>{$category['point_multiplier']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>