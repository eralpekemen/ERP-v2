<?php
require_once 'template.php';
require_once 'functions/notifications.php';

display_header('Bildirimler');
$branch_id = get_current_branch();
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
?>

<div class="container">
    <h2>Bildirimler</h2>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Mesaj</th>
                <th>Tür</th>
                <th>Tarih</th>
            </tr>
        </thead>
        <tbody>
            <?php
            global $db;
            $query = "SELECT message, type, created_at FROM notifications WHERE branch_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("iii", $branch_id, $limit, $offset);
            $stmt->execute();
            $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($notifications as $notification) {
                $alert_class = $notification['type'] == 'success' ? 'alert-success' : ($notification['type'] == 'error' ? 'alert-danger' : 'alert-warning');
                echo "<tr class='$alert_class'>
                    <td>{$notification['message']}</td>
                    <td>{$notification['type']}</td>
                    <td>{$notification['created_at']}</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>

    <!-- Sayfalama -->
    <nav>
        <ul class="pagination">
            <?php
            $query = "SELECT COUNT(*) as total FROM notifications WHERE branch_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $total_pages = ceil($total / $limit);
            for ($i = 1; $i <= $total_pages; $i++) {
                echo "<li class='page-item " . ($page == $i ? 'active' : '') . "'>
                    <a class='page-link' href='notifications.php?page=$i'>$i</a>
                </li>";
            }
            ?>
        </ul>
    </nav>
</div>

<?php display_footer(); ?>