<?php
ob_start();
session_start();
require_once 'config.php';

// Müşteri ekranı olduğu için oturum ve vardiya kontrolü YOK
// Herkes görebilir

$cart = $_SESSION['pos_cart'] ?? [];
$discount = $_SESSION['pos_discount'] ?? 0;

$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['qty'];
}
$tax = $subtotal * 0.20; // %20 KDV
$total = $subtotal + $tax - $discount;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Ekranı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #000;
            color: #0f0;
            font-family: 'Courier New', monospace;
            margin: 0;
            padding: 20px;
            height: 100vh;
            overflow: hidden;
        }
        .container {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .price {
            font-size: 4.5rem;
            font-weight: bold;
            text-align: center;
            letter-spacing: 8px;
            text-shadow: 0 0 20px #0f0;
        }
        .label {
            font-size: 2rem;
            text-align: center;
            margin: 15px 0;
        }
        .items {
            max-height: 40vh;
            overflow-y: auto;
            background: rgba(0,255,0,0.05);
            padding: 15px;
            border-radius: 15px;
            margin: 20px 0;
        }
        .item-line {
            display: flex;
            justify-content: space-between;
            font-size: 1.8rem;
            padding: 8px 0;
            border-bottom: 1px dashed #0f0;
        }
        .footer {
            margin-top: auto;
            text-align: center;
            font-size: 2rem;
            opacity: 0.8;
        }
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #000; }
        ::-webkit-scrollbar-thumb { background: #0f0; border-radius: 5px; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="text-center mb-4" style="font-size: 3.5rem;">HOŞ GELDİNİZ</h1>

    <div class="items">
        <?php if (empty($cart)): ?>
            <p class="text-center" style="font-size: 2.5rem; opacity: 0.6;">Sipariş bekleniyor...</p>
        <?php else: ?>
            <?php foreach ($cart as $item): ?>
                <div class="item-line">
                    <span><?= htmlspecialchars($item['name']) ?> x<?= $item['qty'] ?></span>
                    <span><?= number_format($item['price'] * $item['qty'], 2) ?> ₺</span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="label">Ara Toplam</div>
    <div class="price"><?= number_format($subtotal, 2) ?> ₺</div>

    <?php if ($discount > 0): ?>
    <div class="label text-warning">İndirim</div>
    <div class="price text-warning">-<?= number_format($discount, 2) ?> ₺</div>
    <?php endif; ?>

    <div class="label">KDV (%20)</div>
    <div class="price"><?= number_format($tax, 2) ?> ₺</div>

    <hr style="border: 2px dashed #0f0; margin: 30px 0;">

    <div class="label" style="font-size: 3.5rem;">TOPLAM</div>
    <div class="price" style="font-size: 7rem; color: #0f0;">
        <?= number_format($total, 2) ?> ₺
    </div>

    <div class="footer">
        <?= date('H:i') ?> | Teşekkür ederiz :)
    </div>
</div>

<script>
    // Her 2 saniyede bir otomatik yenile
    setTimeout(function() {
        location.reload();
    }, 2000);
</script>
</body>
</html>
<?php ob_end_flush(); ?>