<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\WorkingHour;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkingHourController extends Controller
{
    public function index(Branch $branch): JsonResponse
    {
        return response()->json([
            'data' => $branch->workingHours()->orderBy('weekday')->orderBy('start_time')->get(),
        ]);
    }

    /**
     * Replace a branch's weekly hours in one call. Rows with user_id set the
     * hours for a specific staff member; rows without it are the branch default.
     */
    public function sync(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['present', 'array'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.start_time' => ['required', 'date_format:H:i'],
            'hours.*.end_time' => ['required', 'date_format:H:i', 'after:hours.*.start_time'],
            'hours.*.user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) =>
                    $q->where('salon_id', Tenancy::id())->where('role', 'staff')),
            ],
        ]);

        // Replace only the scopes present in the payload — always the branch
        // default (user_id null), plus any specific staff included — so other
        // staff members' custom hours are preserved, not wiped.
        $staffIds = collect($data['hours'])->pluck('user_id')->filter()->unique()->values();

        DB::transaction(function () use ($branch, $data, $staffIds) {
            $branch->workingHours()
                ->where(function ($q) use ($staffIds) {
                    $q->whereNull('user_id');
                    if ($staffIds->isNotEmpty()) {
                        $q->orWhereIn('user_id', $staffIds);
                    }
                })
                ->delete();

            foreach ($data['hours'] as $row) {
                $branch->workingHours()->create([
                    'salon_id' => $branch->salon_id,
                    'weekday' => $row['weekday'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'user_id' => $row['user_id'] ?? null,
                ]);
            }
        });

        return response()->json([
            'data' => $branch->workingHours()->orderBy('weekday')->get(),
        ]);
    }
}
