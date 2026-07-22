<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Owner dashboard (E11-1 / E11-2): bookings & revenue for a date range
     * (defaults to today, in the salon's timezone), with per-staff and
     * per-service breakdowns plus the next upcoming appointments.
     */
    public function index(Request $request): JsonResponse
    {
        $salon = Salon::findOrFail(Tenancy::id());
        $tz = $salon->timezone ?? 'Asia/Riyadh';

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = Carbon::parse($data['from'] ?? Carbon::today($tz)->toDateString(), $tz)->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->format('Y-m-d'), $tz)->endOfDay();

        $appts = Appointment::with(['staff:id,name', 'service:id,name'])
            ->whereBetween('starts_at', [$from->clone()->utc(), $to->clone()->utc()])
            ->get();

        $revenue = fn ($c) => round((float) $c->where('status', 'done')->sum(fn ($a) => (float) $a->price), 2);

        $byStatus = [];
        foreach (Appointment::STATUSES as $s) {
            $byStatus[$s] = $appts->where('status', $s)->count();
        }

        $byStaff = $appts->groupBy('staff_id')->map(fn ($g) => [
            'staff_id' => $g->first()->staff_id,
            'staff' => $g->first()->staff?->name,
            'bookings' => $g->count(),
            'revenue' => $revenue($g),
        ])->sortByDesc('bookings')->values();

        $byService = $appts->groupBy('service_id')->map(fn ($g) => [
            'service_id' => $g->first()->service_id,
            'service' => $g->first()->service?->name,
            'bookings' => $g->count(),
            'revenue' => $revenue($g),
        ])->sortByDesc('bookings')->values();

        $upcoming = Appointment::with(['customer:id,name,phone', 'service:id,name', 'staff:id,name'])
            ->where('status', 'confirmed')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz],
            // Onboarding checklist state — drives the "get set up" guide on the dashboard.
            'setup' => [
                'has_staff' => User::where('role', 'staff')->exists(),
                'has_branch' => Branch::exists(),
                'has_service' => Service::exists(),
                'slug' => $salon->slug,
            ],
            'totals' => [
                'bookings' => $appts->count(),
                'by_status' => $byStatus,
            ],
            'revenue' => [
                'collected' => $revenue($appts),                              // done
                'expected' => round((float) $appts->whereIn('status', ['confirmed', 'done'])
                    ->sum(fn ($a) => (float) $a->price), 2),                   // confirmed + done
                'currency' => 'SAR',
            ],
            'by_staff' => $byStaff,
            'by_service' => $byService,
            'upcoming' => $upcoming,
        ]);
    }
}
