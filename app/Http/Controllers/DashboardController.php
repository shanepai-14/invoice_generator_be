<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function getStats(): JsonResponse
    {
        // Get current month stats
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        $totalSales = Invoice::whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->sum('total_amount');

        $totalReceived = Payment::whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');

        $totalOutstanding = Invoice::where('invoice_status', '!=', 'paid')
            ->sum('outstanding_balance');

        $recentInvoices = Invoice::with(['customer'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // Monthly sales for chart
        $monthlySales = Invoice::selectRaw('MONTH(created_at) as month, SUM(total_amount) as total')
            ->whereYear('created_at', $currentYear)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => Carbon::create()->month($item->month)->format('M'),
                    'amount' => $item->total
                ];
            });

        // Status distribution
        $invoiceStatus = Invoice::selectRaw('invoice_status, COUNT(*) as count')
            ->groupBy('invoice_status')
            ->get()
            ->pluck('count', 'invoice_status');

        return response()->json([
            'summary' => [
                'total_sales' => $totalSales,
                'total_received' => $totalReceived,
                'total_outstanding' => $totalOutstanding,
                'total_customers' => Customer::count(),
                'total_products' => Product::count(),
                'total_invoices' => Invoice::count()
            ],
            'recent_invoices' => $recentInvoices,
            'monthly_sales' => $monthlySales,
            'invoice_status' => $invoiceStatus
        ]);
    }
}