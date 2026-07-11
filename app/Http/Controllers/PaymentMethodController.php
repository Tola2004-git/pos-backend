<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;

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
            'icon'           => $data['icon'] ?? '💳',
            'logo'           => $data['logo'] ?? null,
            'description'    => $data['description'] ?? null,
            'bank_name'      => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_name'   => $data['account_name'] ?? null,
            'status'         => $data['status'] ?? true,
        ]);

        return response()->json(['message' => 'Payment method created!', 'data' => $method], 201);
    }

    public function update(UpdatePaymentMethodRequest $request, int $id)
    {
        $method = PaymentMethod::findOrFail($id);
        $data = $request->validated();

        $method->update([
            'name'           => $data['name'],
            'icon'           => $data['icon'] ?? $method->icon,
            'logo'           => $data['logo'] ?? $method->logo,
            'description'    => $data['description'] ?? null,
            'bank_name'      => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_name'   => $data['account_name'] ?? null,
            'status'         => $data['status'] ?? $method->status,
        ]);

        return response()->json(['message' => 'Payment method updated!', 'data' => $method]);
    }

    public function destroy(int $id)
    {
        $method = PaymentMethod::findOrFail($id);

        if ($method->orders()->exists()) {
            return response()->json([
                'message' => 'This payment method cannot be deleted because it is linked to existing orders.',
            ], 409);
        }

        $method->delete();
        return response()->json(['message' => 'Payment method deleted!']);
    }
}