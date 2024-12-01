<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;



class PaymentController extends Controller
{

    public function index(): JsonResponse
    {
        $payments = Payment::with(['invoice' => function ($query) {
            $query->with('customer'); // Include customer details
        }])
        ->orderBy('date', 'desc')
        ->get()
        ->map(function ($payment) {
            // Format payment data with invoice and customer details
            return [
                'id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'amount' => $payment->amount,
                'date' => $payment->date,
                'invoice' => [
                    'invoice_number' => $payment->invoice->invoice_number,
                    'customer_name' => $payment->invoice->customer->name,
                    'total_amount' => $payment->invoice->total_amount,
                    'outstanding_balance' => $payment->invoice->outstanding_balance,
                    'invoice_status' => $payment->invoice->invoice_status
                ]
            ];
        });

        return response()->json($payments);
    }
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date'
        ]);

        // Start a transaction to ensure data consistency
        return \DB::transaction(function () use ($validated) {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            
            // Validate payment amount doesn't exceed outstanding balance
            if ($validated['amount'] > $invoice->outstanding_balance) {
                return response()->json([
                    'message' => 'Payment amount cannot exceed outstanding balance'
                ], 422);
            }

            // Create payment
            $payment = Payment::create($validated);

            // Update invoice
            $newTotalPaid = $invoice->total_paid + $validated['amount'];
            $newOutstandingBalance = $invoice->total_amount - $newTotalPaid;
            
            $invoice->update([
                'total_paid' => $newTotalPaid,
                'outstanding_balance' => $newOutstandingBalance,
                'invoice_status' => $newOutstandingBalance <= 0 ? 'paid' : 'partially_paid'
            ]);

            return response()->json($payment, 201);
        });
    }
}