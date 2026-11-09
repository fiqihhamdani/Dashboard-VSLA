# Administrasi VSLA

Aplikasi Laravel 13 dan MySQL untuk pencatatan manual VSLA. Antarmuka berbahasa Indonesia, nominal rupiah bulat, zona waktu `Asia/Jakarta`. Dashboard publik hanya menampilkan agregat; data anggota, tagihan, transaksi, dan laporan Excel berada di ruang admin.

## Menjalankan di VS Code

Prasyarat: PHP **8.3.4+ 64-bit**, Composer 2, Node.js **22.12+** (disarankan 24), npm, dan MySQL **8.4**. Aktifkan ekstensi PHP `pdo_mysql`, `mbstring`, `xml`, `dom`, `fileinfo`, `curl`, `zip`, `tokenizer`, dan `openssl`. Docker Compose opsional untuk menyediakan MySQL.

Dependensi Composer dikunci dengan target PHP 8.3.4 agar cocok dengan Laragon PHP 8.3. PHP 8.4+ juga didukung.

Buka folder proyek di VS Code, lalu jalankan di terminal:

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci --ignore-scripts
```

Pada PowerShell, gunakan `Copy-Item .env.example .env` sebagai pengganti `cp`. Jangan menimpa `.env` atau membuat ulang `APP_KEY` pada instalasi yang sudah ada.

Pilihan A — MySQL melalui Docker:

```sh
docker compose up -d db
docker compose ps
```

Tunggu status `healthy`. `compose.yaml` menyimpan data di volume `vsla_mysql` dan hanya membuka port database pada loopback. Nilai `vsla_local`/`vsla_local_root` hanyalah contoh untuk pengembangan lokal. Ubah sebelum digunakan di lingkungan bersama.

Pilihan B — MySQL yang sudah terpasang: buat database kosong dan pengguna aplikasi, misalnya dari klien MySQL sebagai administrator:

```sql
CREATE DATABASE vsla CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vsla'@'localhost' IDENTIFIED BY 'ganti_dengan_kata_sandi_anda';
GRANT ALL PRIVILEGES ON vsla.* TO 'vsla'@'localhost';
```

Sesuaikan `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Jika memakai Docker, nilai contoh `.env.example` sudah sesuai. Apabila port 3306 sudah digunakan, ubah `DB_PORT` sebelum menjalankan Compose.

```sh
php artisan config:clear
php artisan migrate
php artisan vsla:admin pengurus@example.com --name="Nama Pengurus"
npm run build
php artisan serve
```

Perintah `vsla:admin` meminta kata sandi minimal 12 karakter dan konfirmasinya secara tersembunyi. Tidak ada kata sandi bawaan, registrasi publik, atau akun admin otomatis.

Buka alamat yang ditampilkan `php artisan serve` (umumnya `http://127.0.0.1:8000`). `/` adalah dashboard publik; `/login` untuk masuk admin. Untuk pengembangan tampilan dengan pemuatan ulang aset, jalankan `npm run dev` di terminal kedua. Sistem ini tidak membutuhkan CDN, font eksternal, Excel sumber, layanan email, atau Redis.

### Mesin cloud pada workspace ini

PHP dan Composer lokal tersedia lewat:

```sh
export PATH=/workspace/.cloud-setup/bin:$PATH
cd /workspace/Dashboard-VSLA
php artisan serve --no-reload --host=127.0.0.1 --port=8000
```

`--no-reload` diperlukan untuk mempertahankan jalur pustaka PHP lokal pada proses server anak. MySQL pengembangan saat implementasi berjalan di container `vsla-mysql`; `docker start vsla-mysql` jika container itu berhenti. Jangan menjalankan container Compose kedua pada port sama. Data operasional database `vsla` tetap kosong. Buat akun admin Anda sendiri dengan perintah di atas. Proses yang sedang berjalan tidak diasumsikan bertahan pada task baru.

## Mulai dengan data kosong

Migrasi hanya membuat tabel, foreign key, indeks, dan kolom admin. Seeder bawaan sengaja tidak menambahkan akun ataupun data operasional. Urutan pengisian:

1. **Kelompok:** tambah nama, kode unik, lokasi, dan status.
2. **Anggota:** pilih kelompok dan status sekali, lalu tambah beberapa baris nama atau tempel satu kolom nama dari Excel (satu nama per baris). Periksa daftar, lalu klik **Simpan semua anggota**. Maksimal 100 anggota per penyimpanan; jika satu baris tidak valid, tidak ada anggota yang disimpan. Anggota yang sudah ada tetap dapat diedit satu per satu. Nama yang sama dibedakan dengan kelompok serta nomor anggota pada pilihan transaksi.
3. **Transaksi:** pilih kelompok, lalu simpanan, pencairan pinjaman, pembayaran angsuran, atau kas lainnya.
4. **Pinjaman & tagihan:** lihat jadwal, sisa cicilan, status lunas, dan riwayat pembayaran.
5. **Buku kas & laporan:** pilih kelompok/periode dan unduh `.xlsx` yang berisi Ringkasan, Rekap Bulanan, dan Buku Kas.

