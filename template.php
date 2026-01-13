<?php
function display_header($title) {
    // container-fluid gösterilmeyecek sayfalar
    $no_container_pages = ['login.php', 'lock_screen.php'];
    $is_no_container = in_array(basename($_SERVER['PHP_SELF']), $no_container_pages);
    
    // POS sayfaları için kontrol
    $is_pos_page = in_array(basename($_SERVER['PHP_SELF']), ['pos.php', 'pos_takeaway.php']);
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <?php if ($is_pos_page): ?>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="description" content>
            <meta name="author" content>
            <link href="assets/css/vendor.min.css" rel="stylesheet">
            <link href="assets/css/app.min.css" rel="stylesheet">
        <?php else: ?>
            <!-- Diğer sayfalar için varsayılan meta ve stiller -->
            <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php endif; ?>
    </head>
    <body class="<?php echo $is_pos_page ? 'pace-top' : ''; ?>">
        <?php if (!$is_no_container): ?>
            <!-- Varsayılan navbar diğer sayfalarda görünecek -->
            <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
                <div class="container-fluid">
                    <a class="navbar-brand" href="dashboard.php">ERP</a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="pos.php">POS</a></li>
                            <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
        <?php endif; ?>
    <?php
}
function display_footer() {
    // container-fluid gösterilmeyecek sayfalar
    $no_container_pages = ['login.php', 'lock_screen.php'];
    $is_no_container = in_array(basename($_SERVER['PHP_SELF']), $no_container_pages);
    
    // POS sayfaları için kontrol
    $is_pos_page = in_array(basename($_SERVER['PHP_SELF']), ['pos.php', 'pos_takeaway.php']);
    ?>
        <?php if (!$is_no_container): ?>
            </div>
        <?php endif; ?>
        <?php if ($is_pos_page): ?>
            <script src="assets/js/vendor.min.js"></script>
            <script src="assets/js/app.min.js"></script>
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="assets/js/demo/pos-customer-order.demo.js"></script>
        <?php else: ?>            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <?php endif; ?>
    </body>
    </html>
    <?php
}
?>