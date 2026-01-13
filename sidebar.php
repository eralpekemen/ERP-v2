<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <div id="sidebar" class="app-sidebar">
                    <div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
                        <div class="menu">
                            <div class="menu-header">Menü</div>
                            <div class="menu-item <?php echo $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>">
                                <a href="admin_dashboard.php" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-laptop"></i></span>
                                    <span class="menu-text">Ana Sayfa</span>
                                </a>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="javascript:;" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-chart-bar"></i></span>
                                    <span class="menu-text">Raporlar</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="report_daily.php" class="menu-link">
                                            <span class="menu-text">Günlük</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="report_monthly.php" class="menu-link">
                                            <span class="menu-text">Aylık</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="report_yearly.php" class="menu-link">
                                            <span class="menu-text">Yıllık</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="report_period.php" class="menu-link">
                                            <span class="menu-text">Dönemlik</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon">
                                        <i class="fa fa-envelope"></i>
                                        <span class="menu-icon-label">6</span>
                                    </span>
                                    <span class="menu-text">Mesajlar</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="email_inbox.html" class="menu-link">
                                            <span class="menu-text">Gelen Kutusu</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="email_compose.html" class="menu-link">
                                            <span class="menu-text">Giden Kutusu</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="javascript:;" class="menu-link">
                                    <div class="menu-icon">
                                        <i class="fa fa-wallet"></i>
                                    </div>
                                    <div class="menu-text d-flex align-items-center">POS System</div>
                                </a>
                            </div>
                            <div class="menu-item has-sub <?php echo in_array($current_page, ['menu_management.php', 'stock_count.php', 'recipe_management.php', 'ingredient_management.php', 'product_reports.php']) ? 'active' : ''; ?>">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-boxes"></i></span>
                                    <span class="menu-text">Ürünler</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item <?php echo $current_page == 'menu_management.php' ? 'active' : ''; ?>">
                                        <a href="menu_management.php" class="menu-link">
                                            <span class="menu-text">Ürünler</span>
                                        </a>
                                    </div>
                                    <div class="menu-item <?php echo $current_page == 'stock_count.php' ? 'active' : ''; ?>">
                                        <a href="stock_count.php" class="menu-link">
                                            <span class="menu-text">Stok Sayımı</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="stock_movements.php" class="menu-link">
                                            <span class="menu-text">Stok Hareketleri</span>
                                        </a>
                                    </div>
                                    <div class="menu-item <?php echo $current_page == 'recipe_management.php' ? 'active' : ''; ?>">
                                        <a href="recipe_management.php" class="menu-link">
                                            <span class="menu-text">Reçete Yönetimi</span>
                                        </a>
                                    </div>
                                    <div class="menu-item <?php echo $current_page == 'ingredient_management.php' ? 'active' : ''; ?>">
                                        <a href="ingredient_management.php" class="menu-link">
                                            <span class="menu-text">Malzemeler</span>
                                        </a>
                                    </div>
                                    <div class="menu-item <?php echo $current_page == 'product_reports.php' ? 'active' : ''; ?>">
                                        <a href="product_reports.php" class="menu-link">
                                            <span class="menu-text">Ürün Raporları</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="order_management.php" class="menu-link">
                                            <span class="menu-text">Sipariş Yönetimi</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-user"></i></span>
                                    <span class="menu-text">Personel Yönetimi</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="personnel.php" class="menu-link">
                                            <span class="menu-text">Tüm Personeller</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="personnel_reports.php" class="menu-link">
                                            <span class="menu-text">Personel Raporları</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-table"></i></span>
                                    <span class="menu-text">Masalar</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="table_elements.html" class="menu-link">
                                            <span class="menu-text">Table Elements</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="table_plugins.html" class="menu-link">
                                            <span class="menu-text">Table Plugins</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-users"></i></span>
                                    <span class="menu-text">Müşteriler</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="customers.php" class="menu-link">
                                            <span class="menu-text">Tüm Müşteriler</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item">
                                <a href="map.html" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-map-marker-alt"></i></span>
                                    <span class="menu-text">Kuryeler</span>
                                </a>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="#" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-code-branch"></i></span>
                                    <span class="menu-text">Şubeler</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a href="profile.html" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-user-circle"></i></span>
                                    <span class="menu-text">Profil</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a href="calendar.html" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-calendar"></i></span>
                                    <span class="menu-text">Takvim</span>
                                </a>
                            </div>
                            <div class="menu-item has-sub">
                                <a href="javascript:;" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-cog"></i></span>
                                    <span class="menu-text">Ayarlar</span>
                                    <span class="menu-caret"><b class="caret"></b></span>
                                </a>
                                <div class="menu-submenu">
                                    <div class="menu-item">
                                        <a href="settings_menu.php" class="menu-link">
                                            <span class="menu-text">Menü Ayarları</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="settings_personnel.php" class="menu-link">
                                            <span class="menu-text">Personel Ayarları</span>
                                        </a>
                                    </div>
                                    <div class="menu-item">
                                        <a href="settings_cash.php" class="menu-link">
                                            <span class="menu-text">Kasa Ayarları</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="menu-item">
                                <a href="helper.html" class="menu-link">
                                    <span class="menu-icon"><i class="fa fa-question-circle"></i></span>
                                    <span class="menu-text">Yardım</span>
                                </a>
                            </div>
                            <div class="p-3 px-4 mt-auto hide-on-minified">
                                <a href="documentation/index.html" class="btn btn-secondary d-block w-100 fw-600 rounded-pill">
                                    <i class="fa fa-code-branch me-1 ms-n1 opacity-5"></i> Kullanım Klavuzu
                                </a>
                            </div>
                        </div>
                    </div>
                    <button class="app-sidebar-mobile-backdrop" data-dismiss="sidebar-mobile"></button>
                </div>