Tidak tersedia penghapusan transaksi keuangan. Anggota dengan riwayat tidak boleh dipindahkan ke kelompok lain. Nonaktifkan anggota untuk mempertahankan seluruh relasi; pembayaran tagihan lama tetap diizinkan untuk anggota/kelompok nonaktif. Migrasi bersifat tambahan terhadap tabel pengguna yang ada. Pada database berisi data, cadangkan dahulu lalu jalankan `php artisan migrate`; **jangan gunakan `migrate:fresh`, `db:wipe`, atau menghapus volume**.

## Aturan hitung dan integritas

- Semua uang disimpan sebagai integer rupiah. Bunga bulanan disimpan sebagai basis poin (`1,25%` = 125), bukan floating point. Formulir menerima titik sebagai pemisah desimal bunga, misalnya `1.25`.
- Bunga total = pokok × basis poin × tenor / 10.000, dibulatkan setengah ke atas ke rupiah terdekat. Total kewajiban = pokok + bunga total. Cicilan dasar dibagi bulat; seluruh sisa masuk cicilan terakhir.
- Jatuh tempo selalu dihitung dari **tanggal pencairan asli** ditambah n bulan, lalu dijepit ke tanggal terakhir bulan tujuan. Contoh 31 Januari → 28/29 Februari → 31 Maret → 30 April. Tanggal tidak bergeser permanen akibat bulan Februari.
- Setoran gabungan menghasilkan satu kas masuk sebesar total dan satu rincian simpanan per anggota. Penarikan menghasilkan satu kas keluar; saldo diperiksa **per anggota dan jenis simpanan**, termasuk kondisi pada tanggal transaksi serta dampak terhadap transaksi berikutnya.
- Pencairan menghasilkan satu kas keluar dan jadwal tagihan. Pembayaran menghasilkan satu kas masuk yang terkait satu tagihan; pembayaran sebagian didukung. Pembayaran melebihi sisa atau sebelum pencairan ditolak. Status lunas dihitung saat sisa tepat Rp0.
- Setiap formulir transaksi memiliki UUID unik. Pengiriman ulang UUID yang sama dengan data sama mengembalikan catatan sebelumnya; UUID yang sama dengan data berbeda ditolak. Membuka formulir baru menghasilkan UUID baru dan dianggap transaksi baru. Jangan membuka formulir baru hanya untuk mengulang kiriman yang sama.
- Kas, rincian, serta jadwal disimpan dalam satu transaksi database. Penguncian baris admin/kelompok/anggota/tagihan serta indeks unik mencegah saldo terlewati dan duplikasi saat permintaan bersamaan.
- Pemasukan/pengeluaran lain boleh membuat saldo kas negatif agar pengurus dapat mencatat keadaan aktual; aplikasi tidak menganggap saldo kas sebagai plafon pinjaman. Saldo simpanan untuk penarikan tetap tidak boleh negatif.
- Saldo awal berasal dari seluruh kas sebelum tanggal mulai. Saldo akhir serta simpanan bersih dihitung sampai tanggal akhir. Pencairan dan angsuran pada kartu dihitung selama periode. Anggota aktif selalu kondisi saat ini.
- Tunggakan mencakup sisa tagihan dengan jatuh tempo **pada atau sebelum** tanggal akhir; hanya pembayaran pada/sebelum tanggal itu yang menguranginya. Pembayaran sesudahnya tidak mengubah laporan historis.
- Bulan tanpa aktivitas tetap tampil, arus kas Rp0 dan saldo dibawa. Bulan pertama/terakhir mengikuti batas tanggal saat filter berada di tengah bulan. Periode laporan dibatasi maksimal 10 tahun agar grafik tetap terbaca.
- Dashboard, buku kas, dan Excel menggunakan layanan perhitungan yang sama dan snapshot transaksi database yang konsisten. Ekspor adalah file XLSX asli dengan teks sebagai `inlineStr` agar nama/uraian tidak dieksekusi sebagai formula.

## Skenario manual yang dapat diulang

Untuk menguji tanpa mengisi database utama, gunakan database **`vsla_manual_test`** dan pengguna yang memiliki akses ke database tersebut. Salin `.env` ke `.env.manual_test`, ganti `DB_DATABASE=vsla_manual_test`, lalu jalankan:

