<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';
require_once 'functions/pos_fast.php';

if (!isset($_SESSION['personnel_id']) || !in_array($_SESSION['personnel_type'], ['admin', 'cashier'])) {
    header("Location: login.php"); exit;
}

$branch_id = $_SESSION['branch_id'];
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// Vardiya kontrolü
$shift = $db->query("SELECT id FROM shifts WHERE personnel_id = $personnel_id AND branch_id = $branch_id AND status = 'open'")->fetch_assoc();
if (!$shift) { header("Location: dashboard.php"); exit; }

// Kategoriler
$categories = $db->query("SELECT id, name FROM product_categories WHERE branch_id = $branch_id ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Ürünler
$products = [];
foreach ($categories as $cat) {
    $stmt = $db->prepare("SELECT id, name, unit_price, barcode, image_url FROM products WHERE category_id = ? AND branch_id = ? ORDER BY name");
    $stmt->bind_param("ii", $cat['id'], $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($p = $res->fetch_assoc()) {
        if (empty($p['image_url'])) $p['image_url'] = 'https://placehold.co/300x300?text=' . urlencode($p['name']);
        $p['category_filter'] = $cat['id'];
        $products[] = $p;
    }
}

// Müşteriler
$customers = $db->query("SELECT id, name FROM customers WHERE branch_id = $branch_id ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Alçitepe Cafe | Studio POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- ORİJİNAL STUDIO DOSYALARI - HİÇ DOKUNMADIM -->
<link href="assets/css/vendor.min.css" rel="stylesheet">
<link href="assets/css/app.min.css" rel="stylesheet">
</head>
<body class="pace-top">

<div id="app" class="app app-content-full-height app-without-sidebar app-without-header">
<div id="content" class="app-content p-0">
<div class="pos pos-with-menu pos-with-sidebar" id="pos">
<div class="pos-container">

<!-- SOL MENU - KATEGORİLER -->
<div class="pos-menu">
  <div class="logo">
    <a href="#"><div class="logo-img"><i class="fa fa-bowl-food"></i></div><div class="logo-text">Alçitepe Cafe</div></a>
  </div>
  <div class="nav-container">
    <div class="h-100" data-scrollbar="true" data-skip-mobile="true">
      <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link active" href="#" data-filter="all"><i class="fa fa-fw fa-utensils"></i> Tüm Ürünler</a></li>
        <?php foreach($categories as $cat): ?>
          <li class="nav-item"><a class="nav-link" href="#" data-filter="<?= $cat['id'] ?>"><i class="fa fa-fw fa-tag"></i> <?= htmlspecialchars($cat['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<!-- ORTA ÜRÜNLER - SENİN VERİTABANINDAN ÇEKİYOR -->
<div class="pos-content">
  <div class="pos-content-container h-100">
    <div class="row gx-4" id="product-container">
      <?php foreach($products as $p): ?>
        <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-4 col-sm-6 pb-4" data-type="<?= $p['category_filter'] ?>">
          <a href="#" class="pos-product" onclick="selectProduct(<?= $p['id'] ?>); return false;">
            <div class="img" style="background-image: url('<?= $p['image_url'] ?>')"></div>
            <div class="info">
              <div class="title"><?= htmlspecialchars($p['name']) ?></div>
              <div class="price"><?= number_format($p['unit_price'], 2) ?> TL</div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- SAĞ SEPET - ORİJİNAL STUDIO -->
<div class="pos-order">
  <div class="pos-order-header">SEPET</div>
  <div class="pos-order-body" id="order-items"></div>
  <div class="pos-order-footer">
    <div class="total-price" id="total-price">0.00 TL</div>
    <button class="btn btn-theme btn-lg w-100" onclick="openPaymentModal()">ÖDEME AL</button>
  </div>
</div>

</div>
</div>
</div>

<!-- ÜRÜN DETAY MODAL - ORİJİNAL -->
<div class="modal modal-pos fade" id="modalPosItem">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0">
      <a href="#" data-bs-dismiss="modal" class="btn-close position-absolute top-0 end-0 m-4"></a>
      <div class="modal-pos-product">
        <div class="modal-pos-product-img"><div class="img" id="modal-img"></div></div>
        <div class="modal-pos-product-info">
          <div class="fs-4 fw-semibold" id="modal-title"></div>
          <div class="fs-3 fw-bold mb-3" id="modal-price"></div>
          <div class="d-flex mb-3">
            <a href="#" class="btn btn-secondary" onclick="changeQty(-1)"><i class="fa fa-minus"></i></a>
            <input type="text" class="form-control w-50px fw-bold mx-2 text-center" id="modal-qty" value="1">
            <a href="#" class="btn btn-secondary" onclick="changeQty(1)"><i class="fa fa-plus"></i></a>
          </div>
          <hr class="opacity-1">
          <div class="row">
            <div class="col-4"><a href="#" class="btn btn-default fw-semibold mb-0 d-block py-3" data-bs-dismiss="modal">İptal</a></div>
            <div class="col-8"><a href="#" class="btn btn-theme fw-semibold d-flex justify-content-center align-items-center py-3 m-0" onclick="addToCartFromModal()">Sepete Ekle</a></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- YENİ: ÖDEME MODAL -->
<div class="modal fade" id="paymentModal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5>Ödeme Al</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <h2 class="text-center mb-4" id="payment-total">0.00 TL</h2>
        <select class="form-select mb-3" id="customer-select">
          <option value="">Müşteri Yok</option>
          <?php foreach($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="d-grid gap-2">
          <button class="btn btn-success btn-lg" onclick="completePayment('Nakit')">NAKİT</button>
          <button class="btn btn-primary btn-lg" onclick="completePayment('Kredi Kartı')">KREDİ KARTI</button>
          <button class="btn btn-warning btn-lg" onclick="completePayment('Yemek Kartı')">YEMEK KARTI</button>
          <button class="btn btn-purple btn-lg" id="open-account-btn" style="display:none;" onclick="completePayment('Açık Hesap')">AÇIK HESAP</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ORİJİNAL JS DOSYALARI -->
<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
// Senin ürünlerin
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
let cart = [];
let currentProduct = null;

function selectProduct(id) {
    currentProduct = products.find(p => p.id == id);
    document.getElementById('modal-img').style.backgroundImage = `url('${currentProduct.image_url}')`;
    document.getElementById('modal-title').textContent = currentProduct.name;
    document.getElementById('modal-price').textContent = parseFloat(currentProduct.unit_price).toFixed(2) + ' TL';
    document.getElementById('modal-qty').value = 1;
    new bootstrap.Modal(document.getElementById('modalPosItem')).show();
}

function changeQty(delta) {
    let qty = parseInt(document.getElementById('modal-qty').value) + delta;
    if (qty < 1) qty = 1;
    document.getElementById('modal-qty').value = qty;
}

function addToCartFromModal() {
    const qty = parseInt(document.getElementById('modal-qty').value);
    const existing = cart.find(i => i.id == currentProduct.id);
    if (existing) existing.qty += qty;
    else cart.push({...currentProduct, qty});
    updateCart();
    bootstrap.Modal.getInstance(document.getElementById('modalPosItem')).hide();
}

function updateCart() {
    const container = document.getElementById('order-items');
    container.innerHTML = '';
    let total = 0;
    cart.forEach((item, i) => {
        total += item.unit_price * item.qty;
        container.innerHTML += `
            <div class="pos-order-item">
                <div class="name">${item.name} x${item.qty}</div>
                <div class="price">${(item.unit_price * item.qty).toFixed(2)} TL</div>
                <a href="#" class="remove" onclick="cart.splice(${i},1); updateCart(); return false;">×</a>
            </div>`;
    });
    document.getElementById('total-price').textContent = total.toFixed(2) + ' TL';
    document.getElementById('payment-total').textContent = total.toFixed(2) + ' TL';
}

// Barkod
document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' && e.target.type === 'text') return;
    if (e.key === 'Enter') {
        const barcode = prompt('Barkod:');
        if (barcode) {
            const found = products.find(p => p.barcode === barcode.trim());
            if (found) selectProduct(found.id);
            else alert('Ürün bulunamadı');
        }
    }
});

// Kategori filtreleme
document.querySelectorAll('[data-filter]').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('[data-filter]').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
        const filter = link.getAttribute('data-filter');
        document.querySelectorAll('#product-container > div').forEach(item => {
            const type = item.getAttribute('data-type');
            item.style.display = (filter === 'all' || type == filter) ? 'block' : 'none';
        });
    });
});

