<?php
require_once 'functions/branches.php';
require_once 'template.php';

display_header('Şube Yönetimi');
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $result = add_branch($name, $address);
    if ($result) {
        echo "<div class='alert alert-success'>Şube eklendi!</div>";
    } else {
        echo "<div class='alert alert-danger'>Şube ekleme başarısız!</div>";
    }
}
?>

<div class="container">
    <h2>Şube Yönetimi</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Şube Adı</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Adres</label>
            <textarea name="address" class="form-control"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Şube Ekle</button>
    </form>
    
    <h3>Şube Listesi</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Şube Adı</th>
                <th>Adres</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
            <?php
            global $db;
            $query = "SELECT name, address, status FROM branches ORDER BY id DESC LIMIT 20";
            $result = $db->query($query);
            while ($branch = $result->fetch_assoc()) {
                echo "<tr>
                    <td>{$branch['name']}</td>
                    <td>{$branch['address']}</td>
                    <td>{$branch['status']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>