<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimeOffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = TimeOff::query()
            ->when($request->query('branch'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('staff'), fn ($q, $id) => $q->where('user_id', $id))
            // Staff only see their own blocks (+ branch-wide closures).
            ->when($user->isStaff(), fn ($q) =>
                $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhereNull('user_id')))
            ->orderBy('starts_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $salonId = Tenancy::id();

        $data = $request->validate([
            'branch_id' => [
                'required',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Staff may only block their own time — never a branch-wide closure or
        // someone else's schedule.
        if ($user->isStaff()) {
            $data['user_id'] = $user->id;
        }

        // Interpret the entered times in the salon timezone → store UTC, so the
        // availability engine blocks the right window.
        $tz = Salon::find($salonId)?->timezone ?? 'Asia/Riyadh';
        $data['starts_at'] = Carbon::parse($data['starts_at'], $tz)->utc();
        $data['ends_at'] = Carbon::parse($data['ends_at'], $tz)->utc();

        $timeOff = TimeOff::create($data);

        return response()->json(['data' => $timeOff], 201);
    }

    public function destroy(Request $request, TimeOff $timeOff): JsonResponse
    {
        $user = $request->user();

        // Staff can only remove their own blocks.
        if ($user->isStaff() && $timeOff->user_id !== $user->id) {
            abort(403, 'You can only remove your own time off.');
        }

        $timeOff->delete();

        return response()->json(['message' => 'Time off removed.']);
    }
}
