<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * AuditLogController
 *
 * Exposes the Spatie activity log for compliance and fraud investigations.
 * Restricted to Provincial Staff (manage-settings gate) — municipal staff
 * must request an export through their supervisor to avoid cross-LGU exposure.
 */
class AuditLogController extends Controller
{
    /**
     * Return a paginated, filterable audit log.
     *
     * Query parameters:
     *   - log_name   (string)  — filter by log channel: 'claims', 'users', 'beneficiaries', etc.
     *   - subject_type (string) — filter by model class short name, e.g. 'Claim', 'User'
     *   - causer_id  (int)     — filter by the user who triggered the event
     *   - event      (string)  — filter by event type: 'created', 'updated', 'deleted'
     *   - date_from  (date)    — ISO 8601 start date (inclusive)
     *   - date_to    (date)    — ISO 8601 end date (inclusive)
     *   - per_page   (int)     — records per page (default 50, max 200)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'log_name'     => ['sometimes', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'string', 'max:100'],
            'causer_id'    => ['sometimes', 'integer'],
            'event'        => ['sometimes', 'string', 'in:created,updated,deleted,restored'],
            'date_from'    => ['sometimes', 'date'],
            'date_to'      => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page'     => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Activity::query()
            ->with('causer:id,uuid,name,email,role')
            ->latest();

        if ($logName = $request->input('log_name')) {
            $query->inLog($logName);
        }

        if ($subjectType = $request->input('subject_type')) {
            // Accept a short name like "Claim" and resolve it to the full class path
            // so callers don't need to know the full namespace.
            $fqcn = 'App\\Models\\' . $subjectType;
            $query->forSubject(new $fqcn());
        }

        if ($causerId = $request->input('causer_id')) {
            $query->causedBy($causerId);
        }

        if ($event = $request->input('event')) {
            $query->where('event', $event);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (Activity $activity) => [
                'id'           => $activity->id,
                'log_name'     => $activity->log_name,
                'event'        => $activity->event,
                'description'  => $activity->description,
                'subject_type' => class_basename($activity->subject_type ?? ''),
                'subject_id'   => $activity->subject_id,
                'causer'       => $activity->causer ? [
                    'uuid'  => $activity->causer->uuid,
                    'name'  => $activity->causer->name,
                    'email' => $activity->causer->email,
                    'role'  => $activity->causer->role,
                ] : null,
                'properties'   => $activity->properties,
                'timestamp'    => $activity->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
