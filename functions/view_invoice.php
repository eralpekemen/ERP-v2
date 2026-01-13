<?php
// Çıktı tamponunu başlat
ob_start();

session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';
require_once 'functions/payments.php';
require_once 'functions/notifications.php';

// Oturum kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id'])) {
    header("Location: login.php");
    exit;
}

// Şube ID'si
$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// Fişlerin listesini al
$query = "SELECT i.id, i.sale_id, i.created_at, s.table_id, t.number AS table_number, c.name AS customer_name, s.total_amount 
          FROM invoices i 
          LEFT JOIN sales s ON i.sale_id = s.id 
          LEFT JOIN tables t ON s.table_id = t.id 
          LEFT JOIN customers c ON s.customer_id = c.id 
          WHERE i.branch_id = ? 
          ORDER BY i.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'get_invoice_content') {
        $sale_id = intval($_POST['sale_id']);
        $query = "SELECT content FROM invoices WHERE sale_id = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ii", $sale_id, $branch_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            echo json_encode(['success' => true, 'content' => $result['content']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fiş bulunamadı!']);
        }
        exit;
    }
}

display_header('Fiş Görüntüleme');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiş Görüntüleme</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .invoice-table th, .invoice-table td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        .invoice-table th { background-color: #f2f2f2; }
        .btn-view { margin-right: 5px; }
        .modal-xl { max-width: 800px; }
        .invoice-container { width: 100%; max-width: 80mm; padding: 5mm; margin: 0 auto; }
        .invoice-header { text-align: center; }
        .invoice-header img { max-width: 50mm; }
        .invoice-table-modal { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .invoice-table-modal th, .invoice-table-modal td { border: 1px solid #000; padding: 5px; text-align: left; }
        .invoice-table-modal th { background-color: #f2f2f2; }
        .invoice-summary { margin-top: 10px; }
        .invoice-qr { text-align: center; margin-top: 10px; }
        #qrcode { margin: 0 auto; }
        @media print {
            body { font-family: monospace; font-size: 12px; }
            .invoice-container { width: 80mm; }
            .invoice-table-modal th, .invoice-table-modal td { border: none; font-size: 10px; }
            .invoice-summary p { font-size: 10px; }
            .invoice-qr img { width: 40mm; height: 40mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<div id="app" class="app app-content-full-height app-without-sidebar app-without-header">
    <div id="content" class="app-content p-3">
        <h1 class="page-header">Fiş Görüntüleme</h1>
        <div class="card">
            <div class="card-body">
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Fiş ID</th>
                            <th>Satış ID</th>
                            <th>Masa No</th>
                            <th>Müşteri</th>
                            <th>Tutar</th>
                            <th>Tarih</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="7" class="text-center">Kayıtlı fiş bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($invoice['id']); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['sale_id']); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['table_number'] ?? 'Bilinmeyen Masa'); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['customer_name'] ?? 'Misafir'); ?></td>
                                    <td><?php echo number_format($invoice['total_amount'], 2); ?> TL</td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($invoice['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm btn-view" onclick="viewInvoice(<?php echo $invoice['sale_id']; ?>)">
                                            <i class="fa fa-eye"></i> Görüntüle
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fiş Görüntüleme Modal -->
        <div class="modal fade" id="invoiceModal" tabindex="-1" aria-labelledby="invoiceModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="invoiceModalLabel">Fiş Detayı</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="invoice-content"></div>
                    </div>
                    <div class="modal-footer no-print">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-primary" onclick="printInvoice()">Yazdır</button>
                        <button type="button" class="btn btn-success" onclick="saveAsPDF()">PDF Olarak Kaydet</button>
                    </div>
                </div>
            </div>
        </div>

        <a href="#" data-click="scroll-top" class="btn-scroll-top fade"><i class="fa fa-arrow-up"></i></a>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.min.js"></script>

    <script>
        let currentSaleId = null;

        function viewInvoice(saleId) {
            console.log('viewInvoice called with saleId:', saleId);
            currentSaleId = saleId;
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'get_invoice_content');
            formData.append('sale_id', saleId);
            formData.append('ajax', '1');

            $.ajax({
                url: 'pos.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    console.log('viewInvoice AJAX success:', response);
                    if (response.success) {
                        $('#invoice-content').html(response.content + '<div class="invoice-qr"><div id="qrcode"></div></div>');
                        new QRCode(document.getElementById("qrcode"), {
                            text: "#" + saleId,
                            width: 100,
                            height: 100
                        });
                        $('#invoiceModal').modal('show');
                    } else {
                        showToast(response.message || 'Fiş içeriği alınamadı!', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('viewInvoice AJAX error:', xhr.status, xhr.responseText);
                    showToast('Fiş içeriği alınamadı: ' + (xhr.responseJSON?.message || error), 'danger');
                }
            });
        }

        function printInvoice() {
            const printContent = document.getElementById('invoice-content').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Fiş - #${currentSaleId}</title>
                        <style>
                            body { font-family: monospace; font-size: 12px; }
                            .invoice-container { width: 80mm; padding: 5mm; }
                            .invoice-table-modal th, .invoice-table-modal td { border: none; font-size: 10px; }
                            .invoice-summary p { font-size: 10px; }
                            .invoice-qr img { width: 40mm; height: 40mm; }
                            .no-print { display: none; }
                        </style>
                    </head>
                    <body onload="window.print(); window.close();">
                        ${printContent}
                    </body>
                </html>
            `);
            printWindow.document.close();
        }

        function saveAsPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: [80, 200]
            });
            doc.html(document.getElementById('invoice-content'), {
                callback: function (doc) {
                    doc.save(`fatura_#${currentSaleId}.pdf`);
                },
                x: 5,
                y: 5,
                width: 70,
                windowWidth: 300
            });
        }

        function showToast(message, type) {
            console.log('showToast called:', message, type);
            const toastContainer = $('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
            toastContainer.html(`
                <div class="toast show" role="alert">
                    <div class="toast-body bg-${type} text-white">${message}</div>
                </div>
            `);
            $('body').append(toastContainer);
            setTimeout(() => toastContainer.remove(), 3000);
        }
    </script>

    <?php display_footer(); ?>
</body>
</html>
<?php
ob_end_flush();
?>