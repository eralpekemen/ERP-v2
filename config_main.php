<?php
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'main_db';

    $main_db = new mysqli($host, $username, $password, $database);
    if ($main_db->connect_error) {
        die("Veritabanı bağlantı hatası: " . $main_db->connect_error);
    }
    $main_db->set_charset("utf8mb4");
?>