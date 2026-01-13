<?php
require_once 'functions/payments.php';
require_once 'template.php';

display_header('POS - Ödeme İşlemi');
$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sale_id = intval($_POST['sale_id']);
    $payment_type = $_POST['payment_type'];
    $amount = floatval($_POST['amount']);
    
    $result = process_payment($sale_id, $branch_id, $personnel_id, $amount, $payment_type);
    if ($result) {
        echo "<div class='alert alert-success'>Ödeme başarıyla işlendi!</div>";
    } else {
        echo "<div class='alert alert-danger'>Ödeme işlemi başarısız!</div>";
    }
}
?>

<div class="container">
    <h2>POS - Ödeme İşlemi</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="form-group">
            <label>Satış ID</label>
            <input type="number" name="sale_id" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Ödeme Türü</label>
            <select name="payment_type" class="form-control" required>
                <option value="cash">Nakit</option>
                <option value="credit_card">Kredi Kartı</option>
                <option value="bank_transfer">Havale</option>
            </select>
        </div>
        <div class="form-group">
            <label>Tutar (TL)</label>
            <input type="number" step="0.01" name="amount" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Ödemeyi Kaydet</button>
    </form>
    
    <?php if (is_shift_supervisor($personnel_id)) { ?>
        <a href="returns.php" class="btn btn-warning">İade İşlemi</a>
    <?php } ?>
</div>

<?php display_footer(); ?>