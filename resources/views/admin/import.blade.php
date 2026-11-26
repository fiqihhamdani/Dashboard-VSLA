@extends('layouts.app')
@section('title', 'Import Excel')
@section('content')
<h1>Import Excel · {{ ['groups'=>'Kelompok','members'=>'Anggota','saving'=>'Simpanan','loan'=>'Pencairan pinjaman','payment'=>'Pembayaran angsuran','other'=>'Kas lainnya'][$module] }}</h1>
<div class="card mt-6 space-y-4"><h2>Siapkan data sesuai template</h2>
<p class="muted">File .xlsx maksimal 5 MB, 500 baris data, dan 20 MB setelah diurai. Gunakan sheet Data dari template; jangan mengubah kolom. Tanggal harus teks YYYY-MM-DD, rupiah berupa angka bulat tanpa pemisah ribuan. Tidak menerima formula, macro, atau sumber eksternal.</p>
<p class="muted">Referensi kelompok, anggota, dan tagihan harus sudah tersedia. Setiap transaksi memakai kode UUID unik. Untuk simpanan, satu anggota per baris dan kode transaksi. Import menolak duplikasi dan tidak menimpa data lama; seluruh file dibatalkan jika satu baris tidak valid. Data export bersifat laporan; gunakan template untuk import.</p>
@if($module === 'members')<p class="muted">Nama yang sama dalam kelompok yang sama dianggap duplikat saat import (tanpa membedakan huruf besar/kecil).</p>@endif
<a class="btn-secondary" href="{{ route('admin.excel.template',$module) }}">Unduh template Excel</a>
<form action="{{ route('admin.excel.preview',$module) }}" method="post" enctype="multipart/form-data" class="space-y-4" data-busy-form>@csrf
<div><label for="excel-file">File Excel (.xlsx)</label><input required id="excel-file" type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div><button class="btn">Validasi dan pratinjau</button></form>
</div>
@endsection
