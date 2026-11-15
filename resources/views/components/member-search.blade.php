@props(['name', 'id', 'kind', 'groupId' => null, 'selected' => null, 'error' => null, 'label' => 'Nama anggota', 'required' => true])
<div class="relative min-w-0" data-member-search data-endpoint="{{ route('admin.members.search') }}" data-kind="{{ $kind }}" data-group="{{ $groupId }}">
    <label for="{{ $id }}-search">{{ $label }}</label>
    <input type="hidden" name="{{ $name }}" value="{{ $selected?->id }}" data-member-value>
    <input id="{{ $id }}-search" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $id }}-list" aria-describedby="{{ $id }}-status{{ $error ? ' '.$id.'-error' : '' }}" autocomplete="off" maxlength="100" placeholder="{{ $groupId ? 'Ketik sebagian nama anggota…' : 'Pilih kelompok terlebih dahulu' }}" value="{{ $selected ? $selected->name.' · '.$selected->group->name.' · '.$selected->group->code.' · #'.$selected->id : '' }}" @disabled(!$groupId) @required($required) @if($error) aria-invalid="true" @endif data-member-input>
    <div class="absolute z-20 mt-1 w-full rounded-xl border border-slate-200 bg-white p-1 shadow-lg" data-member-panel hidden>
        <div id="{{ $id }}-list" role="listbox" aria-label="Hasil pencarian anggota" class="max-h-60 overflow-y-auto" data-member-results></div>
        <button class="btn-secondary mt-1 w-full" type="button" data-member-more hidden>Muat lebih banyak</button>
    </div>
    <p id="{{ $id }}-status" class="mt-1 text-xs text-slate-500" role="status" data-member-status>{{ $groupId ? 'Cari nama, lalu pilih anggota dari hasil pencarian.' : 'Pilih kelompok terlebih dahulu' }}</p>
    @if($error)<p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $error }}</p>@endif
</div>
