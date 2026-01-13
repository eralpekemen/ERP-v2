<?php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'acdb';

$db = new mysqli($host, $username, $password, $database);
if ($db->connect_error) {
    die("Veritabanı bağlantı hatası: " . $db->connect_error);
}
$db->set_charset("utf8mb4");
?>