<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Group;
use App\Services\ReportService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ReportRequest $request, ReportService $reports): View
    {
        return view('dashboard', ['report' => $reports->dashboard($request->validated()), 'groups' => Group::orderBy('name')->get(['id', 'name', 'code'])]);
    }
}
