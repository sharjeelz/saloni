<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Bulk import existing customers from pasted rows or a CSV, one per line:
     * "name, phone[, email[, notes]]". Phones are canonicalized to E.164 and
     * customers are matched by phone — so re-running updates instead of
     * duplicating. Returns a summary (created / updated / skipped).
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000000'],
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $firstLine = true;

        foreach (preg_split('/\r\n|\r|\n/', $data['text']) as $lineNo => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            // A CSV header row has no phone digits — skip it silently (not counted).
            if ($firstLine) {
                $firstLine = false;
                if (! preg_match('/[0-9]/', $line)) {
                    continue;
                }
            }

            $cols = array_map('trim', preg_split('/\t|,|;/', $line));
            $name = $cols[0] ?? '';
            $phone = Phone::canonicalize($cols[1] ?? '');
            $email = $this->pickEmail($cols);
            $notes = isset($cols[3]) && ! Str::contains($cols[3], '@') ? Str::limit($cols[3], 1000, '') : null;

            if ($name === '' || ! Phone::isValid($phone)) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = ['line' => $lineNo + 1, 'value' => Str::limit($line, 60)];
                }
                continue;
            }

            // Match by phone within the salon (global scope handles tenancy).
            $customer = Customer::firstOrNew(['phone' => $phone]);
            $isNew = ! $customer->exists;

            $customer->name = mb_substr($name, 0, 255);
            if ($email) {
                $customer->email = $email;
            }
            if ($notes) {
                $customer->notes = $notes;
            }
            $customer->save();

            $isNew ? $created++ : $updated++;
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    /** First column that looks like an email address. */
    protected function pickEmail(array $cols): ?string
    {
        foreach (array_slice($cols, 2) as $c) {
            if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
                return mb_substr($c, 0, 255);
            }
        }

        return null;
    }

    /** Directory + search (by name or phone). Tenant-scoped. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::withCount('appointments')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) =>
                $w->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                    ->orWhere('phone', 'like', "%{$q}%")))
            ->orderByDesc('last_visit_at')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'phone', 'email', 'last_visit_at']);

        return response()->json(['data' => $customers]);
    }

    /** A customer profile with their booking history. */
    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount('appointments');
        $history = $customer->appointments()
            ->with(['service:id,name,name_en', 'staff:id,name', 'branch:id,name'])
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get(['id', 'branch_id', 'service_id', 'staff_id', 'starts_at', 'status', 'price']);

        return response()->json([
            'data' => $customer,
            'history' => $history,
        ]);
    }

    /** Edit a customer's profile (name, email, notes). */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer->update($data);

        return response()->json(['data' => $customer]);
    }
}