```sh
php artisan migrate --env=manual_test
php artisan vsla:admin pengurus-uji@example.com --env=manual_test
php artisan serve --env=manual_test --port=8002
```

Pastikan `DB_DATABASE` pada file itu benar sebelum migrasi. Isi seluruh contoh berikut melalui browser pada server database uji tersebut. Database utama tidak diberi data contoh oleh instalasi.

1. Buat satu kelompok dan dua anggota uji.
2. Pada **1 September 2026**, pilih Simpanan, jenis Sukarela, Setoran. Tambahkan dua baris, masing-masing Rp100.000. Total harus Rp200.000; setelah simpan ada satu kas masuk dan dua rincian simpanan.
3. Pada **10 September 2026**, cairkan pinjaman Rp100.000 kepada anggota pertama, bunga flat **10% per bulan**, tenor **1 bulan**. Preview menunjukkan total Rp110.000 dan jatuh tempo **10 Oktober 2026**. Saldo kas menjadi Rp100.000.
4. Pada **10 Oktober 2026**, bayar sebagian tagihan anggota pertama sebesar Rp40.000. Saldo kas menjadi Rp140.000, sisa tagihan Rp70.000.
5. Keluar dari admin. Di dashboard publik pilih kelompok tadi, periode **1 September–31 Oktober 2026**. Saldo akhir grafik: September Rp100.000 dan Oktober Rp140.000. Tunggakan akhir Rp70.000. Pilih titik grafik untuk melihat nilai rupiah.
6. Ulangi pengiriman **formulir yang sama**, misalnya kirim ulang POST dari tab Network browser (termasuk token CSRF dan `request_key` asli). Jumlah kas, rincian, dan saldo harus tetap. Jangan membuat formulir baru untuk langkah ini.
7. Masuk admin dan unduh Excel dengan filter sama. Cocokkan ringkasan, saldo bulanan, dan buku kas. Coba pembayaran Rp70.001: harus ditolak tanpa kas baru.
8. Tambahkan kelompok kedua, lalu bandingkan filter satu kelompok dan Semua Kelompok. Coba bulan tanpa transaksi, periode di tengah bulan, serta pembayaran sesudah akhir laporan.
9. Tanpa login, akses `/admin` dan `/admin/laporan.xlsx`: harus dialihkan ke login. Nama anggota/rincian tidak muncul dalam HTML/data grafik publik.

## Pengujian otomatis dengan MySQL terpisah

Buat database tes menggunakan administrator MySQL. Contoh untuk container Compose:

```sh
docker compose exec db mysql -uroot -p
```

Di klien MySQL (sesuaikan pengguna jika berbeda):

```sql
CREATE DATABASE vsla_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON vsla_test.* TO 'vsla'@'%';
```

Untuk MySQL lokal, grant ke `'vsla'@'localhost'` sesuai akun yang dibuat. Lalu:

```sh
cp .env.testing.example .env.testing
php artisan key:generate --env=testing
php artisan test
```

Sesuaikan kredensial pada `.env.testing` dengan database uji. `phpunit.xml` memilih koneksi MySQL dan database `vsla_test`. Pengaman tes menolak database tanpa akhiran `_test`; tetap pastikan akun tes tidak memiliki akses ke database produksi. Migrasi tes dapat menghapus isi **database tes**. Tidak ada Excel acuan yang diperlukan.

Tes mencakup kondisi kosong, skenario September–Oktober, filter dua kelompok dan bulan parsial, saldo bulan tanpa transaksi, privasi publik, otorisasi, login/logout dan pembatasannya, UUID kiriman ulang, pembayaran berlebih/anggota salah, histori anggota nonaktif, tanggal akhir bulan, pembulatan, saldo penarikan bertanggal mundur, rollback saat jadwal gagal, serta rekonsiliasi XLSX.

Pemeriksaan tambahan:

```sh
npm run build
vendor/bin/pint --dirty --format agent
```

Validasi pada workspace: PHP 8.3.36 dan 8.4, Node 24, MySQL 8.4; seluruh 29 tes (297 assertion) lulus pada kedua versi PHP; pengujian browser Chromium juga dijalankan pada database `vsla_browser_test` untuk alur formulir, preview, unduhan XLSX, grafik publik, dan lebar ponsel. Data contoh browser hanya berada di database tersebut.

## Batas tahap ini

Tahap ini ditujukan untuk pengisian dan pengujian lokal. Tidak mencakup impor Excel, reset kata sandi lewat email, penghapusan/koreksi transaksi yang sudah dibukukan, atau deployment produksi. Pembatalan/koreksi transaksi memerlukan rancangan audit terpisah. Untuk produksi, siapkan HTTPS, kredensial unik, `APP_DEBUG=false`, backup, kebijakan akses, serta peninjauan beban data yang sesuai.
