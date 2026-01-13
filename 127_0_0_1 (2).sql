-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1:3306
-- Üretim Zamanı: 13 Oca 2026, 09:12:35
-- Sunucu sürümü: 8.2.0
-- PHP Sürümü: 8.2.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `acdb`
--
CREATE DATABASE IF NOT EXISTS `acdb` DEFAULT CHARACTER SET latin5 COLLATE latin5_turkish_ci;
USE `acdb`;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `assets`
--

DROP TABLE IF EXISTS `assets`;
CREATE TABLE IF NOT EXISTS `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(50) NOT NULL COMMENT 'Örn: TEL-001, LAP-045',
  `name` varchar(150) NOT NULL COMMENT 'İsim',
  `category` enum('Telefon','Laptop','Tablet','Anahtar','Üniforma','Diğer') DEFAULT 'Diğer',
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `status` enum('stokta','zimmetli','arızalı','kayıp') DEFAULT 'stokta',
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_code` (`asset_code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `assets`
--

INSERT INTO `assets` (`id`, `asset_code`, `name`, `category`, `brand`, `model`, `serial_number`, `purchase_date`, `price`, `status`, `notes`, `created_at`) VALUES
(1, 'TEL-001', 'iPhone 14 Pro', 'Telefon', 'Apple', 'iPhone 14 Pro 256GB', 'FX123456789ABC', '2024-01-15', 45000.00, 'zimmetli', 'Siyah renk, kılıf hediyeli', '2025-11-30 16:02:50'),
(2, 'LAP-001', 'MacBook Pro M2', 'Laptop', 'Apple', 'MacBook Pro 14\" M2 Pro', 'C02Z1234XYZ', '2024-03-20', 85000.00, 'stokta', '16GB RAM, 512GB SSD', '2025-11-30 16:02:50'),
(3, 'TAB-001', 'iPad Air 5', 'Tablet', 'Apple', 'iPad Air 64GB WiFi', 'MP123456789', '2024-06-10', 22000.00, 'zimmetli', 'Mavi renk', '2025-11-30 16:02:50'),
(4, 'UNI-101', 'Garson Üniforma Seti', 'Üniforma', 'CafeMarka', 'Siyah Gömlek + Pantolon', NULL, '2025-01-05', 1800.00, 'stokta', 'Beden: M', '2025-11-30 16:02:50'),
(5, 'ANA-001', 'Kasa Anahtarı', 'Anahtar', NULL, 'Yedek Kasa Anahtarı', NULL, NULL, NULL, 'stokta', 'Çelik, kırmızı etiketli', '2025-11-30 16:02:50'),
(6, 'TEL-002', 'Samsung Galaxy S23', 'Telefon', 'Samsung', 'S23 128GB', 'RF123456789XYZ', '2024-09-01', 28000.00, 'stokta', 'Yeşil renk', '2025-11-30 16:02:50');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `asset_assignments`
--

DROP TABLE IF EXISTS `asset_assignments`;
CREATE TABLE IF NOT EXISTS `asset_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `personnel_id` int NOT NULL,
  `assigned_by` int NOT NULL COMMENT 'Kim zimmetledi (admin id)',
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `returned_at` datetime DEFAULT NULL COMMENT 'NULL ise hâlâ zimmetli',
  `return_notes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`asset_id`,`returned_at`),
  KEY `personnel_id` (`personnel_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `asset_assignments`
--

INSERT INTO `asset_assignments` (`id`, `asset_id`, `personnel_id`, `assigned_by`, `assigned_at`, `returned_at`, `return_notes`) VALUES
(1, 6, 1, 4, '2025-12-11 00:52:40', '2025-12-11 00:52:43', NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `attendance`
--

DROP TABLE IF EXISTS `attendance`;
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `branches`
--

DROP TABLE IF EXISTS `branches`;
CREATE TABLE IF NOT EXISTS `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `address` text,
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `branches`
--

INSERT INTO `branches` (`id`, `name`, `address`, `status`) VALUES
(1, 'Merkez Şube', '1234 Sokak, İstanbul', 'active');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `branch_orders`
--

DROP TABLE IF EXISTS `branch_orders`;
CREATE TABLE IF NOT EXISTS `branch_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int NOT NULL,
  `total_items` int NOT NULL DEFAULT '0',
  `status` enum('pending','approved','rejected','delivered') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `branch_orders`
--

INSERT INTO `branch_orders` (`id`, `branch_id`, `total_items`, `status`, `created_at`) VALUES
(1, 1, 40, 'pending', '2025-11-27 19:23:48');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `branch_order_items`
--

DROP TABLE IF EXISTS `branch_order_items`;
CREATE TABLE IF NOT EXISTS `branch_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `cash_registers`
--

DROP TABLE IF EXISTS `cash_registers`;
CREATE TABLE IF NOT EXISTS `cash_registers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shift_id` int DEFAULT NULL,
  `sale_id` int DEFAULT NULL,
  `open_account_payment_id` int DEFAULT NULL,
  `pos_device_id` int DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `transaction_type` enum('sale','payment','refund') DEFAULT NULL,
  `payment_type` enum('cash','credit_card','bank_transfer','open_account') DEFAULT NULL,
  `return_reason` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `open_account_payment_id` (`open_account_payment_id`),
  KEY `pos_device_id` (`pos_device_id`),
  KEY `idx_cash_registers_shift_id` (`shift_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text,
  `branch_id` int DEFAULT NULL,
  `debt_limit` decimal(10,2) DEFAULT '0.00',
  `debt_limit_enabled` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_customers_debt_limit` (`debt_limit`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `customers`
--

INSERT INTO `customers` (`id`, `name`, `email`, `phone`, `address`, `branch_id`, `debt_limit`, `debt_limit_enabled`) VALUES
(1, 'Muhtelif Müşteri', NULL, NULL, NULL, 1, 5000.00, 0),
(2, 'test müşteri', NULL, NULL, NULL, 1, 2500.00, 0);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_promotions`
--

DROP TABLE IF EXISTS `customer_promotions`;
CREATE TABLE IF NOT EXISTS `customer_promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `promotion_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_customer_promo` (`customer_id`,`promotion_id`),
  KEY `promotion_id` (`promotion_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `daily_closes`
--

DROP TABLE IF EXISTS `daily_closes`;
CREATE TABLE IF NOT EXISTS `daily_closes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `personnel_id` int DEFAULT NULL,
  `close_date` date DEFAULT NULL,
  `cash_entered` decimal(10,2) DEFAULT NULL,
  `pos_entered` decimal(10,2) DEFAULT NULL,
  `cash_system` decimal(10,2) DEFAULT NULL,
  `pos_system` decimal(10,2) DEFAULT NULL,
  `cash_difference` decimal(10,2) DEFAULT NULL,
  `pos_difference` decimal(10,2) DEFAULT NULL,
  `note` text,
  `status` enum('pending','completed') DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`),
  KEY `idx_daily_closes_branch_id` (`branch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `departments`
--

DROP TABLE IF EXISTS `departments`;
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `departments`
--

INSERT INTO `departments` (`id`, `branch_id`, `name`) VALUES
(1, 1, 'MEYDAN'),
(2, 1, 'DUT ALTI'),
(3, 1, 'MEYDAN ALTI'),
(7, 1, 'İÇ KISIM'),
(8, 1, 'GAZ ODASI'),
(9, 1, 'YOL');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `document_types`
--

DROP TABLE IF EXISTS `document_types`;
CREATE TABLE IF NOT EXISTS `document_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `is_required` tinyint(1) DEFAULT '0',
  `sort_order` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=36 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `document_types`
--

INSERT INTO `document_types` (`id`, `name`, `is_required`, `sort_order`) VALUES
(1, 'Nüfus Cüzdanı Fotokopisi', 1, 1),
(2, 'Nüfus Kayıt Örneği', 1, 2),
(3, 'İkametgâh Belgesi', 1, 3),
(4, 'Adli Sicil Kaydı', 1, 4),
(5, 'Sağlık Raporu (İşe Giriş)', 1, 5),
(6, 'Askerlik Durum Belgesi', 1, 6),
(7, 'Diploma Fotokopisi', 1, 7),
(8, '2 Adet Biyometrik Fotoğraf', 1, 8),
(9, 'İş Sözleşmesi (İmzalı)', 1, 9),
(10, 'SGK İşe Giriş Bildirgesi', 1, 10),
(11, 'Vukuatlı Nüfus Kayıt Örneği', 1, 11),
(12, 'İş Sağlığı ve Güvenliği Eğitimi Sertifikası', 1, 12),
(13, 'Araç Zimmet Formu', 1, 13),
(14, 'İş Güvenliği Talimatı ve Taahhütnamesi', 1, 14),
(15, 'Mesleki Yeterlilik Belgesi', 1, 15),
(16, 'SRC Belgesi (Şoförler için)', 1, 16),
(17, 'Psikoteknik Muayene Raporu (Şoförler için)', 1, 17),
(18, 'Ebeveyn Muvafakatnamesi (18 yaş altı)', 1, 18),
(19, 'Çalışma İzni (Yabancı Personel)', 1, 19),
(20, 'Fazla Mesai Onay Belgesi', 1, 20),
(21, 'Yıllık İzin Formu', 1, 21),
(22, 'İstirahat Raporu Örnekleri', 1, 22),
(23, 'İş Kazası Tutanağı', 1, 23),
(24, 'Ücret Kesme Cezası Belgesi', 1, 24),
(25, 'İstifa Dilekçesi', 1, 25),
(26, 'İş Başvuru Formu', 0, 26),
(27, 'Performans Değerlendirme Belgeleri', 0, 27),
(28, 'Vizite Kağıtları', 0, 28),
(29, 'Referans Mektupları', 0, 29),
(30, 'Özgeçmiş', 0, 30),
(31, 'Bakmakla Yükümlü Kişiler Kimlik Fotokopileri', 0, 31),
(32, 'Bonservis/Hizmet Belgesi', 0, 32),
(33, 'Asgari Geçim İndirimi Belgesi', 0, 33),
(34, 'İhbarname', 0, 34),
(35, 'Kıdem ve İhbar Tazminatı Bordrosu', 0, 35);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `currency` varchar(3) NOT NULL,
  `rate` decimal(10,4) NOT NULL,
  `last_updated` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=343 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `exchange_rates`
--

INSERT INTO `exchange_rates` (`id`, `currency`, `rate`, `last_updated`) VALUES
(342, 'EUR', 50.3016, '2026-01-12 19:15:24'),
(341, 'USD', 43.0609, '2026-01-12 19:15:24');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ingredients`
--

DROP TABLE IF EXISTS `ingredients`;
CREATE TABLE IF NOT EXISTS `ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category_id` int DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'adet',
  `unit_cost` decimal(10,2) DEFAULT '0.00',
  `stock_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `current_qty` decimal(10,2) DEFAULT '0.00',
  `min_qty` decimal(10,2) DEFAULT '0.00',
  `branch_id` int DEFAULT '1',
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name_branch` (`name`,`branch_id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_branch` (`branch_id`),
  KEY `idx_category` (`category_id`)
) ENGINE=MyISAM AUTO_INCREMENT=62 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `ingredients`
--

INSERT INTO `ingredients` (`id`, `name`, `category_id`, `unit`, `unit_cost`, `stock_quantity`, `current_qty`, `min_qty`, `branch_id`, `last_updated`) VALUES
(6, 'Kaşar Peyniri', NULL, 'kg', 315.84, 1.20, 0.00, 0.00, 1, '2025-12-06 14:41:29'),
(7, 'Piliç Sucuk', NULL, 'kg', 207.00, 0.00, 0.00, 0.00, 1, '2025-12-06 14:42:42'),
(8, 'Beyaz Ekmek', NULL, 'adet', 15.00, 0.00, 0.00, 0.00, 1, '2025-12-07 00:19:53'),
(9, 'Sofralık Tuz', NULL, 'kg', 13.34, 0.00, 0.00, 0.00, 1, '2025-12-06 14:43:27'),
(10, 'Pul Biber', NULL, 'kg', 340.00, 0.04, 0.00, 0.00, 1, '2025-12-06 14:43:06'),
(11, 'Kekik', NULL, 'kg', 1045.00, 0.00, 0.00, 0.00, 1, '2025-12-06 14:42:06'),
(12, 'Margarin', NULL, 'kg', 92.00, 0.00, 0.00, 0.00, 1, '2025-12-06 14:42:18'),
(13, 'Tost Kağıdı', NULL, 'adet', 0.50, 0.00, 0.00, 0.00, 1, '2025-12-06 15:48:01'),
(14, 'Kare Peçete', NULL, 'adet', 0.11, 0.00, 0.00, 0.00, 1, '2025-12-06 15:48:37'),
(15, 'Islak Mendil', NULL, 'adet', 0.98, 0.00, 0.00, 0.00, 1, '2025-12-06 15:49:10'),
(16, 'Ketçap', NULL, 'kg', 63.50, 0.00, 0.00, 0.00, 1, '2025-12-06 15:50:28'),
(17, 'Mayonez', NULL, 'kg', 136.90, 0.00, 0.00, 0.00, 1, '2025-12-06 15:53:12'),
(18, 'Turşu Salatalık', NULL, 'kg', 79.86, 0.00, 0.00, 0.00, 1, '2025-12-06 15:55:39'),
(19, 'Beyaz Peynir', NULL, 'kg', 246.16, 0.00, 0.00, 0.00, 1, '2025-12-06 16:02:53'),
(20, 'Bazlama Ekmeği', NULL, 'adet', 19.00, 0.00, 0.00, 0.00, 1, '2025-12-07 00:21:20'),
(21, 'Dana Sucuk', NULL, 'kg', 640.00, 0.00, 0.00, 0.00, 1, '2025-12-07 00:25:27'),
(22, 'Türk Kahvesi', 1, 'kg', 835.00, 0.00, 0.00, 0.00, 1, '2025-12-07 00:42:34'),
(23, 'Kesme Şeker', NULL, 'kg', 52.50, 0.00, 0.00, 0.00, 1, '2025-12-07 00:38:04'),
(24, 'Ayvalık Tostu Ekmeği', NULL, 'adet', 20.00, 0.00, 0.00, 0.00, 1, '2025-12-13 17:27:22'),
(25, 'Dana Kavurma', NULL, 'kg', 1326.66, 0.00, 0.00, 0.00, 1, '2025-12-13 17:28:50'),
(26, 'Hindi Salam', NULL, 'kg', 235.71, 0.00, 0.00, 0.00, 1, '2025-12-13 17:37:01'),
(27, 'Hindi Sosis', NULL, 'kg', 378.57, 0.00, 0.00, 0.00, 1, '2025-12-13 17:44:52'),
(28, 'Cheddar Peyniri', NULL, 'kg', 790.00, 0.00, 0.00, 0.00, 1, '2025-12-13 17:46:25'),
(29, 'Domates', NULL, 'kg', 55.00, 0.00, 0.00, 0.00, 1, '2025-12-13 18:47:21'),
(30, 'Ev Yapımı Salça', NULL, 'kg', 250.00, 0.00, 0.00, 0.00, 1, '2025-12-13 18:47:57'),
(31, 'Zeytinyağı', NULL, 'kg', 450.00, 0.00, 0.00, 0.00, 1, '2025-12-13 18:48:15'),
(32, 'Dana Jambon', NULL, 'kg', 1699.00, 0.00, 0.00, 0.00, 1, '2025-12-13 19:14:39'),
(33, 'Sandwich Ekmeği', NULL, 'adet', 24.75, 0.00, 0.00, 0.00, 1, '2025-12-13 19:36:11'),
(34, 'Pesto Sos', NULL, 'kg', 752.89, 0.00, 0.00, 0.00, 1, '2025-12-13 19:40:06'),
(35, 'Çeçil Peyniri', NULL, 'kg', 639.90, 0.00, 0.00, 0.00, 1, '2025-12-13 19:41:10'),
(36, 'Marul', NULL, 'g', 0.20, 0.00, 0.00, 0.00, 1, '2025-12-13 19:55:43'),
(37, 'Yumurta', NULL, 'adet', 8.50, 0.00, 0.00, 0.00, 1, '2025-12-13 19:57:12'),
(38, 'Zeytin Ezmesi', NULL, 'kg', 347.05, 0.00, 0.00, 0.00, 1, '2025-12-13 19:57:50'),
(39, 'Hindi Jambon', NULL, 'kg', 673.60, 0.00, 0.00, 0.00, 1, '2025-12-13 20:07:35'),
(40, 'Espresso Çekirdek', NULL, 'kg', 703.70, 0.00, 0.00, 0.00, 1, '2025-12-13 23:17:14'),
(41, 'Süt', NULL, 'lt', 53.95, 0.00, 0.00, 0.00, 1, '2025-12-13 23:18:32'),
(42, 'Karamel Şurup', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:25:50'),
(43, 'Vanilya Şurup', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:26:02'),
(44, 'Çikolata Şurup', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:26:14'),
(45, 'Hazelnut Şuruğ', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:26:41'),
(46, 'Coconut Şurup', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:26:54'),
(47, 'Beyaz Çikolata Şurup', NULL, 'ml', 1.06, 0.00, 0.00, 0.00, 1, '2025-12-13 23:28:14'),
(48, '14oz Karton Bardak', NULL, 'adet', 2.62, 0.00, 0.00, 0.00, 1, '2025-12-13 23:58:04'),
(49, '14-16 oz Bardak Tututcu', NULL, 'adet', 0.83, 0.00, 0.00, 0.00, 1, '2025-12-14 00:02:01'),
(50, '14-16 oz Bardak Kapağı', NULL, 'adet', 1.31, 0.00, 0.00, 0.00, 1, '2025-12-14 00:08:38'),
(51, '300ml Soğuk Bardak', NULL, 'adet', 1.55, 0.00, 0.00, 0.00, 1, '2025-12-14 12:35:03'),
(52, '400ml Soğuk Bardak', NULL, 'adet', 2.71, 0.00, 0.00, 0.00, 1, '2025-12-14 12:37:06'),
(53, '500ml 16oz Soğuk Bardak', NULL, 'adet', 2.83, 0.00, 0.00, 0.00, 1, '2025-12-14 12:49:27'),
(54, 'Soğuk Deliklik Bombe Kapak', NULL, 'adet', 2.03, 0.00, 0.00, 0.00, 1, '2025-12-14 12:52:28'),
(55, 'Klipsli Soğuk Kapak', NULL, 'adet', 1.08, 0.00, 0.00, 0.00, 1, '2025-12-14 12:53:30'),
(56, 'Emzikli Soğuk Kapak', NULL, 'adet', 1.21, 0.00, 0.00, 0.00, 1, '2025-12-14 12:53:59'),
(57, 'Artı Delikli Bombe Soğuk Kapak', NULL, 'adet', 1.13, 0.00, 0.00, 0.00, 1, '2025-12-14 12:54:28'),
(58, 'Düz Artı Delikli Soğuk Kapak', NULL, 'adet', 1.07, 0.00, 0.00, 0.00, 1, '2025-12-14 12:55:08'),
(59, 'Siyah Kokteyl Pipet', NULL, 'adet', 0.64, 0.00, 0.00, 0.00, 1, '2025-12-14 12:56:21'),
(60, 'Siyah Frozen Pipet', NULL, 'adet', 0.20, 0.00, 0.00, 0.00, 1, '2025-12-14 13:29:09'),
(61, 'Bardak Su 180ml', NULL, 'adet', 2.00, 0.00, 0.00, 0.00, 1, '2025-12-14 13:45:36');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ingredient_categories`
--

DROP TABLE IF EXISTS `ingredient_categories`;
CREATE TABLE IF NOT EXISTS `ingredient_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `branch_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name_branch` (`name`,`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `ingredient_categories`
--

INSERT INTO `ingredient_categories` (`id`, `name`, `branch_id`, `created_at`) VALUES
(1, 'İçecek', 1, '2025-12-07 00:41:59'),
(2, 'DONDURMA', 1, '2025-12-11 00:50:58'),
(3, 'Yemek', 1, '2025-12-13 12:50:56'),
(4, 'Şurup', 1, '2025-12-13 23:23:04'),
(5, 'Zarf Malzeme', 1, '2025-12-13 23:58:03');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ingredient_movements`
--

DROP TABLE IF EXISTS `ingredient_movements`;
CREATE TABLE IF NOT EXISTS `ingredient_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ingredient_id` int NOT NULL,
  `type` enum('in','out') NOT NULL,
  `qty` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `sale_item_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ingredient_id` (`ingredient_id`),
  KEY `sale_item_id` (`sale_item_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `invoices`
--

DROP TABLE IF EXISTS `invoices`;
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `content` text NOT NULL,
  `branch_id` int NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `reason` text,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `personnel_id`, `start_date`, `end_date`, `reason`, `status`, `requested_at`) VALUES
(1, 1, '2025-11-14', '2025-11-14', 'haftalık', 'pending', '2025-11-14 10:52:17'),
(2, 1, '2025-11-26', '2025-11-27', 'tatil', 'pending', '2025-11-23 00:30:35'),
(3, 1, '2026-01-17', '2026-01-17', 'TEST', 'pending', '2026-01-09 11:06:18'),
(4, 1, '2026-01-29', '2026-01-29', 'tgest', 'pending', '2026-01-12 19:14:12');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `logs`
--

DROP TABLE IF EXISTS `logs`;
CREATE TABLE IF NOT EXISTS `logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action` varchar(50) DEFAULT NULL,
  `details` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `type` enum('success','error','warning','info') DEFAULT 'info',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `related_type` varchar(50) DEFAULT NULL,
  `related_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_branch_id` (`branch_id`),
  KEY `idx_personnel` (`personnel_id`),
  KEY `idx_related` (`related_type`,`related_id`)
) ENGINE=MyISAM AUTO_INCREMENT=73 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `notifications`
--

