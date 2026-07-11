<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/view-excel', function () {
    $date = now()->format('Y-m-d');
    $fileName = "receipts-{$date}.xlsx";
    $path = "receipts/{$fileName}";

    if (Storage::exists($path)) {
        return Storage::download($path);
    }

    return response()->json([
        'status' => 'error',
        'message' => "Today's excel report has not been generated yet."
    ], 404);
});