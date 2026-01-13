<?php
require_once 'functions/shifts.php';
require_once 'template.php';

display_header('Vardiya Yönetimi');
$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['open_shift'])) {
        $shift_type = $_POST['shift_type'];
        $opening_balance = floatval($_POST['opening_balance']);
        $result = open_shift($branch_id, $personnel_id, $shift_type, $opening_balance);
        if ($result) {
            echo "<div class='alert alert-success'>Vardiya açıldı! Vardiya ID: $result</div>";
        } else {
            echo "<div class='alert alert-danger'>Vardiya açma başarısız!</div>";
        }
    } elseif (isset($_POST['close_shift'])) {
        $shift_id = intval($_POST['shift_id']);
        $closing_balance = floatval($_POST['closing_balance']);
        $result = close_shift($shift_id, $closing_balance);
        if ($result) {
            echo "<div class='alert alert-success'>Vardiya kapatıldı!</div>";
        } else {
            echo "<div class='alert alert-danger'>Vardiya kapatma başarısız!</div>";
        }
    }
}
?>

<div class="container">
    <h2>Vardiya Yönetimi</h2>
    
    <!-- Vardiya Aç -->
    <h3>Yeni Vardiya Aç</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="open_shift" value="1">
        <div class="form-group">
            <label>Vardiya Tipi</label>
            <select name="shift_type" class="form-control" required>
                <option value="morning">Sabah</option>
                <option value="evening">Akşam</option>
            </select>
        </div>
        <div class="form-group">
            <label>Açılış Bakiyesi (TL)</label>
            <input type="number" step="0.01" name="opening_balance" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Vardiya Aç</button>
    </form>
    
    <!-- Vardiya Kapat -->
    <h3>Vardiya Kapat</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="close_shift" value="1">
        <div class="form-group">
            <label>Aktif Vardiya</label>
            <select name="shift_id" class="form-control" required>
                <option value="">Seçin</option>
                <?php
                global $db;
                $query = "SELECT id, shift_type, opened_at FROM shifts WHERE branch_id = ? AND status = 'open'";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($shifts as $shift) {
                    echo "<option value='{$shift['id']}'>{$shift['shift_type']} ({$shift['opened_at']})</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label>Kapanış Bakiyesi (TL)</label>
            <input type="number" step="0.01" name="closing_balance" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Vardiya Kapat</button>
    </form>
    
    <h3>Vardiya Listesi</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Vardiya Tipi</th>
                <th>Kasiyer</th>
                <th>Açılış (TL)</th>
                <th>Kapanış (TL)</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = "SELECT s.shift_type, p.name, s.opening_balance, s.closing_balance, s.status 
                      FROM shifts s JOIN personnel p ON s.cashier_id = p.id 
                      WHERE s.branch_id = ? ORDER BY s.opened_at DESC LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($shifts as $shift) {
                echo "<tr>
                    <td>{$shift['shift_type']}</td>
                    <td>{$shift['name']}</td>
                    <td>{$shift['opening_balance']}</td>
                    <td>" . ($shift['closing_balance'] ?: '-') . "</td>
                    <td>{$shift['status']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>