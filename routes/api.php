<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Customer routes
Route::apiResource('customers', CustomerController::class);

// Product routes
Route::apiResource('products', ProductController::class);

// Invoice routes
Route::apiResource('invoices', InvoiceController::class);

// Payment routes
Route::post('payments', [PaymentController::class, 'store']);
Route::get('/payments', [PaymentController::class, 'index']);
Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);