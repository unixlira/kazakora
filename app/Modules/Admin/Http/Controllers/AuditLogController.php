<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    private const MAX_RESULTS = 500;

    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('entity'), fn ($query) => $query->where('entity', $request->string('entity')))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('created_at')
            ->limit(self::MAX_RESULTS)
            ->get();

        return Inertia::render('Admin/Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('user_id', 'action', 'entity', 'from', 'to'),
            'users' => User::query()->whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(['id', 'name']),
            'entities' => AuditLog::query()->distinct()->orderBy('entity')->pluck('entity'),
        ]);
    }
}
