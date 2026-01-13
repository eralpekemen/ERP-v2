<?php
require_once 'functions/reports.php';
require_once 'template.php';

display_header('Raporlar');
$branch_id = get_current_branch();
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
?>

<div class="container">
    <h2>Raporlar</h2>
    <form method="GET">
        <div class="form-group">
            <label>Başlangıç Tarihi</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
        </div>
        <div class="form-group">
            <label>Bitiş Tarihi</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
        </div>
        <button type="submit" class="btn btn-primary">Raporu Göster</button>
    </form>
    
    <h3>Gün Sonu Farkları</h3>
    <canvas id="dailyCloseChart"></canvas>
    
    <h3>İade Sebepleri</h3>
    <canvas id="returnReasonChart"></canvas>
    
    <h3>Satış Özeti</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Şube</th>
                <th>Tarih</th>
                <th>Toplam Satış (TL)</th>
                <th>Toplam İade (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            global $db;
            $query = "SELECT b.name, DATE(s.created_at) as sale_date, 
                      SUM(s.total_amount) as total_sales, 
                      COALESCE(SUM(r.amount), 0) as total_returns 
                      FROM sales s 
                      JOIN branches b ON s.branch_id = b.id 
                      LEFT JOIN returns r ON s.id = r.sale_id 
                      WHERE s.created_at BETWEEN ? AND ? 
                      GROUP BY b.id, DATE(s.created_at)";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($sales as $sale) {
                echo "<tr>
                    <td>{$sale['name']}</td>
                    <td>{$sale['sale_date']}</td>
                    <td>{$sale['total_sales']}</td>
                    <td>{$sale['total_returns']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php
$daily_closes = $db->query("SELECT b.name, dc.cash_difference, dc.pos_difference 
                             FROM daily_closes dc 
                             JOIN branches b ON dc.branch_id = b.id 
                             WHERE dc.close_date BETWEEN '$start_date' AND '$end_date'")->fetch_all(MYSQLI_ASSOC);
$labels = array_column($daily_closes, 'name');
$cash_differences = array_column($daily_closes, 'cash_difference');
$pos_differences = array_column($daily_closes, 'pos_difference');
?>
new Chart(document.getElementById('dailyCloseChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($labels); ?>,
        datasets: [
            {
                label: 'Nakit Farkı (TL)',
                data: <?php echo json_encode($cash_differences); ?>,
                backgroundColor: '#36A2EB',
                borderColor: '#2C83C3',
                borderWidth: 1
            },
            {
                label: 'POS Farkı (TL)',
                data: <?php echo json_encode($pos_differences); ?>,
                backgroundColor: '#FF6384',
                borderColor: '#D35400',
                borderWidth: 1
            }
        ]
    },
    options: {
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Fark (TL)' } },
            x: { title: { display: true, text: 'Şube' } }
        },
        plugins: {
            legend: { display: true },
            title: { display: true, text: 'Şube Bazlı Gün Sonu Farkları' }
        }
    }
});

<?php
$reasons = $db->query("SELECT reason, COUNT(id) as count 
                        FROM returns 
                        WHERE created_at BETWEEN '$start_date' AND '$end_date' 
                        GROUP BY reason")->fetch_all(MYSQLI_ASSOC);
$reason_labels = array_column($reasons, 'reason');
$reason_counts = array_column($reasons, 'count');
?>
new Chart(document.getElementById('returnReasonChart'), {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($reason_labels); ?>,
        datasets: [{
            label: 'İade Sebepleri',
            data: <?php echo json_encode($reason_counts); ?>,
            backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56'],
            borderColor: ['#D35400', '#2C83C3', '#FFB300'],
            borderWidth: 1
        }]
    },
    options: {
        plugins: {
            legend: { display: true, position: 'top' },
            title: { display: true, text: 'İade Sebepleri Dağılımı' }
        }
    }
});
</script>

<?php display_footer(); ?>