@extends('layouts.app')
@section('title','Anggota')
@section('content')
<h1>Anggota</h1><p class="muted mt-3 mb-7">Langkah 2 · Anggota selalu terhubung dengan satu kelompok. Status nonaktif mempertahankan riwayat.</p>
@if($groups->isEmpty())<div class="empty">Tambahkan kelompok terlebih dahulu. <a class="text-emerald-700 underline" href="{{ route('admin.groups') }}">Tambah kelompok →</a></div>@else
@if($editing)
<div class="card mb-6"><h2 class="mb-4">Edit anggota</h2><form method="post" action="{{ $editing ? route('admin.members.update',$editing) : route('admin.members.store') }}" class="grid gap-4 sm:grid-cols-2">@csrf @if($editing) @method('put') @endif
<div><label for="name">Nama anggota</label><input id="name" name="name" required maxlength="255" value="{{ old('name',$editing?->name) }}"></div><div><label for="group_id">Kelompok</label><select required id="group_id" name="group_id"><option value="">Pilih kelompok</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('group_id',$editing?->group_id) == $group->id)>{{ $group->name }} · {{ $group->code }}</option>@endforeach</select></div><div><label for="is_active">Status</label><select id="is_active" name="is_active"><option value="1" @selected(old('is_active',$editing?->is_active ?? true))>Aktif</option><option value="0" @selected(!old('is_active',$editing?->is_active ?? true))>Nonaktif</option></select></div><div class="flex items-end gap-3"><button class="btn">Simpan anggota</button>@if($editing)<a class="btn-secondary" href="{{ route('admin.members') }}">Batal</a>@endif</div></form></div>
@else
<form method="post" action="{{ route('admin.members.bulk') }}" class="card mb-6 space-y-5" data-bulk-members>
    @csrf
    <div><h2>Tambah anggota sekaligus</h2><p class="muted mt-2">Pilih kelompok dan status sekali, lalu isi daftar nama. Maksimal 100 anggota setiap penyimpanan.</p></div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label for="bulk_group_id">Kelompok untuk seluruh anggota</label><select id="bulk_group_id" name="group_id" required><option value="">Pilih kelompok</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('group_id',request('group_id')) == $group->id)>{{ $group->name }} · {{ $group->code }}</option>@endforeach</select></div>
        <div><label for="bulk_is_active">Status seluruh anggota baru</label><select id="bulk_is_active" name="is_active"><option value="1" @selected(old('is_active','1') == '1')>Aktif</option><option value="0" @selected(old('is_active','1') == '0')>Nonaktif</option></select></div>
    </div>
    <details class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-emerald-800">Tempel banyak nama dari Excel atau daftar teks</summary>
        <div class="mt-4"><label for="pasted_names">Satu nama pada setiap baris</label><textarea id="pasted_names" rows="5" placeholder="Siti Aminah&#10;Budi Santoso&#10;Dewi Lestari">{{ old('pasted_names') }}</textarea><p class="muted mt-2">Salin satu kolom nama dari Excel, lalu tempel di sini. Daftar yang dimasukkan akan ditambahkan ke baris di bawah untuk diperiksa.</p><button type="button" class="btn-secondary mt-3" data-import-names>Masukkan ke daftar</button><p class="mt-2 text-sm text-emerald-800" data-paste-feedback role="status"></p></div>
    </details>
    <div><div class="mb-3 flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">Daftar anggota baru</h3><output class="text-xs text-slate-500" data-member-count aria-live="polite"></output></div>
        <div class="space-y-3" data-member-rows>
            @foreach(array_values(is_array(old('members')) && old('members') ? old('members') : [['name'=>'']]) as $i=>$row)
            <div class="flex items-start gap-3 rounded-lg bg-slate-50 p-3" data-member-row>
                <div class="min-w-0 flex-1"><label for="member_name_{{ $i }}" data-member-label>Nama anggota {{ $i + 1 }}</label><input id="member_name_{{ $i }}" name="members[{{ $i }}][name]" value="{{ is_array($row) && is_scalar($row['name']??null) ? $row['name'] : '' }}" maxlength="255" required autocomplete="off" @if($errors->has('members.'.$i.'.name')) aria-invalid="true" aria-describedby="member_error_{{ $i }}" @endif>@error('members.'.$i.'.name')<p id="member_error_{{ $i }}" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <button type="button" class="btn-secondary mt-6" data-remove-member>Hapus</button>
            </div>
            @endforeach
        </div>
        <button type="button" class="btn-secondary mt-3" data-add-member>+ Tambah baris anggota</button>
    </div>
    <p class="muted">Periksa daftar sebelum menyimpan. Nama yang sama tetap dapat digunakan untuk anggota yang berbeda; setiap anggota mendapatkan nomor tersendiri.</p>
    <button class="btn" data-save-members>Simpan semua anggota</button>
</form>
@endif
@endif
<form class="mb-4 grid gap-3 sm:grid-cols-[2fr_1fr_auto]" method="get"><input name="q" aria-label="Cari nama anggota" value="{{ request('q') }}" placeholder="Cari nama anggota…"><select name="group_id" aria-label="Filter kelompok"><option value="">Semua Kelompok</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(request('group_id') == $group->id)>{{ $group->name }} · {{ $group->code }}</option>@endforeach</select><button class="btn-secondary">Cari</button></form>
@if($members->isEmpty())<div class="empty">Belum ada anggota yang sesuai. Tambahkan anggota melalui formulir di atas.</div>@else<div class="table-wrap"><table><thead><tr><th>Anggota</th><th>Kelompok</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@foreach($members as $member)<tr><td class="font-medium">{{ $member->name }} <span class="text-xs text-slate-400">#{{ $member->id }}</span></td><td>{{ $member->group->name }} · {{ $member->group->code }}</td><td>{{ $member->is_active ? 'Aktif' : 'Nonaktif' }}</td><td><a class="text-emerald-700 underline" href="{{ route('admin.members',['edit'=>$member->id]) }}">Edit</a></td></tr>@endforeach</tbody></table></div><div class="mt-4">{{ $members->links() }}</div>@endif
@endsection
