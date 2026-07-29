<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return response()->json(PaymentMethod::all());
    }

    public function store(StorePaymentMethodRequest $request)
    {
        $data = $request->validated();

        $method = PaymentMethod::create([
            'name'           => $data['name'],
            'is_cash'        => $data['is_cash'] ?? false,
            'icon'           => $data['icon'] ?? '💳',
            'logo'           => $data['logo'] ?? null,
            'description'    => $data['description'] ?? null,
            'bank_name'      => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_name'   => $data['account_name'] ?? null,
            'status'         => $data['status'] ?? true,
        ]);

        AuditLog::record(Auth::id(), 'payment_method_created', 'PaymentMethod', $method->id, "Created payment method \"{$method->name}\"");

        return response()->json(['message' => 'Payment method created!', 'data' => $method], 201);
    }

    public function update(UpdatePaymentMethodRequest $request, int $id)
    {
        $method = PaymentMethod::findOrFail($id);
        $data = $request->validated();

        // The seeded Cash method is the sole source of truth for is_cash-
        // based cash-shift accounting (CashierShiftController sums by the
        // is_cash flag, not by name) - any edit here, not just a rename,
        // risks breaking that accounting (e.g. clearing is_cash would make
        // every cash sale stop counting as cash) or confusing cashiers
        // looking at the payment picker. Locked from the admin UI entirely -
        // it isn't even listed there (see Payments.jsx's is_cash filter).
        if ($method->is_cash) {
            return response()->json([
                'message' => 'The Cash payment method cannot be edited.',
            ], 422);
        }

        $method->update([
            'name'           => $data['name'],
            'is_cash'        => $data['is_cash'] ?? $method->is_cash,
            'icon'           => $data['icon'] ?? $method->icon,
            'logo'           => $data['logo'] ?? $method->logo,
            'description'    => $data['description'] ?? null,
            'bank_name'      => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_name'   => $data['account_name'] ?? null,
            'status'         => $data['status'] ?? $method->status,
        ]);

        AuditLog::record(Auth::id(), 'payment_method_updated', 'PaymentMethod', $method->id, "Updated payment method \"{$method->name}\"");

        return response()->json(['message' => 'Payment method updated!', 'data' => $method]);
    }

    public function destroy(int $id)
    {
        $method = PaymentMethod::findOrFail($id);

        if ($method->is_cash) {
            return response()->json([
                'message' => 'The Cash payment method cannot be deleted.',
            ], 422);
        }

        if ($method->orders()->exists()) {
            return response()->json([
                'message' => 'This payment method cannot be deleted because it is linked to existing orders.',
            ], 409);
        }

        $name = $method->name;
        $method->delete();

        AuditLog::record(Auth::id(), 'payment_method_deleted', 'PaymentMethod', $id, "Deleted payment method \"{$name}\"");

        return response()->json(['message' => 'Payment method deleted!']);
    }
}