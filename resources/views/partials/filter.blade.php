<form method="get" class="card mb-7 grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]" aria-label="Filter laporan">
<div><label for="group_id">Kelompok</label><select id="group_id" name="group_id"><option value="">Semua Kelompok</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(($report['filter']['group_id']??'') == $group->id)>{{ $group->name }} · {{ $group->code }}</option>@endforeach</select></div>
<div><label for="start">Tanggal mulai</label><input required type="date" id="start" name="start" value="{{ $report['filter']['start'] }}"></div><div><label for="end">Tanggal akhir</label><input required type="date" id="end" name="end" value="{{ $report['filter']['end'] }}"></div><button class="btn">Terapkan filter</button>
</form>
