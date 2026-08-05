Panduan Setup Slitting System 2.0
Dokumentasi Lengkap Setup Persekitaran Pembangunan (Laragon & MySQL) di Laptop Baharu

1. Senarai Perisian & Tool Perlu Di-download
Sebelum memulakan proses setup, pastikan perisian berikut dimuat turun dan dipasang di laptop anda:
•	Laragon (Full Edition): Menyediakan Apache, MySQL, PHP, Git, dan Composer terbina dalam satu pakej. (Muat turun dari laragon.org)
•	Visual Studio Code (VS Code): Editor kod utama untuk membuat sebarang suntingan program. (Muat turun dari code.visualstudio.com)
2. Langkah Setup Step-by-Step
Langkah 2.1: Jalankan Laragon
1. Buka perisian Laragon di laptop anda.
2. Klik butang Start All untuk memulakan servis Apache dan MySQL.
Langkah 2.2: Clone Repository dari GitHub
1. Buka Terminal dalam Laragon (klik butang Terminal di skrin utama Laragon).
2. Taip arahan berikut untuk masuk ke folder web root:
cd C:\laragon\www

3. Jalankan arahan git clone untuk mengambil projek:
git clone https://github.com/mionaufal03/Slitting_System_2.0.git

Langkah 2.3: Setup Dependencies (Folder /vendor/)
Oleh kerana folder /vendor/ di-ignore oleh Git, anda perlu membina semula pakej Composer (seperti PhpOffice & Endroid QR Code):
1. Masuk ke folder projek di terminal:
cd Slitting_System_2.0

2. Jalankan arahan berikut:
composer install

Langkah 2.4: Import Database (.sql)
1. Buka **MySQL Workbench** atau **HeidiSQL** (butang Database dalam Laragon).
2. Cipta database baharu bernama slitting_db (atau ikut nama database sistem anda).
3. Import fail `.sql` yang telah di-export daripada MySQL Workbench sebelum ini (mengandungi struktur table lengkap serta data spesifik untuk table `user`, `std_wght`, dan `coil_mapping`).
Langkah 2.5: Tetapan Fail Connection (config.php)
Create file config. Pastikan tetapan seperti berikut:
<?php
/**
 * Main System Configuration
 * Location: /slitting_system/config.php
 * -----------------------------------------------------------------------
 * Single source of truth for database connection, timezones, and base URL.
 * -----------------------------------------------------------------------
 */

// Database Credentials
$host   = 'localhost'; 
$user   = '';
$pass   = ''; 
$dbname = 'slitting_db'; 

// Initialize MySQLi Connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Handle Connection Failures
if ($conn->connect_error) { 
    die("Database Connection Failed: " . $conn->connect_error); 
} 

// Set Character Set and Timezone
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kuala_Lumpur');

// Dynamic Base URL Configuration
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host_header = $_SERVER['HTTP_HOST'] ?? 'localhost';
$BASE_URL    = "{$protocol}://{$host_header}/slitting_system";

?>


3. Menguji & Melancarkan Sistem
Selepas semua langkah di atas selesai, buka pelayar web (Google Chrome / Edge) dan taip salah satu URL berikut:
•	URL Virtual Host Laragon (Pretty URL): http://slitting_system_2.0.test
•	URL Localhost Standard: http://localhost/Slitting_System_2.0/login.php


Password untuk semua user : 

MKL3 : admin123
slitting : admin123
qc : admin123
