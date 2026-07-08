<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentMethod;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return response()->json(PaymentMethod::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $method = PaymentMethod::create([
            'name'           => $request->name,
            'icon'           => $request->icon ?? '💳',
            'logo'           => $request->logo ?? null,
            'description'    => $request->description ?? null,
            'bank_name'      => $request->bank_name ?? null,
            'account_number' => $request->account_number ?? null,
            'account_name'   => $request->account_name ?? null,
            'status'         => $request->status ?? true,
        ]);

        return response()->json(['message' => 'Payment method created!', 'data' => $method], 201);
    }

    public function update(Request $request, int $id)
    {
        $method = PaymentMethod::findOrFail($id);

        $request->validate([
            'name' => 'required|string',
        ]);

        $method->update([
            'name'           => $request->name,
            'icon'           => $request->icon ?? $method->icon,
            'logo'           => $request->logo ?? $method->logo,
            'description'    => $request->description ?? null,
            'bank_name'      => $request->bank_name ?? null,
            'account_number' => $request->account_number ?? null,
            'account_name'   => $request->account_name ?? null,
            'status'         => $request->status ?? $method->status,
        ]);

        return response()->json(['message' => 'Payment method updated!', 'data' => $method]);
    }

    public function destroy(int $id)
    {
        PaymentMethod::findOrFail($id)->delete();
        return response()->json(['message' => 'Payment method deleted!']);
    }
}