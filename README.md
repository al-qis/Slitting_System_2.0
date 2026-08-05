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
$host = "localhost";
$user = "root";
$pass = ""; // Kosongkan secara default di Laragon
$db   = "slitting_db";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>

3. Menguji & Melancarkan Sistem
Selepas semua langkah di atas selesai, buka pelayar web (Google Chrome / Edge) dan taip salah satu URL berikut:
•	URL Virtual Host Laragon (Pretty URL): http://slitting_system_2.0.test
•	URL Localhost Standard: http://localhost/Slitting_System_2.0/login.php


Password untuk semua user : 

MKL3 : admin123
slitting : admin123
qc : admin123
