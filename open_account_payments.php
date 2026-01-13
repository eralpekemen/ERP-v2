<?php
require_once 'functions/payments.php';
require_once 'template.php';

display_header('Açık Hesap Ödemeleri');
$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $open_account_id = intval($_POST['open_account_id']);
    $amount_paid = floatval($_POST['amount_paid']);
    $payment_type = $_POST['payment_type'];
    
    $result = process_open_account_payment($open_account_id, $branch_id, $personnel_id, $amount_paid, $payment_type);
    if ($result) {
        echo "<div class='alert alert-success'>Ödeme başarıyla işlendi!</div>";
    } else {
        echo "<div class='alert alert-danger'>Ödeme işlemi başarısız!</div>";
    }
}
?>

<div class="container">
    <h2>Açık Hesap Ödemeleri</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Açık Hesap Seç</label>
            <select name="open_account_id" class="form-control" required>
                <option value="">Seçin</option>
                <?php
                global $db;
                $query = "SELECT oa.id, c.name, oa.amount_due FROM open_accounts oa 
                          JOIN customers c ON oa.customer_id = c.id 
                          WHERE oa.branch_id = ? AND oa.status = 'open'";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($accounts as $account) {
                    echo "<option value='{$account['id']}'>{$account['name']} ({$account['amount_due']} TL)</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label>Ödeme Tutarı (TL)</label>
            <input type="number" step="0.01" name="amount_paid" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Ödeme Türü</label>
            <select name="payment_type" class="form-control" required>
                <option value="cash">Nakit</option>
                <option value="credit_card">Kredi Kartı</option>
                <option value="bank_transfer">Havale</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Ödemeyi Kaydet</button>
    </form>
</div>

<?php display_footer(); ?>