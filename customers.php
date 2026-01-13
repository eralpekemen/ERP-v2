<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';
require_once 'config_main.php';

if ($_SESSION['personnel_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

$personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';

// Arama & Filtre
$search = trim($_GET['search'] ?? '');
$limit = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

// Toplam müşteri sayısı
$count_stmt = $main_db->prepare("SELECT COUNT(*) FROM customers $where");
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_customers = $count_stmt->get_result()->fetch_row()[0];
$total_pages = max(1, ceil($total_customers / $limit));

// Müşteri listesi
$sql = "SELECT id, name, phone, email, total_spent, created_at, birth_date FROM customers $where ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = $main_db->prepare($sql);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Müşteriler • Yönetim Paneli</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <style>
        .table td { vertical-align: middle; }
        .badge-birthday { background: #ffc107; color: #000; }
    </style>
</head>
<body>
<div id="app" class="app">

    <!-- HEADER (senin istediğin gibi, include olmadan) -->
    <div id="header" class="app-header">
        <div class="mobile-toggler">
            <button type="button" class="menu-toggler" data-toggle="sidebar-mobile">
                <span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="brand">
            <div class="desktop-toggler">
                <button type="button" class="menu-toggler" data-toggle="sidebar-minify">
                    <span class="bar"></span><span class="bar"></span>
                </button>
            </div>
            <a href="index.php" class="brand-logo">
                <img src="assets/img/logo.png" class="invert-dark" alt height="20">
            </a>
        </div>
        <div class="menu">
            <form class="menu-search" method="GET">
                <div class="menu-search-icon"><i class="fa fa-search"></i></div>
                <div class="menu-search-input">
                    <input type="text" name="search" class="form-control" placeholder="Müşteri ara..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </form>
            <div class="menu-item dropdown">
                <a href="#" data-bs-toggle="dropdown" class="menu-link">
                    <div class="menu-icon"><i class="fa fa-bell"></i></div>
                    <div class="menu-label">3</div>
                </a>
            </div>
            <div class="menu-item dropdown">
                <a href="#" data-bs-toggle="dropdown" class="menu-link">
                    <div class="menu-img online">
                        <img src="assets/img/user/user.jpg" alt class="ms-100 mh-100 rounded-circle">
                    </div>
                    <div class="menu-text"><?= htmlspecialchars($personnel_name) ?></div>
                </a>
                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="profile.php">Profil</a>
                    <a class="dropdown-item" href="logout.php">Çıkış</a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>

    <div id="content" class="app-content">
        <div class="d-flex align-items-center mb-3">
            <div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Ana Sayfa</a></li>
                    <li class="breadcrumb-item active">Müşteriler</li>
                </ol>
                <h1 class="page-header mb-0">
                    Müşteriler <small><?= number_format($total_customers) ?> kayıt</small>
                </h1>
            </div>
            <div class="ms-auto">
                <a href="customer_add.php" class="btn btn-success">
                    <i class="fa fa-plus"></i> Yeni Müşteri Ekle
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover text-nowrap">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ad Soyad</th>
                                <th>Telefon</th>
                                <th>E-posta</th>
                                <th>Toplam Harcama</th>
                                <th>Kayıt Tarihi</th>
                                <th>Doğum Günü</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers->num_rows == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fa fa-users fa-3x mb-3"></i><br>
                                        Müşteri bulunamadı
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $no = $offset + 1;
                                while ($c = $customers->fetch_assoc()): 
                                    $birth = $c['birth_date'] ? date('d.m', strtotime($c['birth_date'])) : '';
                                    $today = date('d.m');
                                    $is_birthday = $birth && $birth === $today;
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                                            <?php if ($is_birthday): ?>
                                                <span class="badge badge-birthday ms-2">DOĞUM GÜNÜ!</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['phone'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
                                        <td><strong>₺<?= number_format($c['total_spent'], 0) ?></strong></td>
                                        <td><?= date('d.m.Y', strtotime($c['created_at'])) ?></td>
                                        <td><?= $birth ?: '-' ?></td>
                                        <td>
                                            <a href="customer_detail.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fa fa-eye"></i> Detay
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Sayfalama -->
                <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">Önceki</a>
                        </li>
                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Sonraki</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="assets/js/vendor.min.js?v=<?= time() ?>"></script>
 <script src="assets/js/app.min.js?v=<?= time() ?>"></script>
<script>
    window.cloudflareRocketLoaderDisabled = true;
</script>
</body>
</html>
<?php ob_end_flush(); ?>