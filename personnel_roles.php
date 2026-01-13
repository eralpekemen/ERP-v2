<?php
require_once 'template.php';

display_header('Personel Rolleri');
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $query = "INSERT INTO personnel_roles (name) VALUES (?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $name);
    if ($stmt->execute()) {
        echo "<div class='alert alert-success'>Rol eklendi!</div>";
    } else {
        echo "<div class='alert alert-danger'>Rol ekleme başarısız!</div>";
    }
}
?>

<div class="container">
    <h2>Personel Rolleri</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Rol Adı</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Rol Ekle</button>
    </form>
    
    <h3>Rol Listesi</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Rol Adı</th>
            </tr>
        </thead>
        <tbody>
            <?php
            global $db;
            $query = "SELECT name FROM personnel_roles ORDER BY id DESC LIMIT 20";
            $result = $db->query($query);
            while ($role = $result->fetch_assoc()) {
                echo "<tr><td>{$role['name']}</td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>