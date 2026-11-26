@php($entry = $snapshot['cash'])
<dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
<dt class="muted">Status</dt><dd>{{ $entry['deleted_at'] ? 'Dibatalkan' : 'Aktif' }}</dd>
<dt class="muted">Tanggal</dt><dd>{{ substr($entry['date'],0,10) }}</dd>
<dt class="muted">Arah kas</dt><dd>{{ $entry['direction'] === 'in' ? 'Masuk' : 'Keluar' }}</dd>
<dt class="muted">Nominal</dt><dd>@rupiah($entry['amount'])</dd>
<dt class="muted">Uraian</dt><dd class="break-words">{{ $entry['description'] }}</dd>
</dl>
@if($entry['category'] === 'loan')<div class="mt-3">@include('partials.drive-link',['url'=>$entry['proof_url'],'label'=>'Lihat Bukti Pencairan'])</div>@endif
@if($snapshot['loan'])
@php($loanSnapshot = $snapshot['loan'])
<p class="mt-4 text-sm">Pokok @rupiah($loanSnapshot['principal']) · bunga {{ number_format($loanSnapshot['rate_bps']/100,2,',','.') }}%/bulan · {{ $loanSnapshot['months'] }} bulan · total @rupiah($loanSnapshot['total'])</p>
<div class="table-wrap mt-3"><table><thead><tr><th>Cicilan</th><th>Jatuh tempo</th><th>Tagihan</th><th>Dibayar</th></tr></thead><tbody>@foreach($loanSnapshot['installments'] as $item)<tr><td>{{ $item['sequence'] }}</td><td>{{ substr($item['due_date'],0,10) }}</td><td>@rupiah($item['amount'])</td><td>@rupiah(collect($item['payments'])->sum('amount'))</td></tr>@endforeach</tbody></table></div>
@endif
@if($snapshot['savings'])<div class="table-wrap mt-3"><table><thead><tr><th>Anggota</th><th>Jenis</th><th>Nominal</th></tr></thead><tbody>@foreach($snapshot['savings'] as $item)<tr><td>Anggota #{{ $item['member_id'] }}</td><td>{{ ucfirst($item['type']) }}</td><td>@rupiah($item['amount'])</td></tr>@endforeach</tbody></table></div>@endif
@if($snapshot['payment'])<p class="mt-3 text-sm">Pembayaran tagihan #{{ $snapshot['payment']['installment_id'] }} · @rupiah($snapshot['payment']['amount'])</p>@endif
