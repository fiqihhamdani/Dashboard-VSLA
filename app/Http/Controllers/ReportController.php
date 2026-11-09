<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Group;
use App\Services\ExcelReport;
use App\Services\ReportService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function ledger(ReportRequest $request, ReportService $service): View
    {
        $filter = $request->validated();

        return view('admin.ledger', $service->exportData($filter) + ['groups' => Group::orderBy('name')->get()]);
    }

    public function download(ReportRequest $request, ReportService $service, ExcelReport $excel): BinaryFileResponse
    {
        $filter = $request->validated();
        $group = empty($filter['group_id']) ? 'Semua Kelompok' : Group::findOrFail($filter['group_id'])->name;
        $bundle = $service->exportData($filter);
        $path = $excel->create($bundle['report'], $bundle['ledger'], $group);

        return response()->download($path, 'VSLA-'.$filter['start'].'-'.$filter['end'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