// Ödeme
function openPaymentModal() {
    if (cart.length === 0) return alert('Sepet boş!');
    document.getElementById('customer-select').onchange = () => {
        document.getElementById('open-account-btn').style.display = 
            document.getElementById('customer-select').value ? 'block' : 'none';
    };
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function completePayment(method) {
    const customerId = document.getElementById('customer-select').value || null;
    if (method === 'Açık Hesap' && !customerId) return alert('Müşteri seçin!');

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=save_order&csrf_token=<?= $csrf_token ?>&items=${JSON.stringify(cart)}&payment_method=${method}&customer_id=${customerId || ''}`
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Ödeme alındı! Fiş basılıyor...');
            cart = []; updateCart();
            bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
        }
    });
}
</script>

<?php
// AJAX ödeme
if ($_POST['action'] ?? '' === 'save_order') {
    header('Content-Type: application/json');
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false]); exit; }

    $items = json_decode($_POST['items'], true);
    $method = $_POST['payment_method'];
    $customer_id = $_POST['customer_id'] ?? null;
    $total = array_sum(array_map(fn($i)=>$i['unit_price']*$i['qty'], $items));

    $db->begin_transaction();
    try {
        $stmt = $db->prepare("INSERT INTO orders (branch_id, personnel_id, customer_id, total_amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW())");
        $stmt->bind_param("iiids", $branch_id, $personnel_id, $customer_id, $total, $method);
        $stmt->execute();
        $order_id = $db->insert_id;

        $stmt2 = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $i) {
            $stmt2->bind_param("iisid", $order_id, $i['id'], $i['name'], $i['qty'], $i['unit_price']);
            $stmt2->execute();
        }
        $db->commit();

        print_receipt(['items'=>$items, 'total'=>$total, 'payment_method'=>$method]);

        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        $db->rollback();
        echo json_encode(['success'=>false]);
    }
    exit;
}
?>
</body>
</html>
<?php ob_end_flush(); ?>