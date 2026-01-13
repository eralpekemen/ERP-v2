<?php
require_once 'functions/daily_close.php';
require_once 'template.php';

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$close_date = date('Y-m-d');
$totals = calculate_system_totals($branch_id, $close_date);

display_header('Gün Sonu Kapanışı');

if ($_POST) {
    $result = process_daily_close(
        $branch_id, $personnel_id, $close_date,
        $_POST['cash_entered'], $_POST['pos_entered'], $_POST['note'] ?? '', $_POST['csrf_token']
    );
    if ($result) {
        echo "<div class='alert alert-success'>Gün sonu başarıyla tamamlandı!</div>";
    }
}
?>

<div class="container">
    <h2>Gün Sonu İşlemi - <?php echo $close_date; ?></h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="mb-3">
            <label>Nakit Toplam (TL):</label>
            <input type="number" step="0.01" name="cash_entered" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>POS Toplam (TL):</label>
            <input type="number" step="0.01" name="pos_entered" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Not (Opsiyonel):</label>
            <textarea name="note" class="form-control"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Gün Sonunu Kapat</button>
    </form>
    
    <h3>Vardiya Özeti</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Vardiya Tipi</th>
                <th>Kasiyer</th>
                <th>Açılış (TL)</th>
                <th>Kapanış (TL)</th>
                <th>Nakit (TL)</th>
                <th>POS (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $shifts = get_shifts_summary($branch_id, $close_date);
            foreach ($shifts as $shift) {
                echo "<tr>
                    <td>{$shift['shift_type']}</td>
                    <td>{$shift['cashier_name']}</td>
                    <td>{$shift['opening_balance']}</td>
                    <td>{$shift['closing_balance']}</td>
                    <td>{$shift['cash_total']}</td>
                    <td>{$shift['pos_total']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php display_footer(); ?>