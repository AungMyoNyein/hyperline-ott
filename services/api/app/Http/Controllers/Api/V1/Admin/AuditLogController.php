<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:64'],
            'user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('ott.catalog.max_page_size')],
        ]);

        $logs = AdminAuditLog::query()
            ->with('user')
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', (int) $filters['user_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
