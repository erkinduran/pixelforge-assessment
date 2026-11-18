<?php

namespace App\Http\Controllers;

use App\Admin\UsageReport;
use App\Http\Requests\AdminReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(AdminReportRequest $request, UsageReport $report): Response
    {
        return Inertia::render('admin/Report', [
            'report' => $report->build($request->from(), $request->to()),
            'range' => ['from' => $request->from()->toDateString(), 'to' => $request->to()->toDateString(), 'max_days' => AdminReportRequest::MAX_DAYS],
        ]);
    }
}
