<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;





class InvoiceController extends Controller
{
    private function generateInvoiceNumber(): string
    {
        $lastInvoice = Invoice::orderBy('id', 'desc')->first();
        
        if (!$lastInvoice) {
            return 'INV-0000001';
        }

        // Extract the numeric part and increment
        $lastNumber = (int) substr($lastInvoice->invoice_number, 4);
        $newNumber = $lastNumber + 1;
        
        // Format to 7 digits with leading zeros
        return 'INV-' . str_pad($newNumber, 7, '0', STR_PAD_LEFT);
    }
    public function index(): JsonResponse
    {
        $invoices = Invoice::with(['customer', 'items.product', 'payments'])->get();
        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'due_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1'
        ]);

        
        // Start a database transaction
        return \DB::transaction(function () use ($validated) {
            // Create invoice
            $invoiceNumber = $this->generateInvoiceNumber();
            $invoice = Invoice::create([
                'customer_id' => $validated['customer_id'],
                  'invoice_number' => $invoiceNumber,
                'due_date' => $validated['due_date'],
                'invoice_status' => 'draft',
                'total_amount' => 0,
                'total_paid' => 0,
                'outstanding_balance' => 0
            ]);

            $totalAmount = 0;

            // Create invoice items
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                $itemTotal = $product->price * $item['quantity'];
                $totalAmount += $itemTotal;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'total' => $itemTotal
                ]);
            }

            // Update invoice totals
            $invoice->update([
                'total_amount' => $totalAmount,
                'outstanding_balance' => $totalAmount
            ]);

            return response()->json($invoice->load('items.product'), 201);
        });
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['customer', 'items.product', 'payments']));
    }

    // public function update(Request $request, Invoice $invoice): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'invoice_status' => 'sometimes|in:draft,sent,paid,partially_paid,overdue',
    //         'due_date' => 'sometimes|date'
    //     ]);

    //     $invoice->update($validated);
    //     return response()->json($invoice);
    // }

    public function update(Request $request, Invoice $invoice): JsonResponse
{
    $validated = $request->validate([
        'invoice_status' => 'sometimes|in:draft,sent,paid,partially_paid,overdue',
        'due_date' => 'sometimes|date',
        'customer_id' => 'sometimes|exists:customers,id',
        'items' => 'sometimes|array'
    ]);

    // If items are being updated
    if (isset($validated['items'])) {
        return \DB::transaction(function () use ($request, $invoice, $validated) {
            // Validate all items
            $request->validate([
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.id' => 'sometimes|exists:invoice_items,id'
            ]);

            // Get current item IDs
            $currentItemIds = $invoice->items->pluck('id')->toArray();
            
            // Track which items have been processed
            $processedItemIds = [];
            
            $totalAmount = 0;

            // Update or create items
            foreach ($validated['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);
                $itemTotal = $product->price * $itemData['quantity'];
                $totalAmount += $itemTotal;

                if (isset($itemData['id'])) {
                    // Update existing item
                    $item = InvoiceItem::find($itemData['id']);
                    if ($item && $item->invoice_id == $invoice->id) {
                        $item->update([
                            'product_id' => $itemData['product_id'],
                            'quantity' => $itemData['quantity'],
                            'total' => $itemTotal
                        ]);
                        $processedItemIds[] = $item->id;
                    }
                } else {
                    // Create new item
                    $item = InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $itemData['product_id'],
                        'quantity' => $itemData['quantity'],
                        'total' => $itemTotal
                    ]);
                    $processedItemIds[] = $item->id;
                }
            }

            // Delete items that weren't in the request
            $itemsToDelete = array_diff($currentItemIds, $processedItemIds);
            InvoiceItem::whereIn('id', $itemsToDelete)->delete();

            // Update invoice data
            $invoice->update([
                'total_amount' => $totalAmount,
                'outstanding_balance' => $totalAmount - $invoice->total_paid
            ]);

            // Update other invoice fields if provided
            if (isset($validated['invoice_status'])) {
                $invoice->invoice_status = $validated['invoice_status'];
            }
            
            if (isset($validated['due_date'])) {
                $invoice->due_date = $validated['due_date'];
            }
            
            if (isset($validated['customer_id'])) {
                $invoice->customer_id = $validated['customer_id'];
            }
            
            $invoice->save();

            return response()->json($invoice->load(['customer', 'items.product', 'payments']));
        });
    } else {
        // Simple update without changing items
        $invoice->update($validated);
        return response()->json($invoice);
    }
}

    public function destroy(Invoice $invoice): JsonResponse
    {
        $invoice->delete();
        return response()->json(null, 204);
    }
}

