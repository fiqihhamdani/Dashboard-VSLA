<?php

return [
    'required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'integer' => ':attribute harus berupa bilangan bulat rupiah.', 'numeric' => ':attribute harus berupa angka.', 'email' => 'Masukkan alamat email yang valid.', 'unique' => ':attribute sudah digunakan. Pilih nilai lain.', 'exists' => ':attribute tidak ditemukan. Pilih data yang tersedia.', 'boolean' => 'Pilih status aktif atau nonaktif.', 'in' => 'Pilihan :attribute tidak valid.', 'uuid' => 'Kode formulir tidak valid. Muat ulang halaman.', 'array' => ':attribute harus berupa daftar.', 'distinct' => 'Anggota yang sama tidak boleh muncul dua kali dalam setoran gabungan.', 'regex' => 'Format :attribute tidak valid. Gunakan angka dengan maksimal dua desimal.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => ':attribute harus sama dengan atau setelah :date.', 'before_or_equal' => ':attribute harus sama dengan atau sebelum :date.', 'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'min' => ['numeric' => ':attribute minimal :min.', 'string' => ':attribute minimal :min karakter.', 'array' => 'Isi paling sedikit :min baris :attribute.'],
    'max' => ['numeric' => ':attribute maksimal :max.', 'string' => ':attribute maksimal :max karakter.', 'array' => ':attribute maksimal :max baris.'],
    'attributes' => ['email' => 'email', 'password' => 'kata sandi', 'name' => 'nama'],
];
