<?php
function get_sales_report($branch_id, $start_date, $end_date) {
    global $db;
    $query = "SELECT b.name, DATE(s.created_at) as sale_date, 
              SUM(s.total_amount) as total_sales, 
              COALESCE(SUM(r.amount), 0) as total_returns 
              FROM sales s 
              JOIN branches b ON s.branch_id = b.id 
              LEFT JOIN returns r ON s.id = r.sale_id 
              WHERE s.branch_id = ? AND s.created_at BETWEEN ? AND ? 
              GROUP BY b.id, DATE(s.created_at)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iss", $branch_id, $start_date, $end_date);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_debt_report($branch_id) {
    global $db;
    $query = "SELECT c.name, c.debt_limit, COALESCE(SUM(oa.amount_due), 0) as total_debt 
              FROM customers c 
              LEFT JOIN open_accounts oa ON c.id = oa.customer_id AND oa.status = 'open' 
              WHERE c.branch_id = ? 
              GROUP BY c.id";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_branch_name($branch_id) {
    global $db;
    $query = "SELECT name FROM branches WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['name'] ?? 'Bilinmeyen Şube';
}
?>