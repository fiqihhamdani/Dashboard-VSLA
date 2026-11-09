@extends('layouts.app')
@section('title','Masuk Admin')
@section('content')
<div class="mx-auto my-8 max-w-md"><div class="mb-7 text-center"><span class="tag mb-4">Ruang pengurus</span><h1>Selamat datang kembali</h1><p class="muted mt-3">Masuk untuk mengelola data kelompok dan pencatatan keuangan VSLA.</p></div><form method="post" action="{{ route('login.store') }}" class="card space-y-5">@csrf<div><label for="email">Email admin</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div><div><label for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn w-full">Masuk Admin →</button></form><p class="mt-5 text-center text-sm text-slate-500"><a href="{{ route('dashboard') }}" class="hover:text-emerald-700">← Kembali ke dashboard publik</a></p></div>
@endsection
