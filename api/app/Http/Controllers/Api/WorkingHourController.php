<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\WorkingHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'hours.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($branch, $data) {
            $branch->workingHours()->delete();

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
