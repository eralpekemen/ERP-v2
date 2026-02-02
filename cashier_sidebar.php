<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
<div id="sidebar" class="app-sidebar">
    <div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
        <div class="menu">
            <div class="menu-header">Kasiyer Menüsü</div>
            <div class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <a href="dashboard.php" class="menu-link">
                    <span class="menu-icon"><i class="fa fa-laptop"></i></span>
                    <span class="menu-text">Ana Sayfa</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="pos.php" class="menu-link">
                    <span class="menu-icon"><i class="fa fa-cash-register"></i></span>
                    <span class="menu-text">POS</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="shift_management.php" class="menu-link"> <!-- Vardiya yönetimi link -->
                    <span class="menu-icon"><i class="fa fa-clock"></i></span>
                    <span class="menu-text">Vardiyalarım</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="#" class="menu-link" data-bs-toggle="modal" data-bs-target="#leaveModal"> <!-- Modal tetikle -->
                    <span class="menu-icon"><i class="fa fa-calendar-check"></i></span>
                    <span class="menu-text">İzin Talebi</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="notifications.php" class="menu-link"> <!-- Mevcut notifications.php -->
                    <span class="menu-icon"><i class="fa fa-envelope"></i></span>
                    <span class="menu-text">Mesajlar</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="#" class="menu-link" data-bs-toggle="modal" data-bs-target="#tasksModal">
                    <span class="menu-icon"><i class="fa fa-tasks"></i></span>
                    <span class="menu-text">Görevlerim</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="#" class="menu-link" data-bs-toggle="modal" data-bs-target="#documentModal"> <!-- Modal tetikle -->
                    <span class="menu-icon"><i class="fa fa-file-upload"></i></span>
                    <span class="menu-text">Evrak Yükle</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="#" class="menu-link" data-bs-toggle="modal" data-bs-target="#profileModal"> <!-- Modal tetikle -->
                    <span class="menu-icon"><i class="fa fa-user-edit"></i></span>
                    <span class="menu-text">Profilim</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="logout.php" class="menu-link">
                    <span class="menu-icon"><i class="fa fa-sign-out-alt"></i></span>
                    <span class="menu-text">Çıkış</span>
                </a>
            </div>
        </div>
    </div>
    <button class="app-sidebar-mobile-backdrop" data-dismiss="sidebar-mobile"></button>
</div>