INSERT INTO `notifications` (`id`, `personnel_id`, `branch_id`, `message`, `type`, `created_at`, `related_type`, `related_id`) VALUES
(1, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 00:20:00', NULL, NULL),
(2, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 00:26:40', NULL, NULL),
(3, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 18:47:41', NULL, NULL),
(4, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 18:47:52', NULL, NULL),
(5, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 18:48:19', NULL, NULL),
(6, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 19:06:48', NULL, NULL),
(7, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 19:15:25', NULL, NULL),
(8, NULL, 1, 'Kasiyer kasa kapatma talebinde bulundu (Vardiya #30)', 'warning', '2025-11-11 19:21:43', NULL, NULL),
(9, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #33). Kapanış bakiyesini girin.', 'warning', '2025-11-11 19:48:35', NULL, NULL),
(10, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #34). Kapanış bakiyesini girin.', 'warning', '2025-11-11 19:48:46', NULL, NULL),
(11, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #35). Kapanış bakiyesini girin.', 'warning', '2025-11-11 20:01:34', NULL, NULL),
(12, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #36). Kapanış bakiyesini girin.', 'warning', '2025-11-11 21:45:11', NULL, NULL),
(13, NULL, 1, 'Vardiya #36 kapatıldı. Fark: 0.00 TL', 'info', '2025-11-11 21:48:45', NULL, NULL),
(14, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #37). Kapanış bakiyesini girin.', 'warning', '2025-11-11 21:49:15', NULL, NULL),
(15, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #38). Kapanış bakiyesini girin.', 'warning', '2025-11-12 10:35:34', NULL, NULL),
(16, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #39). Kapanış bakiyesini girin.', 'warning', '2025-11-12 11:00:08', NULL, NULL),
(17, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #40). Kapanış bakiyesini girin.', 'warning', '2025-11-12 11:02:09', NULL, NULL),
(18, NULL, 1, 'Vardiya #40 kapatıldı. Fark: -520.26 TL', 'info', '2025-11-12 11:45:32', NULL, NULL),
(19, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #41). Kapanış bakiyesini girin.', 'warning', '2025-11-12 11:51:13', NULL, NULL),
(20, NULL, 1, 'Vardiya #41 kapatıldı. Fark: 379.74 TL', 'info', '2025-11-12 11:51:43', NULL, NULL),
(21, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #42). Kapanış bakiyesini girin.', 'warning', '2025-11-12 12:12:07', NULL, NULL),
(22, NULL, 1, 'Vardiya #42 kapatıldı. Fark: -522.51 TL', 'info', '2025-11-12 12:13:39', NULL, NULL),
(23, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #43). Kapanış bakiyesini girin.', 'warning', '2025-11-12 12:20:46', NULL, NULL),
(24, NULL, 1, 'Vardiya #43 kapatıldı. Fark: 395.78 TL', 'info', '2025-11-12 12:22:17', NULL, NULL),
(25, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #44). Kapanış bakiyesini girin.', 'warning', '2025-11-12 12:31:26', NULL, NULL),
(26, NULL, 1, 'Vardiya #44 kapatıldı. Fark: -343.69 TL', 'info', '2025-11-12 12:32:32', NULL, NULL),
(27, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #45). Kapanış bakiyesini girin.', 'warning', '2025-11-12 14:33:16', NULL, NULL),
(28, NULL, 1, 'Vardiya #45 kapatıldı. Fark: 377.74 TL', 'info', '2025-11-12 14:34:01', NULL, NULL),
(29, NULL, 1, 'Vardiya #45 kapatıldı. Fark: 377.74 TL', 'info', '2025-11-12 15:33:43', NULL, NULL),
(30, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #46). Kapanış bakiyesini girin.', 'warning', '2025-11-13 10:53:43', NULL, NULL),
(31, NULL, 1, 'Vardiya #46 kapatıldı. Fark: 41.74 TL', 'info', '2025-11-13 10:54:21', NULL, NULL),
(32, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #47). Kapanış bakiyesini girin.', 'warning', '2025-11-13 17:34:25', NULL, NULL),
(33, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #48). Kapanış bakiyesini girin.', 'warning', '2025-11-14 10:35:25', NULL, NULL),
(34, NULL, 1, 'Yeni izin talebi: 2025-11-14 - 2025-11-14', 'info', '2025-11-14 10:52:17', NULL, NULL),
(35, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #49). Kapanış bakiyesini girin.', 'warning', '2025-11-14 15:30:38', NULL, NULL),
(36, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #50). Kapanış bakiyesini girin.', 'warning', '2025-11-14 15:37:54', NULL, NULL),
(37, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #51). Kapanış bakiyesini girin.', 'warning', '2025-11-14 17:36:43', NULL, NULL),
(38, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #52). Kapanış bakiyesini girin.', 'warning', '2025-11-14 17:38:49', NULL, NULL),
(39, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #53). Kapanış bakiyesini girin.', 'warning', '2025-11-14 19:36:14', NULL, NULL),
(40, NULL, 1, 'Vardiya #53 kapatıldı. Fark: 1,192.18 TL', 'info', '2025-11-14 19:38:04', NULL, NULL),
(41, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #54). Kapanış bakiyesini girin.', 'warning', '2025-11-15 11:40:51', NULL, NULL),
(42, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #57). Kapanış bakiyesini girin.', 'warning', '2025-11-17 01:02:52', NULL, NULL),
(43, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #58). Kapanış bakiyesini girin.', 'warning', '2025-11-18 00:15:38', NULL, NULL),
(44, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #59)', '', '2025-11-18 18:19:40', '0', 59),
(45, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #59)', '', '2025-11-18 18:19:46', '0', 59),
(46, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #60)', '', '2025-11-18 18:20:06', '0', 60),
(47, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #60)', '', '2025-11-18 18:22:04', '0', 60),
(48, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #60). Kapanış bakiyesini girin.', 'warning', '2025-11-19 19:37:12', NULL, NULL),
(49, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #61)', '', '2025-11-19 20:16:54', '0', 61),
(50, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #63). Kapanış bakiyesini girin.', 'warning', '2025-11-20 12:08:09', NULL, NULL),
(51, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #62)', '', '2025-11-20 12:08:13', '0', 62),
(52, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #64). Kapanış bakiyesini girin.', 'warning', '2025-11-20 12:20:32', NULL, NULL),
(53, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #65). Kapanış bakiyesini girin.', 'warning', '2025-11-20 12:21:06', NULL, NULL),
(54, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #65). Kapanış bakiyesini girin.', 'warning', '2025-11-20 12:27:55', NULL, NULL),
(55, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #66). Kapanış bakiyesini girin.', 'warning', '2025-11-20 18:23:40', NULL, NULL),
(56, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #67). Kapanış bakiyesini girin.', 'warning', '2025-11-23 00:30:15', NULL, NULL),
(57, NULL, 1, 'Yeni izin talebi: 2025-11-26 - 2025-11-27', 'info', '2025-11-23 00:30:35', NULL, NULL),
(58, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #68). Kapanış bakiyesini girin.', 'warning', '2025-11-23 00:32:14', NULL, NULL),
(59, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #69). Kapanış bakiyesini girin.', 'warning', '2025-11-29 11:37:54', NULL, NULL),
(60, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #70). Kapanış bakiyesini girin.', 'warning', '2025-12-11 00:50:05', NULL, NULL),
(61, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #71). Kapanış bakiyesini girin.', 'warning', '2025-12-13 12:21:05', NULL, NULL),
(62, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #72). Kapanış bakiyesini girin.', 'warning', '2025-12-16 23:43:39', NULL, NULL),
(63, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #73). Kapanış bakiyesini girin.', 'warning', '2025-12-26 13:48:03', NULL, NULL),
(64, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #74). Kapanış bakiyesini girin.', 'warning', '2026-01-06 01:23:07', NULL, NULL),
(65, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #75). Kapanış bakiyesini girin.', 'warning', '2026-01-09 10:37:04', NULL, NULL),
(66, NULL, 1, 'Yeni izin talebi: 2026-01-17 - 2026-01-17', 'info', '2026-01-09 11:06:18', NULL, NULL),
(67, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #76)', '', '2026-01-09 21:21:50', '0', 76),
(68, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #76)', '', '2026-01-09 21:21:57', '0', 76),
(69, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #76)', '', '2026-01-09 21:22:07', '0', 76),
(70, NULL, 1, 'Kasiyer vardiyasını kapattı (Vardiya #76). Kapanış bakiyesini girin.', 'warning', '2026-01-09 21:22:22', NULL, NULL),
(71, NULL, 1, 'Yeni izin talebi: 2026-01-29 - 2026-01-29', 'info', '2026-01-12 19:14:12', NULL, NULL),
(72, NULL, 1, 'Kasiyer vardiyasını kapatmak istiyor (Vardiya #77)', '', '2026-01-12 19:15:16', '0', 77);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `open_accounts`
--

DROP TABLE IF EXISTS `open_accounts`;
CREATE TABLE IF NOT EXISTS `open_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `sale_id` int DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `amount_due` decimal(10,2) DEFAULT NULL,
  `status` enum('open','paid') DEFAULT 'open',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `note` text,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `sale_id` (`sale_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `open_account_payments`
--

DROP TABLE IF EXISTS `open_account_payments`;
CREATE TABLE IF NOT EXISTS `open_account_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `open_account_id` int DEFAULT NULL,
  `shift_id` int DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `payment_type` enum('cash','credit_card','bank_transfer') DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `open_account_id` (`open_account_id`),
  KEY `shift_id` (`shift_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `payments`
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `payment_method_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `commission` decimal(10,2) DEFAULT '0.00',
  `installment_count` int DEFAULT '1',
  `user_rate` decimal(10,4) DEFAULT '1.0000',
  `payment_date` datetime NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=98 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `payments`
--

INSERT INTO `payments` (`id`, `sale_id`, `payment_method_id`, `amount`, `currency`, `commission`, `installment_count`, `user_rate`, `payment_date`, `branch_id`) VALUES
(1, 14, 1, 127.20, 'TL', 0.00, 1, 1.0000, '2025-10-23 10:53:59', 1),
(2, 13, 1, 10.60, 'TL', 0.00, 1, 1.0000, '2025-10-23 10:54:21', 1),
(3, 15, 1, 63.60, 'TL', 0.00, 1, 1.0000, '2025-10-23 10:57:23', 1),
(4, 16, 1, 646.60, 'TL', 0.00, 1, 1.0000, '2025-10-24 21:04:20', 1),
(5, 17, 1, 254.40, 'TL', 0.00, 1, 1.0000, '2025-10-24 21:27:41', 1),
(6, 18, 2, 150.00, 'TL', 2.25, 1, 1.0000, '2025-10-24 21:29:03', 1),
(7, 18, 1, 35.50, 'TL', 0.00, 1, 1.0000, '2025-10-24 21:29:03', 1),
(8, 19, 1, 12.72, 'TL', 0.00, 1, 1.0000, '2025-10-25 11:28:27', 1),
(9, 20, 1, 180.20, 'TL', 0.00, 1, 1.0000, '2025-10-25 22:34:25', 1),
(10, 21, 1, 90.80, 'TL', 0.00, 1, 1.0000, '2025-10-26 01:45:49', 1),
(11, 21, 2, 100.00, 'TL', 1.50, 1, 1.0000, '2025-10-26 01:45:49', 1),
(12, 24, 1, 65.72, 'TL', 0.00, 1, 1.0000, '2025-10-26 12:20:02', 1),
(13, 25, 1, 12.72, 'TL', 0.00, 1, 1.0000, '2025-10-27 13:43:15', 1),
(14, 26, 1, 12.72, 'TL', 0.00, 1, 1.0000, '2025-10-27 14:39:20', 1),
(15, 27, 2, 53.00, 'TL', 0.79, 1, 1.0000, '2025-10-27 14:40:33', 1),
(16, 28, 2, 137.80, 'TL', 2.07, 1, 1.0000, '2025-10-27 15:03:11', 1),
(17, 29, 1, 53.00, 'TL', 0.00, 1, 1.0000, '2025-10-30 12:33:59', 1),
(18, 30, 1, 85.50, 'TL', 0.00, 1, 1.0000, '2025-10-31 00:26:01', 1),
(19, 30, 2, 10.00, 'TL', 0.15, 1, 1.0000, '2025-10-31 00:26:01', 1),
(20, 30, 4, 1.50, 'USD', 1.26, 1, 41.0000, '2025-10-31 00:26:01', 1),
(21, 30, 5, 0.55, 'EUR', 0.54, 1, 48.0000, '2025-10-31 00:26:01', 1),
(22, 34, 1, 10.60, 'TL', 0.00, 1, 1.0000, '2025-10-31 13:10:59', 1),
(23, 35, 2, 12.72, 'TL', 0.19, 1, 1.0000, '2025-11-01 00:32:56', 1),
(24, 36, 4, 1.00, 'USD', 0.84, 1, 41.0000, '2025-11-01 00:33:54', 1),
(25, 36, 1, 26.92, 'TL', 0.00, 1, 1.0000, '2025-11-01 00:33:54', 1),
(26, 37, 1, 243.80, 'TL', 0.00, 1, 1.0000, '2025-11-01 00:35:17', 1),
(27, 40, 1, 10.60, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(28, 39, 1, 68.90, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(29, 41, 1, 190.80, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(30, 42, 1, 68.90, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(31, 44, 2, 68.90, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(32, 45, 4, 0.37, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(33, 45, 1, 0.30, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(34, 46, 5, 7.50, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(35, 47, 3, 169.60, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(36, 48, 1, 24.03, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(37, 48, 2, 50.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(38, 48, 3, 50.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(39, 48, 4, 4.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(40, 48, 5, 4.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(41, 50, 1, 70.94, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(42, 50, 2, 50.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(43, 50, 3, 50.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(44, 50, 4, 5.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(45, 50, 5, 5.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(46, 52, 1, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(47, 52, 2, 120.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(48, 52, 3, 178.84, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(49, 52, 4, 5.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(50, 52, 5, 5.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(51, 53, 1, 231.97, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(52, 53, 2, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(53, 53, 3, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(54, 53, 4, 10.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(55, 53, 5, 10.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(56, 54, 1, 463.84, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(57, 54, 2, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(58, 54, 3, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(59, 54, 4, 5.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(60, 54, 5, 5.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(61, 55, 1, 198.84, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(62, 55, 2, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(63, 55, 3, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(64, 55, 4, 5.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(65, 55, 5, 5.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(66, 56, 1, 149.03, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(67, 56, 2, 101.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(68, 56, 3, 250.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(69, 56, 4, 10.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(70, 56, 5, 10.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(71, 57, 1, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(72, 57, 2, 100.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(73, 57, 3, 84.13, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(74, 57, 4, 2.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(75, 57, 5, 2.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(76, 58, 1, 78.68, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(77, 58, 1, 60.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(78, 58, 1, 60.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(79, 58, 1, 60.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(80, 58, 2, 60.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(81, 58, 4, 3.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(82, 60, 1, 227.90, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(83, 61, 1, 53.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(84, 62, 1, 116.60, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(85, 64, 1, 15.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(86, 64, 4, 1.00, 'USD', 0.00, 1, 42.0000, '0000-00-00 00:00:00', 1),
(87, 64, 5, 1.00, 'EUR', 0.00, 1, 48.0000, '0000-00-00 00:00:00', 1),
(88, 64, 2, 2.00, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(89, 64, 3, 3.27, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(90, 65, 1, 111.30, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(91, 68, 1, 148.40, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(92, 67, 1, 254.40, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(93, 69, 1, 21.20, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(94, 70, 1, 31.80, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(95, 72, 1, 21.20, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(96, 71, 1, 15.90, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1),
(97, 73, 1, 21.20, 'TL', 0.00, 1, 1.0000, '0000-00-00 00:00:00', 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `commission_rate` decimal(5,2) DEFAULT '0.00',
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `name`, `currency`, `commission_rate`, `branch_id`) VALUES
(1, 'Nakit', 'TL', 0.00, 1),
(2, 'Kredi Kartı', 'TL', 1.50, 1),
(3, 'Havale', 'TL', 0.50, 1),
(4, 'Nakit (USD)', 'USD', 2.00, 1),
(5, 'Nakit (EUR)', 'EUR', 2.00, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel`
--

DROP TABLE IF EXISTS `personnel`;
CREATE TABLE IF NOT EXISTS `personnel` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `role_id` int DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `personnel_type` enum('cashier','kitchen','admin','shift_supervisor') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_logged_in` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `hire_date` date DEFAULT NULL COMMENT 'İşe başlangıç tarihi',
  `termination_date` date DEFAULT NULL COMMENT 'İşten ayrılış tarihi',
  `employment_status` enum('active','terminated','on_leave','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `department` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Departman / Görev (Örn: Kasa, Mutfak, Temizlik)',
  `blood_type` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Kan grubu (A+, 0- vb.)',
  `emergency_contact` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Acil durumda aranacak kişi',
  `emergency_phone` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Acil durum telefon',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Ek notlar (kronik hastalık, alerji vs.)',
  `base_salary` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Aylık brüt maaş (saatlik değilse)',
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Saatlik ücret (saatlik çalışıyorsa)',
  `is_hourly` tinyint(1) NOT NULL,
  `phone` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cep Telefonu',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'E-Posta Adresi',
  `address` text COLLATE utf8mb4_unicode_ci COMMENT 'Tam Adres',
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Çanakkale' COMMENT 'Şehir',
  `district` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'İlçe',
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `role_id` (`role_id`),
  KEY `idx_personnel_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `personnel`
--

INSERT INTO `personnel` (`id`, `name`, `branch_id`, `role_id`, `username`, `password`, `personnel_type`, `is_logged_in`, `created_at`, `hire_date`, `termination_date`, `employment_status`, `department`, `blood_type`, `emergency_contact`, `emergency_phone`, `notes`, `base_salary`, `hourly_rate`, `is_hourly`, `phone`, `email`, `address`, `city`, `district`) VALUES
(1, 'Admin Kullanıcı', 1, 1, 'kasa', '$2y$10$PvmJNnQjgX/HEdQy2GmHTeRmCDOuafMrgBdsZ.dPqg4Tas/oQxPs2', 'cashier', 1, NULL, '2025-11-29', NULL, 'active', 'Kasa', NULL, NULL, NULL, NULL, 0.00, 0.00, 0, NULL, NULL, NULL, 'Çanakkale', NULL),
(3, 'Mutfak', 1, 1, 'usta', '$2y$10$PvmJNnQjgX/HEdQy2GmHTeRmCDOuafMrgBdsZ.dPqg4Tas/oQxPs2', 'kitchen', 0, NULL, '2025-11-29', NULL, 'active', 'Mutfak', NULL, NULL, NULL, NULL, 0.00, 0.00, 0, '+905559876543', 'usta@alcitepecafe.com', 'Mutfak Arkası, Alçıtepe Cafe', 'İstanbul', 'Kadıköy'),
(4, 'admin', 1, 1, 'admin', '$2y$10$PvmJNnQjgX/HEdQy2GmHTeRmCDOuafMrgBdsZ.dPqg4Tas/oQxPs2', 'admin', 1, NULL, '2025-11-29', NULL, 'active', 'Yönetim', NULL, NULL, NULL, NULL, 0.00, 0.00, 0, '+905551234567', 'admin@alcitepecafe.com', 'Alçıtepe Mah. Cafe Sok. No:15', 'İstanbul', 'Kadıköy'),
(5, 'svisor', 1, 1, 'svisor', '$2y$10$PvmJNnQjgX/HEdQy2GmHTeRmCDOuafMrgBdsZ.dPqg4Tas/oQxPs2', 'shift_supervisor', 0, NULL, '2025-11-29', NULL, 'active', 'Vardiya Sorumlusu', NULL, NULL, NULL, NULL, 0.00, 0.00, 0, NULL, NULL, NULL, 'Çanakkale', NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_advances`
--

DROP TABLE IF EXISTS `personnel_advances`;
CREATE TABLE IF NOT EXISTS `personnel_advances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','paid','rejected') DEFAULT 'pending',
  `requested_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_assets`
--

DROP TABLE IF EXISTS `personnel_assets`;
CREATE TABLE IF NOT EXISTS `personnel_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `returned` tinyint(1) DEFAULT '0',
  `returned_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_documents`
--

DROP TABLE IF EXISTS `personnel_documents`;
CREATE TABLE IF NOT EXISTS `personnel_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `document_type_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_doc_per_person` (`personnel_id`,`document_type_id`),
  KEY `document_type_id` (`document_type_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_leaves`
--

DROP TABLE IF EXISTS `personnel_leaves`;
CREATE TABLE IF NOT EXISTS `personnel_leaves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` int NOT NULL,
  `reason` text,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_points`
--

DROP TABLE IF EXISTS `personnel_points`;
CREATE TABLE IF NOT EXISTS `personnel_points` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int DEFAULT NULL,
  `sale_id` int DEFAULT NULL,
  `points` decimal(10,2) DEFAULT NULL,
  `branch_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`),
  KEY `sale_id` (`sale_id`),
  KEY `idx_personnel_points_date` (`branch_id`,`created_at`)
) ENGINE=MyISAM AUTO_INCREMENT=66 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `personnel_points`
--

INSERT INTO `personnel_points` (`id`, `personnel_id`, `sale_id`, `points`, `branch_id`, `created_at`) VALUES
(1, 1, 16, 18.00, 1, '2025-10-24 16:57:21'),
(2, 1, 17, 17.00, 1, '2025-10-24 21:22:47'),
(3, 1, 18, 17.00, 1, '2025-10-24 21:28:14'),
(4, 1, 19, 1.00, 1, '2025-10-24 21:30:46'),
(5, 1, 21, 18.00, 1, '2025-10-26 01:45:08'),
(6, 1, 22, 6.00, 1, '2025-10-26 02:14:04'),
(7, 1, 23, 6.00, 1, '2025-10-26 10:13:47'),
(8, 1, 24, 6.00, 1, '2025-10-26 12:19:34'),
(9, 1, 25, 1.00, 1, '2025-10-26 17:52:18'),
(10, 1, 26, 1.00, 1, '2025-10-27 14:39:10'),
(11, 1, 27, 5.00, 1, '2025-10-27 14:40:26'),
(12, 1, 28, 13.00, 1, '2025-10-27 14:41:08'),
(13, 1, 29, 5.00, 1, '2025-10-28 19:16:43'),
(14, 1, 30, 1.00, 1, '2025-10-30 12:34:19'),
(15, 1, 30, 11.00, 1, '2025-10-31 00:24:50'),
(16, 1, 30, 5.00, 1, '2025-10-31 00:25:00'),
(17, 1, 31, 5.00, 1, '2025-10-31 00:27:29'),
(18, 1, 32, 5.00, 1, '2025-10-31 00:27:52'),
(19, 1, 33, 5.00, 1, '2025-10-31 00:28:40'),
(20, 1, 34, 1.00, 1, '2025-10-31 12:08:50'),
(21, 1, 35, 1.00, 1, '2025-11-01 00:32:41'),
(22, 1, 36, 6.00, 1, '2025-11-01 00:33:07'),
(23, 1, 37, 23.00, 1, '2025-11-01 00:35:10'),
(24, 1, 38, 1.00, 1, '2025-11-01 00:35:58'),
(25, 1, 39, 6.00, 1, '2025-11-05 17:53:26'),
(26, 4, 40, 1.00, 1, '2025-11-05 18:11:38'),
(27, 1, 41, 18.00, 1, '2025-11-11 21:49:05'),
(28, 1, 42, 6.00, 1, '2025-11-12 10:32:14'),
(29, 1, 43, 6.00, 1, '2025-11-12 10:32:42'),
(30, 1, 44, 6.00, 1, '2025-11-12 10:33:35'),
(31, 1, 45, 1.00, 1, '2025-11-12 10:33:58'),
(32, 1, 46, 23.00, 1, '2025-11-12 10:34:38'),
(33, 1, 47, 10.00, 1, '2025-11-12 10:35:18'),
(34, 1, 48, 46.00, 1, '2025-11-12 11:01:14'),
(35, 1, 49, 1.00, 1, '2025-11-12 11:02:01'),
(36, 1, 50, 59.00, 1, '2025-11-12 11:50:20'),
(37, 1, 51, 31.00, 1, '2025-11-12 12:08:38'),
(38, 1, 52, 11.00, 1, '2025-11-12 12:08:49'),
(39, 1, 52, 69.00, 1, '2025-11-12 12:09:19'),
(40, 1, 53, 126.00, 1, '2025-11-12 12:19:13'),
(41, 1, 54, 105.00, 1, '2025-11-12 12:29:37'),
(42, 1, 55, 11.00, 1, '2025-11-12 14:32:41'),
(43, 1, 56, 105.00, 1, '2025-11-13 10:52:52'),
(44, 1, 57, 44.00, 1, '2025-11-13 17:33:47'),
(45, 1, 58, 42.00, 1, '2025-11-14 15:35:54'),
(46, 1, 59, 5.00, 1, '2025-11-14 15:42:14'),
(47, 1, 60, 21.00, 1, '2025-11-14 15:42:35'),
(48, 1, 61, 5.00, 1, '2025-11-14 17:30:00'),
(49, 1, 62, 11.00, 1, '2025-11-14 17:30:46'),
(50, 1, 63, 11.00, 1, '2025-11-14 19:35:07'),
(51, 1, 64, 10.00, 1, '2025-11-23 00:31:23'),
(52, 1, 65, 10.00, 1, '2025-11-29 11:37:47'),
(53, 1, 66, 8.00, 1, '2025-12-09 09:27:53'),
(54, 1, 67, 2.00, 1, '2025-12-09 18:14:52'),
(55, 1, 67, 2.00, 1, '2025-12-09 21:42:12'),
(56, 1, 68, 9.00, 1, '2025-12-09 21:46:16'),
(57, 1, 67, 20.00, 1, '2025-12-11 00:49:45'),
(58, 1, 68, 1.00, 1, '2025-12-13 23:54:34'),
(59, 1, 68, 3.00, 1, '2025-12-16 23:41:40'),
(60, 1, 69, 2.00, 1, '2026-01-04 13:08:39'),
(61, 1, 70, 1.00, 1, '2026-01-04 13:12:02'),
(62, 1, 71, 1.00, 1, '2026-01-04 16:23:33'),
(63, 1, 72, 2.00, 1, '2026-01-05 21:36:28'),
(64, 1, 73, 2.00, 1, '2026-01-06 01:12:35'),
(65, 1, 74, 2.00, 1, '2026-01-08 14:40:28');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_roles`
--

DROP TABLE IF EXISTS `personnel_roles`;
CREATE TABLE IF NOT EXISTS `personnel_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `personnel_roles`
--

INSERT INTO `personnel_roles` (`id`, `name`) VALUES
(1, 'Müdür'),
(2, 'Vardiya Sorumlusu');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_shifts`
--

DROP TABLE IF EXISTS `personnel_shifts`;
CREATE TABLE IF NOT EXISTS `personnel_shifts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL COMMENT 'Örn: 09:00:00',
  `end_time` time NOT NULL COMMENT 'Örn: 18:00:00',
  `shift_type` enum('morning','afternoon','night','off') DEFAULT 'morning' COMMENT 'off = izinli',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shift` (`personnel_id`,`shift_date`),
  KEY `personnel_id` (`personnel_id`),
  KEY `shift_date` (`shift_date`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `personnel_shifts`
--

INSERT INTO `personnel_shifts` (`id`, `personnel_id`, `shift_date`, `start_time`, `end_time`, `shift_type`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, '2025-12-01', '09:00:00', '18:00:00', 'morning', '', 4, '2025-11-30 00:17:41'),
(2, 1, '2025-12-02', '08:00:00', '23:59:00', 'morning', '', 4, '2025-11-30 11:06:13'),
(3, 1, '2025-12-08', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(5, 1, '2025-12-10', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(6, 1, '2025-12-11', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(7, 1, '2025-12-12', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(8, 1, '2025-12-13', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(9, 1, '2025-12-14', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-11-30 12:15:35'),
(10, 1, '2025-12-21', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(11, 1, '2025-12-22', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(12, 1, '2025-12-23', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(13, 1, '2025-12-24', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(14, 1, '2025-12-25', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(15, 1, '2025-12-26', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29'),
(16, 1, '2025-12-27', '09:00:00', '18:00:00', 'morning', NULL, 4, '2025-12-11 00:52:29');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_targets`
--

DROP TABLE IF EXISTS `personnel_targets`;
CREATE TABLE IF NOT EXISTS `personnel_targets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int NOT NULL,
  `target_type` enum('daily_sales','weekly_sales','monthly_sales','yearly_sales','daily_points','monthly_points','product_sales') NOT NULL,
  `product_id` int DEFAULT NULL,
  `target_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `target_points` int DEFAULT '0',
  `period_year` int NOT NULL DEFAULT '2025',
  `period_month` tinyint DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_target` (`personnel_id`,`target_type`,`period_year`,`period_month`,`product_id`),
  KEY `product_id` (`product_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `personnel_targets`
--

INSERT INTO `personnel_targets` (`id`, `personnel_id`, `target_type`, `product_id`, `target_value`, `target_points`, `period_year`, `period_month`, `created_at`, `created_by`) VALUES
(2, 1, 'daily_sales', NULL, 1200.00, 0, 2025, 11, '2025-11-29 10:57:45', 4),
(3, 1, 'weekly_sales', NULL, 8400.00, 0, 2025, 11, '2025-11-29 10:57:58', 4),
(4, 1, 'monthly_sales', NULL, 30000.00, 0, 2025, 12, '2025-12-11 00:53:06', 4);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personnel_tasks`
--

DROP TABLE IF EXISTS `personnel_tasks`;
CREATE TABLE IF NOT EXISTS `personnel_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personnel_id` int DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `assigned_by` int DEFAULT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `personnel_id` (`personnel_id`),
  KEY `assigned_by` (`assigned_by`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `pos_devices`
--

DROP TABLE IF EXISTS `pos_devices`;
CREATE TABLE IF NOT EXISTS `pos_devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `production`
--

DROP TABLE IF EXISTS `production`;
CREATE TABLE IF NOT EXISTS `production` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_production_branch_id` (`branch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category_id` int DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `stock_quantity` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'ad',
  `unit_price` decimal(10,2) DEFAULT NULL,
  `point_value` decimal(10,2) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `description` text,
  `image_url` varchar(255) DEFAULT NULL,
  `requires_features` tinyint(1) DEFAULT '0' COMMENT 'Ürün için zorunlu özellik seçimi gerekli mi?',
  `barcode` varchar(20) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT '20.00' COMMENT '%0, %1, %10, %20 olarak girilecek',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_products_stock` (`branch_id`,`stock_quantity`),
  KEY `category_id` (`category_id`)
) ENGINE=MyISAM AUTO_INCREMENT=54 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `products`
--

INSERT INTO `products` (`id`, `name`, `category_id`, `branch_id`, `stock_quantity`, `unit`, `unit_price`, `point_value`, `cost_price`, `description`, `image_url`, `requires_features`, `barcode`, `tax_rate`, `status`) VALUES
(7, 'Kaşarlı Tost', 1, 1, 38.00, 'ad', 80.00, NULL, NULL, 'Kaşarlı Tost', NULL, 0, '2008127838191', 20.00, 'active'),
(8, 'Kaşarlı & Sucuklu Tost', 1, 1, 36.00, 'ad', 90.00, NULL, NULL, 'Kaşarlı & Sucuklu Tost', NULL, 0, '2008358242088', 20.00, 'active'),
(9, 'Ayran', 2, 1, 27.00, 'ad', 20.00, NULL, NULL, 'Ayran', 'ayran.png', 0, '2006892737053', 20.00, 'active'),
(10, 'Kirte Tost', 1, 1, 40.00, 'ad', 180.00, NULL, NULL, 'Kirte Tost', NULL, 0, '2004989112622', 20.00, 'active'),
(11, 'Ada Tost', 1, 1, 0.00, 'ad', 140.00, NULL, NULL, NULL, NULL, 0, '2005435285068', 20.00, 'active'),
(12, 'Ayvalık Tostu', NULL, 1, 0.00, 'ad', 145.00, NULL, NULL, NULL, NULL, 0, '2009208778399', 20.00, 'active'),
(13, 'Kavurma & Kaşarlı Tost', 1, 1, 0.00, 'ad', 220.00, NULL, NULL, NULL, NULL, 0, '2000804007258', 20.00, 'active'),
(14, 'Mega Karışık Tost', 1, 1, 0.00, 'ad', 140.00, NULL, NULL, NULL, NULL, 0, '2000646376147', 20.00, 'active'),
(15, 'Ultra Karışık Tost', 1, 1, 0.00, 'ad', 110.00, NULL, NULL, NULL, NULL, 0, '2001493137752', 20.00, 'active'),
(16, 'Kahvaltılık Sandwich', 6, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2002254907225', 20.00, 'active'),
(17, 'Pesto Sandwich', 6, 1, 0.00, 'ad', 200.00, NULL, NULL, NULL, NULL, 0, '2006141612056', 20.00, 'active'),
(18, 'Kaşarlı & Jambonlu Sandwich', 6, 1, 0.00, 'ad', 180.00, NULL, NULL, NULL, NULL, 0, '2002902091658', 20.00, 'active'),
(19, 'Su 0.5lt', 2, 1, 440.00, 'ad', 15.00, NULL, NULL, NULL, NULL, 0, '2002402008576', 20.00, 'active'),
(20, 'Premium Su', 2, 1, 0.00, 'ad', 20.00, NULL, NULL, NULL, NULL, 0, '2007840824634', 20.00, 'active'),
(21, 'Sade Maden Suyu', 2, 1, 0.00, 'ad', 20.00, NULL, NULL, NULL, NULL, 0, '2000077799645', 20.00, 'active'),
(22, 'Limonlu Soda', 2, 1, 0.00, 'ad', 30.00, NULL, NULL, NULL, NULL, 0, '2005658392154', 10.00, 'active'),
(23, 'Sade Gazoz', 2, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2008781354785', 20.00, 'active'),
(24, 'Portakallı Gazoz', 2, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2009289420903', 20.00, 'active'),
(25, 'Karpuz & Çilek Soda', 2, 1, 0.00, 'ad', 30.00, NULL, NULL, NULL, NULL, 0, '2004415114268', 20.00, 'active'),
(26, 'Narlı Soda', 2, 1, 0.00, 'ad', 30.00, NULL, NULL, NULL, NULL, 0, '2002061242977', 20.00, 'active'),
(27, 'Elmalı Soda', 2, 1, 0.00, 'ad', 30.00, NULL, NULL, NULL, NULL, 0, '2003827371801', 20.00, 'active'),
(28, 'Kola 330ml', 2, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2003787534636', 20.00, 'active'),
(29, 'İce Tea Şeftali', 2, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2002147017253', 20.00, 'active'),
(30, 'İce Tea Mango', 2, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2008905906272', 20.00, 'active'),
(31, 'İce Tea Limon', NULL, 1, 0.00, 'ad', 50.00, NULL, NULL, NULL, NULL, 0, '2001199479408', 20.00, 'active'),
(32, 'Magnolya', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2004385687250', 20.00, 'active'),
(33, 'Bardak Waffle', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2008423970007', 20.00, 'active'),
(34, 'Limonlu Cheescake', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2007814548184', 20.00, 'active'),
(35, 'Frambuazlı Cheescake', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2008656228715', 20.00, 'active'),
(36, 'Çikolatalı Cheescake', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2004583732677', 20.00, 'active'),
(37, 'Portakallı Cheescake', 3, 1, 0.00, 'ad', 150.00, NULL, NULL, NULL, NULL, 0, '2002057210027', 20.00, 'active'),
(38, 'Dubai Cup', 3, 1, 0.00, 'ad', 275.00, NULL, NULL, NULL, NULL, 0, '2008552044624', 20.00, 'active'),
(39, 'Espresso', 7, 1, 0.00, 'ad', 80.00, NULL, NULL, NULL, NULL, 0, '2006331710272', 20.00, 'active'),
(40, 'Double Espresso', 7, 1, 0.00, 'ad', 110.00, NULL, NULL, NULL, NULL, 0, '2006398573490', 20.00, 'active'),
(41, 'Americano', 7, 1, 0.00, 'ad', 110.00, NULL, NULL, NULL, NULL, 0, '2005937263007', 20.00, 'active'),
(42, 'Filtre Kahve', 7, 1, 0.00, 'ad', 110.00, NULL, NULL, NULL, NULL, 0, '2009715014201', 20.00, 'active'),
(43, 'Latte', 7, 1, 0.00, 'ad', 125.00, NULL, NULL, NULL, NULL, 0, '2007410529129', 20.00, 'active'),
(44, 'Cappuccino', 7, 1, 0.00, 'ad', 125.00, NULL, NULL, NULL, NULL, 0, '2009556578849', 20.00, 'active'),
(45, 'Mocha', 7, 1, 0.00, 'ad', 130.00, NULL, NULL, NULL, NULL, 0, '2005834066701', 20.00, 'active'),
(46, 'White C. Mocha', 7, 1, 0.00, 'ad', 130.00, NULL, NULL, NULL, NULL, 0, '2003854394057', 20.00, 'active'),
(47, 'Coconut Latte', 7, 1, 0.00, 'ad', 140.00, NULL, NULL, NULL, NULL, 0, '2002214296130', 20.00, 'active'),
(48, 'Caramel Latte', 7, 1, 0.00, 'ad', 140.00, NULL, NULL, NULL, NULL, 0, '2001834992347', 20.00, 'active'),
(49, 'Hazelnut Latte', 7, 1, 0.00, 'ad', 140.00, NULL, NULL, NULL, NULL, 0, '2008475590079', 20.00, 'active'),
(50, 'İce Americano', 8, 1, 0.00, 'ad', 110.00, NULL, NULL, NULL, NULL, 0, '2001915524627', 20.00, 'active'),
(51, 'İce Latte', 8, 1, 0.00, 'ad', 130.00, NULL, NULL, NULL, NULL, 0, '2005866453050', 20.00, 'active'),
(52, 'İce Mocha', 8, 1, 0.00, 'ad', 130.00, NULL, NULL, NULL, NULL, 0, '2003648668333', 20.00, 'active'),
(53, 'İce White C. Mocha', 8, 1, 0.00, 'ad', 130.00, NULL, NULL, NULL, NULL, 0, '2003846344961', 20.00, 'active');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `point_multiplier` decimal(5,2) DEFAULT '1.00',
  `icon` varchar(50) DEFAULT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_product_categories_branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`, `point_multiplier`, `icon`, `branch_id`) VALUES
(1, 'Yemek', 1.00, 'fa-utensils', 1),
(2, 'İçecek', 1.00, 'fa-hamburger', 1),
(3, 'Tatlılar', 1.00, 'fa-dessert', 1),
(4, 'Milkshakeler', 1.00, 'fa-drink', 1),
(6, 'Sandwichler', 1.00, 'fa-toast', 1),
(7, 'Sıcak Kahveler', 1.00, 'fa-coffee', 1),
(8, 'Soğuk Kahveler', 1.00, 'fa-coffee', 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_extras`
--

DROP TABLE IF EXISTS `product_extras`;
CREATE TABLE IF NOT EXISTS `product_extras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `quantity` decimal(8,3) DEFAULT '1.000',
  `unit` varchar(20) DEFAULT 'adet',
  `is_required` tinyint(1) DEFAULT '0',
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `product_extras`
--

INSERT INTO `product_extras` (`id`, `product_id`, `name`, `price`, `is_active`, `quantity`, `unit`, `is_required`, `branch_id`) VALUES
(11, 10, 'Ketçap', 0.00, 1, 0.010, '0', 0, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_features`
--

DROP TABLE IF EXISTS `product_features`;
CREATE TABLE IF NOT EXISTS `product_features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `group_id` int DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `additional_price` decimal(10,2) DEFAULT '0.00',
  `is_mandatory` tinyint(1) DEFAULT '0',
  `stock_quantity` int DEFAULT '0',
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_group` (`group_id`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `product_features`
--

INSERT INTO `product_features` (`id`, `product_id`, `group_id`, `name`, `additional_price`, `is_mandatory`, `stock_quantity`, `branch_id`) VALUES
(7, 10, 1, 'Sossuz', 0.00, 0, 0, 1),
(8, 10, 1, 'Ketçaplı', 0.00, 0, 0, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_feature_assignments`
--

DROP TABLE IF EXISTS `product_feature_assignments`;
CREATE TABLE IF NOT EXISTS `product_feature_assignments` (
  `product_id` int NOT NULL,
  `feature_id` int NOT NULL,
  PRIMARY KEY (`product_id`,`feature_id`),
  KEY `feature_id` (`feature_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_feature_groups`
--

DROP TABLE IF EXISTS `product_feature_groups`;
CREATE TABLE IF NOT EXISTS `product_feature_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT '0',
  `branch_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group` (`product_id`,`name`,`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_promotions`
--

DROP TABLE IF EXISTS `product_promotions`;
CREATE TABLE IF NOT EXISTS `product_promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `promotion_id` int NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_promo` (`product_id`,`promotion_id`,`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_sizes`
--

DROP TABLE IF EXISTS `product_sizes`;
CREATE TABLE IF NOT EXISTS `product_sizes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `stock_quantity` int DEFAULT '0',
  `branch_id` int NOT NULL,
  `additional_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=18 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `product_sizes`
--

INSERT INTO `product_sizes` (`id`, `product_id`, `name`, `stock_quantity`, `branch_id`, `additional_price`) VALUES
(17, 51, 'Büyük', 0, 1, 0.00),
(15, 7, 'Tam', 1, 1, 80.00),
(16, 51, 'Orta', 0, 1, 0.00),
(14, 7, '3 Çeyrek', 0, 1, 30.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `promotions`
--

DROP TABLE IF EXISTS `promotions`;
CREATE TABLE IF NOT EXISTS `promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL,
  `valid_from` datetime NOT NULL,
  `valid_until` datetime NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `recipes`
--

DROP TABLE IF EXISTS `recipes`;
CREATE TABLE IF NOT EXISTS `recipes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `ingredient_id` int DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipes_product_id` (`product_id`),
  KEY `fk_recipes_ingredient` (`ingredient_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `recipe_ingredients`
--

DROP TABLE IF EXISTS `recipe_ingredients`;
CREATE TABLE IF NOT EXISTS `recipe_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=176 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `recipe_ingredients`
--

INSERT INTO `recipe_ingredients` (`id`, `product_id`, `ingredient_id`, `quantity`, `created_at`) VALUES
(2, 7, 6, 0.0400, '2025-12-06 11:03:21'),
(3, 7, 8, 0.5000, '2025-12-06 11:04:13'),
(5, 7, 9, 0.0200, '2025-12-06 11:37:37'),
(6, 7, 11, 0.0100, '2025-12-06 11:37:44'),
(7, 7, 10, 0.0200, '2025-12-06 11:37:49'),
(8, 7, 13, 1.0000, '2025-12-06 12:49:42'),
(9, 7, 14, 1.0000, '2025-12-06 12:49:46'),
(10, 7, 15, 1.0000, '2025-12-06 12:49:50'),
(11, 7, 12, 0.0200, '2025-12-06 12:50:01'),
(12, 7, 16, 0.0400, '2025-12-06 12:53:23'),
(13, 7, 17, 0.0400, '2025-12-06 12:53:35'),
(14, 7, 18, 0.0200, '2025-12-06 12:55:52'),
(15, 8, 6, 0.0400, '2025-12-06 11:03:21'),
(16, 8, 8, 0.5000, '2025-12-06 11:04:13'),
(17, 8, 9, 0.0200, '2025-12-06 11:37:37'),
(18, 8, 11, 0.0100, '2025-12-06 11:37:44'),
(19, 8, 10, 0.0200, '2025-12-06 11:37:49'),
(20, 8, 13, 1.0000, '2025-12-06 12:49:42'),
(21, 8, 14, 1.0000, '2025-12-06 12:49:46'),
(22, 8, 15, 1.0000, '2025-12-06 12:49:50'),
(23, 8, 12, 0.0200, '2025-12-06 12:50:01'),
(24, 8, 16, 0.0400, '2025-12-06 12:53:23'),
(25, 8, 17, 0.0400, '2025-12-06 12:53:35'),
(26, 8, 18, 0.0200, '2025-12-06 12:55:52'),
(28, 10, 6, 0.0400, '2025-12-06 13:01:41'),
(29, 10, 14, 1.0000, '2025-12-06 13:01:51'),
(30, 10, 13, 1.0000, '2025-12-06 13:01:58'),
(31, 10, 15, 1.0000, '2025-12-06 13:02:04'),
(32, 10, 19, 0.0500, '2025-12-06 13:03:03'),
(33, 10, 20, 1.0000, '2025-12-06 21:21:31'),
(34, 10, 21, 0.0500, '2025-12-06 21:25:42'),
(35, 10, 18, 0.0400, '2025-12-06 21:25:59'),
(36, 10, 11, 0.0100, '2025-12-06 21:26:10'),
(37, 10, 10, 0.0100, '2025-12-06 21:26:27'),
(38, 10, 9, 0.0200, '2025-12-06 21:26:35'),
(40, 15, 18, 0.0100, '2025-12-13 14:31:24'),
(41, 15, 7, 0.0300, '2025-12-13 14:31:42'),
(42, 8, 7, 0.0300, '2025-12-13 14:32:23'),
(43, 15, 6, 0.0400, '2025-12-13 14:33:01'),
(44, 15, 9, 0.0200, '2025-12-13 14:33:11'),
(45, 15, 13, 1.0000, '2025-12-13 14:33:50'),
(46, 15, 15, 1.0000, '2025-12-13 14:33:56'),
(47, 15, 14, 1.0000, '2025-12-13 14:34:01'),
(48, 15, 16, 0.0400, '2025-12-13 14:34:11'),
(49, 15, 17, 0.0400, '2025-12-13 14:34:17'),
(50, 15, 10, 0.0100, '2025-12-13 14:34:29'),
(51, 15, 11, 0.0100, '2025-12-13 14:34:39'),
(52, 15, 12, 0.0200, '2025-12-13 14:34:48'),
(53, 15, 8, 0.5000, '2025-12-13 14:35:23'),
(54, 15, 26, 0.0300, '2025-12-13 14:40:00'),
(55, 14, 8, 0.5000, '2025-12-13 14:41:43'),
(56, 14, 7, 0.0300, '2025-12-13 14:42:20'),
(58, 14, 14, 1.0000, '2025-12-13 14:42:47'),
(59, 14, 13, 1.0000, '2025-12-13 14:42:57'),
(60, 14, 15, 1.0000, '2025-12-13 14:43:07'),
(61, 14, 16, 0.0400, '2025-12-13 14:43:14'),
(62, 14, 17, 0.0400, '2025-12-13 14:43:21'),
(63, 14, 18, 0.0400, '2025-12-13 14:43:30'),
(64, 14, 9, 0.0200, '2025-12-13 14:43:50'),
(65, 14, 11, 0.0200, '2025-12-13 14:44:01'),
(66, 14, 10, 0.0200, '2025-12-13 14:44:09'),
(67, 14, 26, 0.0300, '2025-12-13 14:45:15'),
(68, 14, 28, 0.0300, '2025-12-13 14:46:51'),
(69, 13, 13, 1.0000, '2025-12-13 15:43:20'),
(70, 13, 14, 1.0000, '2025-12-13 15:43:27'),
(71, 13, 15, 1.0000, '2025-12-13 15:43:32'),
(72, 13, 8, 0.5000, '2025-12-13 15:43:38'),
(73, 13, 6, 0.0400, '2025-12-13 15:43:46'),
(74, 13, 18, 0.0400, '2025-12-13 15:44:17'),
(75, 13, 16, 0.0400, '2025-12-13 15:44:26'),
(76, 13, 17, 0.0400, '2025-12-13 15:44:31'),
(77, 13, 25, 0.0400, '2025-12-13 15:44:41'),
(78, 13, 10, 0.0200, '2025-12-13 15:44:50'),
(79, 13, 11, 0.0100, '2025-12-13 15:44:56'),
(80, 13, 9, 0.0200, '2025-12-13 15:45:08'),
(81, 11, 24, 1.0000, '2025-12-13 15:45:54'),
(82, 11, 13, 1.0000, '2025-12-13 15:46:00'),
(83, 11, 9, 0.0200, '2025-12-13 15:46:06'),
(84, 11, 10, 0.0100, '2025-12-13 15:46:12'),
(85, 11, 11, 0.0100, '2025-12-13 15:46:18'),
(86, 11, 14, 1.0000, '2025-12-13 15:46:27'),
(87, 11, 15, 1.0000, '2025-12-13 15:46:31'),
(88, 11, 19, 0.0500, '2025-12-13 15:46:46'),
(89, 11, 31, 0.0100, '2025-12-13 15:48:32'),
(90, 11, 30, 0.0200, '2025-12-13 15:48:38'),
(91, 11, 29, 0.0400, '2025-12-13 15:48:44'),
(92, 11, 12, 0.0300, '2025-12-13 15:48:55'),
(93, 13, 12, 0.0300, '2025-12-13 15:49:10'),
(94, 14, 12, 0.0300, '2025-12-13 15:49:21'),
(95, 12, 24, 1.0000, '2025-12-13 15:49:44'),
(96, 12, 6, 0.0400, '2025-12-13 15:49:57'),
(97, 12, 7, 0.0500, '2025-12-13 15:50:06'),
(98, 12, 18, 0.0400, '2025-12-13 15:50:23'),
(99, 12, 29, 0.0400, '2025-12-13 15:50:43'),
(100, 12, 12, 0.0300, '2025-12-13 15:50:53'),
(101, 12, 9, 0.0200, '2025-12-13 15:51:05'),
(102, 12, 10, 0.0200, '2025-12-13 15:51:10'),
(104, 12, 17, 0.0300, '2025-12-13 15:51:21'),
(105, 12, 16, 0.0400, '2025-12-13 15:51:26'),
(106, 12, 27, 0.0500, '2025-12-13 15:51:39'),
(107, 17, 34, 0.0300, '2025-12-13 16:52:23'),
(108, 17, 32, 0.0400, '2025-12-13 16:52:35'),
(109, 17, 33, 1.0000, '2025-12-13 16:52:51'),
(110, 17, 34, 0.0500, '2025-12-13 16:53:00'),
(111, 17, 36, 5.0000, '2025-12-13 16:56:02'),
(112, 17, 35, 0.0200, '2025-12-13 16:56:20'),
(113, 17, 15, 1.0000, '2025-12-13 16:56:31'),
(114, 17, 14, 1.0000, '2025-12-13 16:56:35'),
(115, 17, 13, 1.0000, '2025-12-13 16:56:40'),
(116, 16, 33, 1.0000, '2025-12-13 16:58:20'),
(117, 16, 29, 0.0500, '2025-12-13 16:58:32'),
(118, 16, 36, 5.0000, '2025-12-13 16:59:38'),
(119, 16, 6, 0.0500, '2025-12-13 16:59:43'),
(120, 16, 37, 0.5000, '2025-12-13 16:59:55'),
(121, 16, 38, 0.0300, '2025-12-13 17:00:05'),
(122, 16, 9, 0.0200, '2025-12-13 17:00:15'),
(123, 16, 11, 0.0100, '2025-12-13 17:00:21'),
(124, 16, 26, 0.0400, '2025-12-13 17:00:32'),
(125, 16, 13, 1.0000, '2025-12-13 17:04:31'),
(126, 16, 14, 1.0000, '2025-12-13 17:04:35'),
(127, 16, 15, 1.0000, '2025-12-13 17:04:39'),
(128, 18, 33, 1.0000, '2025-12-13 17:05:18'),
(129, 18, 9, 0.0200, '2025-12-13 17:05:25'),
(130, 18, 10, 0.0100, '2025-12-13 17:05:32'),
(131, 18, 6, 0.0500, '2025-12-13 17:05:38'),
(132, 18, 11, 0.0100, '2025-12-13 17:05:45'),
(133, 18, 13, 1.0000, '2025-12-13 17:05:51'),
(134, 18, 14, 1.0000, '2025-12-13 17:05:56'),
(135, 18, 15, 1.0000, '2025-12-13 17:06:04'),
(136, 18, 39, 0.0500, '2025-12-13 17:07:46'),
(137, 18, 36, 3.0000, '2025-12-13 17:08:00'),
(138, 18, 29, 0.0300, '2025-12-13 17:08:18'),
(139, 39, 40, 0.0070, '2025-12-13 20:20:11'),
(140, 40, 40, 0.0140, '2025-12-13 20:21:56'),
(141, 47, 40, 0.0100, '2025-12-13 20:22:35'),
(142, 47, 41, 0.2000, '2025-12-13 20:22:40'),
(143, 49, 40, 0.0100, '2025-12-13 20:32:22'),
(144, 49, 41, 0.2000, '2025-12-13 20:34:40'),
(145, 49, 45, 3.0000, '2025-12-13 20:34:53'),
(146, 51, 40, 0.0100, '2025-12-13 20:50:32'),
(147, 51, 41, 0.1500, '2025-12-13 20:50:40'),
(148, 41, 40, 0.0140, '2025-12-13 20:51:59'),
(149, 47, 46, 3.0000, '2025-12-13 20:52:12'),
(150, 41, 48, 1.0000, '2025-12-13 20:58:22'),
(151, 48, 48, 1.0000, '2025-12-13 20:58:30'),
(152, 47, 48, 1.0000, '2025-12-13 20:58:40'),
(153, 48, 49, 1.0000, '2025-12-13 21:02:19'),
(154, 47, 49, 1.0000, '2025-12-13 21:02:36'),
(155, 49, 48, 1.0000, '2025-12-13 21:03:00'),
(156, 49, 49, 1.0000, '2025-12-13 21:03:05'),
(157, 43, 48, 1.0000, '2025-12-13 21:05:45'),
(158, 43, 40, 0.0100, '2025-12-13 21:05:54'),
(159, 43, 41, 0.2000, '2025-12-13 21:05:59'),
(160, 43, 49, 1.0000, '2025-12-13 21:06:05'),
(161, 46, 48, 1.0000, '2025-12-13 21:06:31'),
(162, 46, 49, 1.0000, '2025-12-13 21:06:37'),
(163, 46, 41, 0.2000, '2025-12-13 21:06:52'),
(164, 46, 40, 0.0100, '2025-12-13 21:06:58'),
(165, 46, 47, 3.0000, '2025-12-13 21:07:09'),
(166, 46, 50, 1.0000, '2025-12-13 21:08:58'),
(167, 51, 51, 1.0000, '2025-12-14 09:50:09'),
(168, 51, 58, 1.0000, '2025-12-14 09:55:27'),
(169, 51, 59, 1.0000, '2025-12-14 09:56:29'),
(170, 48, 42, 3.0000, '2025-12-14 10:32:46'),
(171, 48, 41, 0.2000, '2025-12-14 10:32:54'),
(172, 48, 40, 0.0100, '2025-12-14 10:33:07'),
(173, 33, 61, 1.0000, '2025-12-14 11:07:19'),
(174, 33, 15, 1.0000, '2025-12-14 11:07:29'),
(175, 33, 14, 1.0000, '2025-12-14 11:07:36');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `recipe_items`
--

DROP TABLE IF EXISTS `recipe_items`;
CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_recipe` (`product_id`,`ingredient_id`,`branch_id`),
  KEY `ingredient_id` (`ingredient_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `returns`
--

DROP TABLE IF EXISTS `returns`;
CREATE TABLE IF NOT EXISTS `returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int DEFAULT NULL,
  `shift_id` int DEFAULT NULL,
  `personnel_id` int DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_type` enum('cash','credit_card','bank_transfer','open_account') DEFAULT NULL,
  `reason` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `shift_id` (`shift_id`),
  KEY `personnel_id` (`personnel_id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_returns_sale_id` (`sale_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sales`
--

DROP TABLE IF EXISTS `sales`;
CREATE TABLE IF NOT EXISTS `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `shift_id` int DEFAULT NULL,
  `order_type` enum('table','takeaway','delivery') DEFAULT 'table',
  `payment_type` enum('cash','credit_card','bank_transfer','open_account') DEFAULT NULL,
  `payment_status` enum('pending','completed') DEFAULT 'pending',
  `return_status` enum('none','partial','full') DEFAULT 'none',
  `return_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) DEFAULT 'open',
  `currency` varchar(3) DEFAULT 'TL',
  `payment_method_id` int DEFAULT NULL,
  `table_id` int DEFAULT NULL,
  `personnel_id` int NOT NULL,
  `sale_date` datetime NOT NULL,
  `customer_count` int NOT NULL DEFAULT '1',
  `customer_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `created_by` (`created_by`),
  KEY `return_id` (`return_id`),
  KEY `idx_sales_shift_id` (`shift_id`),
  KEY `idx_sales_date` (`branch_id`,`sale_date`,`status`)
) ENGINE=MyISAM AUTO_INCREMENT=75 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `sales`
--

INSERT INTO `sales` (`id`, `customer_id`, `total_amount`, `branch_id`, `created_by`, `shift_id`, `order_type`, `payment_type`, `payment_status`, `return_status`, `return_id`, `created_at`, `discount`, `status`, `currency`, `payment_method_id`, `table_id`, `personnel_id`, `sale_date`, `customer_count`, `customer_name`) VALUES
(67, 1, 240.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2025-12-09 12:29:16', 0.00, 'completed', 'TL', NULL, 1, 1, '2025-12-09 12:29:16', 1, NULL),
(68, 1, 140.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2025-12-09 21:46:16', 0.00, 'completed', 'TL', NULL, 2, 1, '2025-12-09 21:46:16', 1, NULL),
(69, 1, 20.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-04 13:08:39', 0.00, 'completed', 'TL', NULL, 1, 1, '2026-01-04 13:08:39', 1, NULL),
(70, 1, 15.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-04 13:11:55', 0.00, 'completed', 'TL', NULL, 2, 1, '2026-01-04 13:11:55', 1, NULL),
(71, 1, 15.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-04 16:23:33', 0.00, 'completed', 'TL', NULL, 1, 1, '2026-01-04 16:23:33', 1, NULL),
(72, 1, 20.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-05 21:36:28', 0.00, 'completed', 'TL', NULL, 2, 1, '2026-01-05 21:36:28', 1, NULL),
(73, 1, 20.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-06 01:12:35', 0.00, 'completed', 'TL', NULL, 1, 1, '2026-01-06 01:12:35', 1, NULL),
(74, 1, 20.00, 1, NULL, NULL, 'table', NULL, 'pending', 'none', NULL, '2026-01-08 14:40:28', 0.00, 'open', 'TL', NULL, 1, 1, '2026-01-08 14:40:28', 1, NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sales_items`
--

DROP TABLE IF EXISTS `sales_items`;
CREATE TABLE IF NOT EXISTS `sales_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `notes` text,
  `extras` json DEFAULT NULL,
  `status` enum('pending','completed','canceled') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`)
) ENGINE=MyISAM AUTO_INCREMENT=159 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `notes`, `extras`, `status`) VALUES
(143, 66, 7, 1, 80.00, '', NULL, 'pending'),
(144, 67, 9, 1, 20.00, '', NULL, 'pending'),
(145, 67, 9, 1, 20.00, '', NULL, 'pending'),
(146, 68, 8, 1, 90.00, '', NULL, 'pending'),
(147, 67, 7, 1, 110.00, '', NULL, 'pending'),
(148, 67, 8, 1, 90.00, '', NULL, 'pending'),
(149, 68, 19, 1, 15.00, '', NULL, 'pending'),
(150, 68, 9, 1, 20.00, '', NULL, 'pending'),
(151, 68, 19, 1, 15.00, '', NULL, 'pending'),
(152, 69, 9, 1, 20.00, '', NULL, 'pending'),
(153, 70, 19, 1, 15.00, '', NULL, 'pending'),
(154, 70, 19, 1, 15.00, '', NULL, 'pending'),
(155, 71, 19, 1, 15.00, '', NULL, 'pending'),
(156, 72, 9, 1, 20.00, '', NULL, 'pending'),
(157, 73, 9, 1, 20.00, '', NULL, 'pending'),
(158, 74, 9, 1, 20.00, '', NULL, 'pending');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sale_item_features`
--

DROP TABLE IF EXISTS `sale_item_features`;
CREATE TABLE IF NOT EXISTS `sale_item_features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_item_id` int NOT NULL,
  `feature_id` int DEFAULT NULL,
  `size_id` int DEFAULT NULL,
  `additional_price` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `sale_item_id` (`sale_item_id`),
  KEY `feature_id` (`feature_id`),
  KEY `size_id` (`size_id`)
) ENGINE=MyISAM AUTO_INCREMENT=88 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `sale_item_features`
--

INSERT INTO `sale_item_features` (`id`, `sale_item_id`, `feature_id`, `size_id`, `additional_price`) VALUES
(87, 147, NULL, 14, 30.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `shifts`
--

DROP TABLE IF EXISTS `shifts`;
CREATE TABLE IF NOT EXISTS `shifts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `personnel_id` int DEFAULT NULL,
  `shift_type` enum('morning','evening') DEFAULT 'morning',
  `opening_balance` decimal(10,2) DEFAULT NULL,
  `closing_balance` decimal(10,2) DEFAULT '0.00',
  `status` enum('open','closed','locked') DEFAULT 'open',
  `opened_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `start_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closing_requested` tinyint DEFAULT '0',
  `request_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `cashier_id` (`personnel_id`)
) ENGINE=MyISAM AUTO_INCREMENT=78 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `shifts`
--

INSERT INTO `shifts` (`id`, `branch_id`, `personnel_id`, `shift_type`, `opening_balance`, `closing_balance`, `status`, `opened_at`, `closed_at`, `end_time`, `start_time`, `closing_requested`, `request_time`) VALUES
(70, 1, 1, 'morning', 1234.00, NULL, 'closed', '2025-12-07 19:12:50', NULL, '2025-12-11 00:50:05', '2025-12-07 19:12:50', 0, NULL),
(69, 1, 1, 'morning', 1232.00, NULL, 'closed', '2025-11-28 15:06:46', NULL, '2025-11-29 11:37:54', '2025-11-28 15:06:46', 0, NULL),
(68, 1, 1, 'morning', 1200.00, NULL, 'closed', '2025-11-23 00:31:00', NULL, '2025-11-23 00:32:14', '2025-11-23 00:31:00', 0, NULL),
(67, 1, 1, 'morning', 1234.00, NULL, 'closed', '2025-11-22 19:27:00', NULL, '2025-11-23 00:30:15', '2025-11-22 19:27:00', 0, NULL),
(66, 1, 1, 'morning', 10000.00, NULL, 'closed', '2025-11-20 12:28:33', NULL, '2025-11-20 18:23:40', '2025-11-20 12:28:33', 1, '2025-11-20 12:28:33'),
(71, 1, 1, 'morning', 1234.00, NULL, 'closed', '2025-12-11 00:54:09', NULL, '2025-12-13 12:21:05', '2025-12-11 00:54:09', 0, NULL),
(72, 1, 1, 'morning', 1200.00, NULL, 'closed', '2025-12-13 23:53:08', NULL, '2025-12-16 23:43:39', '2025-12-13 23:53:08', 0, NULL),
(73, 1, 1, 'morning', 1000.00, NULL, 'closed', '2025-12-16 23:43:47', NULL, '2025-12-26 13:48:03', '2025-12-16 23:43:47', 0, NULL),
(74, 1, 1, 'morning', 1200.00, NULL, 'closed', '2026-01-04 13:06:17', NULL, '2026-01-06 01:23:07', '2026-01-04 13:06:17', 0, NULL),
(75, 1, 1, 'morning', 1.00, NULL, 'closed', '2026-01-08 12:08:51', NULL, '2026-01-09 10:37:04', '2026-01-08 12:08:51', 0, NULL),
(76, 1, 1, 'morning', 1200.00, NULL, 'closed', '2026-01-09 21:06:35', NULL, '2026-01-09 21:22:22', '2026-01-09 21:06:35', 1, '2026-01-09 21:22:07'),
(77, 1, 1, 'morning', 1.00, 0.00, 'open', '2026-01-09 21:22:27', NULL, NULL, '2026-01-09 21:22:27', 1, '2026-01-12 19:15:16');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `shift_close_requests`
--

DROP TABLE IF EXISTS `shift_close_requests`;
CREATE TABLE IF NOT EXISTS `shift_close_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shift_id` int NOT NULL,
  `requested_by` int NOT NULL,
  `requested_at` datetime NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `shift_id` (`shift_id`),
  KEY `requested_by` (`requested_by`),
  KEY `branch_id` (`branch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=latin5;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('in','out','adjustment','order','count') NOT NULL,
  `item_type` enum('product','ingredient') NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `old_stock` decimal(10,3) NOT NULL,
  `new_stock` decimal(10,3) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL,
  `branch_id` int NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_item` (`item_type`,`item_id`),
  KEY `idx_date` (`created_at`),
  KEY `idx_type` (`type`)
) ENGINE=MyISAM AUTO_INCREMENT=64 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `type`, `item_type`, `item_id`, `quantity`, `old_stock`, `new_stock`, `reason`, `created_at`, `created_by`, `branch_id`, `user_id`) VALUES
(63, 'out', 'product', 9, 1.000, 29.000, 28.000, 'Satış ID: 73', '2026-01-06 01:15:38', 1, 1, 0),
(62, 'out', 'product', 19, 1.000, 441.000, 440.000, 'Satış ID: 71', '2026-01-06 01:06:35', 1, 1, 0),
(61, 'out', 'product', 9, 1.000, 31.000, 30.000, 'Satış ID: 72', '2026-01-06 01:06:05', 1, 1, 0),
(60, 'out', 'product', 19, 1.000, 444.000, 443.000, 'Satış ID: 70', '2026-01-04 16:22:20', 1, 1, 0),
(59, 'out', 'product', 19, 1.000, 444.000, 443.000, 'Satış ID: 70', '2026-01-04 16:22:20', 1, 1, 0),
(58, 'out', 'product', 9, 1.000, 33.000, 32.000, 'Satış ID: 69', '2026-01-04 16:22:09', 1, 1, 0),
(57, 'out', 'product', 8, 1.000, 37.000, 36.000, 'Satış ID: 67', '2025-12-16 23:43:35', 1, 1, 0),
(56, 'out', 'product', 7, 1.000, 39.000, 38.000, 'Satış ID: 67', '2025-12-16 23:43:35', 1, 1, 0),
(55, 'out', 'product', 9, 1.000, 36.000, 35.000, 'Satış ID: 67', '2025-12-16 23:43:35', 1, 1, 0),
(54, 'out', 'product', 9, 1.000, 36.000, 35.000, 'Satış ID: 67', '2025-12-16 23:43:35', 1, 1, 0),
(53, 'out', 'product', 19, 1.000, 448.000, 447.000, 'Satış ID: 68', '2025-12-16 23:43:26', 1, 1, 0),
(52, 'out', 'product', 9, 1.000, 37.000, 36.000, 'Satış ID: 68', '2025-12-16 23:43:26', 1, 1, 0),
(51, 'out', 'product', 19, 1.000, 448.000, 447.000, 'Satış ID: 68', '2025-12-16 23:43:26', 1, 1, 0),
(50, 'out', 'product', 8, 1.000, 38.000, 37.000, 'Satış ID: 68', '2025-12-16 23:43:26', 1, 1, 0),
(49, 'out', 'product', 2, 1.000, 100.000, 99.000, 'Satış ID: 65', '2025-11-29 11:37:53', 1, 1, 0),
(48, 'order', 'product', 2, 40.000, 101.000, 101.000, 'Ana merkeze sipariş', '2025-11-27 22:23:48', 0, 1, 4),
(47, 'adjustment', 'product', 3, 5.000, 10.000, 15.000, 'Raf düzeltme - Espresso', '2025-11-27 20:37:12', 1, 1, 1),
(46, 'count', 'ingredient', 5, 1.200, 25.000, 26.200, 'Stok Sayımı - Un farkı', '2025-11-27 20:37:12', 1, 1, 1),
(45, 'out', 'product', 2, -3.000, 120.000, 117.000, 'Satış - Kola', '2025-11-27 20:37:12', 1, 1, 1),
(44, 'in', 'ingredient', 5, 10.000, 15.000, 25.000, 'Malzeme alımı - 10 kg un', '2025-11-27 20:37:12', 1, 1, 1),
(43, 'count', 'product', 1, -2.500, 50.000, 47.500, 'Stok Sayımı - Hamburger', '2025-11-27 20:37:12', 1, 1, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `tables`
--

DROP TABLE IF EXISTS `tables`;
CREATE TABLE IF NOT EXISTS `tables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `number` varchar(50) NOT NULL,
  `status` enum('empty','occupied') DEFAULT 'empty',
  `branch_id` int NOT NULL,
  `capacity` int DEFAULT '6',
  `reserved_for` varchar(100) DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `department_id` (`department_id`)
) ENGINE=MyISAM AUTO_INCREMENT=34 DEFAULT CHARSET=latin5;

--
-- Tablo döküm verisi `tables`
--

INSERT INTO `tables` (`id`, `number`, `status`, `branch_id`, `capacity`, `reserved_for`, `department_id`) VALUES
(1, 'Masa 01', 'occupied', 1, 6, NULL, 1),
(2, 'Masa 02', '', 1, 6, NULL, 2),
(8, 'Masa 01', 'empty', 1, 4, NULL, 7),
(9, 'Masa 02', 'empty', 1, 4, NULL, 7),
(10, 'Masa 03', 'empty', 1, 4, NULL, 7),
(11, 'Masa 04', 'empty', 1, 4, NULL, 7),
(12, 'Masa 05', 'empty', 1, 4, NULL, 7),
(13, 'Masa 06', 'empty', 1, 4, NULL, 7),
(14, 'Masa 07', 'empty', 1, 4, NULL, 7),
(15, 'Masa 08', 'empty', 1, 4, NULL, 7),
(16, 'Masa 09', 'empty', 1, 4, NULL, 7),
(17, 'Masa 10', 'empty', 1, 4, NULL, 7),
(18, 'Masa 11', 'empty', 1, 4, NULL, 7),
(19, 'Masa 12', 'empty', 1, 4, NULL, 7),
(20, 'Masa 13', 'empty', 1, 4, NULL, 7),
(21, 'Masa 14', 'empty', 1, 4, NULL, 7),
(22, 'Masa 01', '', 1, 4, NULL, 8),
(23, 'Masa 02', 'empty', 1, 4, NULL, 8),
(24, 'Masa 03', 'empty', 1, 4, NULL, 8),
(25, 'Masa 04', 'empty', 1, 4, NULL, 8),
(26, 'Masa 05', 'empty', 1, 4, NULL, 8),
(27, 'Masa 06', 'empty', 1, 4, NULL, 8),
(28, 'Masa 07', 'empty', 1, 4, NULL, 8),
(29, 'Masa 01', 'empty', 1, 4, NULL, 9),
(30, 'Masa 02', 'empty', 1, 4, NULL, 9),
(31, 'Masa 03', 'occupied', 1, 4, NULL, 9),
(32, 'Masa 04', 'empty', 1, 4, NULL, 9),
(33, 'Masa 05', 'empty', 1, 4, NULL, 9);

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `asset_assignments`
--
ALTER TABLE `asset_assignments`
  ADD CONSTRAINT `asset_assignments_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `asset_assignments_ibfk_2` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `branch_order_items`
--
ALTER TABLE `branch_order_items`
  ADD CONSTRAINT `branch_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `branch_orders` (`id`) ON DELETE CASCADE;
--
-- Veritabanı: `main_db`
--
CREATE DATABASE IF NOT EXISTS `main_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `main_db`;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `campaigns`
--

DROP TABLE IF EXISTS `campaigns`;
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('percent_discount','fixed_discount','buy_get','spend_get') COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(10,2) NOT NULL COMMENT 'Yüzde veya TL',
  `min_spend` decimal(10,2) DEFAULT NULL,
  `free_product_id` int DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `card_transactions`
--

DROP TABLE IF EXISTS `card_transactions`;
CREATE TABLE IF NOT EXISTS `card_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `card_id` int NOT NULL,
  `type` enum('load','spend') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `balance_after` decimal(10,2) NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `card_id` (`card_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `card_types`
--

DROP TABLE IF EXISTS `card_types`;
CREATE TABLE IF NOT EXISTS `card_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color_from` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#667eea',
  `color_to` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#764ba2',
  `max_balance` decimal(12,2) DEFAULT '10000.00',
  `benefits` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `card_types`
--

INSERT INTO `card_types` (`id`, `name`, `slug`, `color_from`, `color_to`, `max_balance`, `benefits`, `is_active`, `created_at`) VALUES
(1, 'Classic', 'classic', '#2c3e50', '#34495e', 5000.00, 'Standart kart', 1, '2025-12-01 10:34:59'),
(2, 'Student', 'student', '#e74c3c', '#c0392b', 2000.00, '%10 eğitim indirimi', 1, '2025-12-01 10:34:59'),
(3, 'Premium', 'premium', '#f39c12', '#e67e22', 25000.00, '%15% indirim + öncelikli servis', 1, '2025-12-01 10:34:59'),
(4, 'Business', 'business', '#1abc9c', '#16a085', 50000.00, 'Şirket faturası + yüksek limit', 1, '2025-12-01 10:34:59');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `companies`
--

DROP TABLE IF EXISTS `companies`;
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `notes` text,
  `total_spent` decimal(12,2) DEFAULT '0.00',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Tablo döküm verisi `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `email`, `birth_date`, `notes`, `total_spent`, `created_at`, `updated_at`) VALUES
(1, 'eralp era telemen', '05378502174', 'eralp.e@hotmail.com', '1995-10-22', NULL, 300.00, '2025-11-30 17:01:10', '2025-12-03 18:16:55'),
(2, 'Ahmet Yılmaz', '5321234567', 'ahmet@example.com', '1992-05-15', NULL, 12450.00, '2025-11-30 17:20:07', '2025-11-30 17:20:07'),
(3, 'Zeynep Kaya', '5339876543', 'zeynep.k@gmail.com', '1995-11-22', NULL, 8750.50, '2025-11-30 17:20:07', '2025-11-30 17:20:07'),
(4, 'Mehmet Demir', '5355554433', 'mehmet.demir@gmail.com', '1988-03-10', NULL, 19230.00, '2025-11-30 17:20:07', '2025-11-30 17:21:47'),
(5, 'Elif Çelik', '5421112233', 'elif.celik@hotmail.com', '2000-08-30', NULL, 5420.75, '2025-11-30 17:20:07', '2025-11-30 17:20:07'),
(6, 'Caner Öztürk', '5559998877', 'caner.ozturk@gmail.com', '1990-12-05', NULL, 9800.00, '2025-11-30 17:20:07', '2025-11-30 17:21:40');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_campaigns`
--

DROP TABLE IF EXISTS `customer_campaigns`;
CREATE TABLE IF NOT EXISTS `customer_campaigns` (
  `customer_id` int NOT NULL,
  `campaign_id` int NOT NULL,
  PRIMARY KEY (`customer_id`,`campaign_id`),
  KEY `campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_cards`
--

DROP TABLE IF EXISTS `customer_cards`;
CREATE TABLE IF NOT EXISTS `customer_cards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `card_number` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiry_month` tinyint UNSIGNED NOT NULL DEFAULT '12',
  `expiry_year` smallint UNSIGNED NOT NULL DEFAULT '2030',
  `cvv` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '000',
  `uid` char(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NFC kartın gerçek UID (ör: 04A1B2C3D4E5F6)',
  `balance` decimal(12,2) DEFAULT '0.00',
  `status` enum('active','passive','lost') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `issued_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_physical` tinyint(1) DEFAULT '0' COMMENT '1 = Fiziksel kart verildi',
  `card_type_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card` (`card_number`),
  KEY `customer_id` (`customer_id`),
  KEY `idx_expiry` (`expiry_year`,`expiry_month`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `customer_cards`
--

INSERT INTO `customer_cards` (`id`, `customer_id`, `card_number`, `expiry_month`, `expiry_year`, `cvv`, `uid`, `balance`, `status`, `issued_at`, `is_physical`, `card_type_id`) VALUES
(13, 1, '5110035701509451', 12, 2030, '926', '04A1B2C3D4E5F6', 100.00, 'active', '2025-12-03 18:15:52', 1, 1),
(14, 1, '1337717290242250', 12, 2030, '384', '04A1B2C3D4E5F1', 15000.00, 'active', '2025-12-03 18:16:20', 1, 3),
(15, 1, '8668725302170818', 12, 2030, '75', '04A1B2C3D4E5F7', 400.00, 'active', '2025-12-03 18:16:39', 1, 4);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_promotions`
--

DROP TABLE IF EXISTS `customer_promotions`;
CREATE TABLE IF NOT EXISTS `customer_promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `points` int DEFAULT '0',
  `free_coffees` int DEFAULT '0',
  `last_spend_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `customer_promotions`
--

INSERT INTO `customer_promotions` (`id`, `customer_id`, `points`, `free_coffees`, `last_spend_date`) VALUES
(2, 1, 1245, 2, NULL),
(3, 2, 875, 1, NULL),
(4, 3, 1923, 4, NULL),
(5, 4, 542, 0, NULL),
(6, 5, 980, 1, NULL);

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `customer_campaigns`
--
ALTER TABLE `customer_campaigns`
  ADD CONSTRAINT `customer_campaigns_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_campaigns_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `customer_cards`
--
ALTER TABLE `customer_cards`
  ADD CONSTRAINT `customer_cards_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `customer_promotions`
--
ALTER TABLE `customer_promotions`
  ADD CONSTRAINT `customer_promotions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
