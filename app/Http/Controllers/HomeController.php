<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use App\Services\Ledger\CashFlow;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;

class HomeController extends Controller
{

    public function index() {
        $sales = Sale::completed()->sum('total_amount');
        $sale_returns = SaleReturn::completed()->sum('total_amount');
        $purchase_returns = PurchaseReturn::completed()->sum('total_amount');
        $product_costs = 0;

        foreach (Sale::completed()->with('saleDetails')->get() as $sale) {
            foreach ($sale->saleDetails as $saleDetail) {
                if (!is_null($saleDetail->product)) {
                    $product_costs += $saleDetail->product->product_cost * $saleDetail->quantity;
                }
            }
        }

        $revenue = ($sales - $sale_returns) / 100;
        $profit = $revenue - $product_costs;

        return view('home', [
            'revenue'          => $revenue,
            'sale_returns'     => $sale_returns / 100,
            'purchase_returns' => $purchase_returns / 100,
            'profit'           => $profit
        ]);
    }


    public function currentMonthChart() {
        abort_if(!request()->ajax(), 404);

        $currentMonthSales = Sale::where('status', 'Completed')->whereMonth('date', date('m'))
                ->whereYear('date', date('Y'))
                ->sum('total_amount') / 100;
        $currentMonthPurchases = Purchase::where('status', 'Completed')->whereMonth('date', date('m'))
                ->whereYear('date', date('Y'))
                ->sum('total_amount') / 100;
        $currentMonthExpenses = Expense::whereMonth('date', date('m'))
                ->whereYear('date', date('Y'))
                ->sum('amount') / 100;

        return response()->json([
            'sales'     => $currentMonthSales,
            'purchases' => $currentMonthPurchases,
            'expenses'  => $currentMonthExpenses
        ]);
    }


    public function salesPurchasesChart() {
        abort_if(!request()->ajax(), 404);

        $sales = $this->salesChartData();
        $purchases = $this->purchasesChartData();

        return response()->json(['sales' => $sales, 'purchases' => $purchases]);
    }


    public function paymentChart() {
        abort_if(!request()->ajax(), 404);

        $months = collect();
        foreach (range(-11, 0) as $i) {
            $months->put(Carbon::now()->addMonths($i)->format('m-Y'), 0);
        }

        $date_range = Carbon::today()->subYear()->format('Y-m-d');
        $cash = app(CashFlow::class);

        $received = $months->all();
        $sent = $months->all();

        // Real cash movements only (credit invoices are not cash); grouped in PHP so it runs on MySQL and SQLite.
        foreach ($cash->received($date_range) as $row) {
            $key = Carbon::parse($row->date)->format('m-Y');
            if (array_key_exists($key, $received)) {
                $received[$key] += $row->amount;
            }
        }

        foreach ($cash->sent($date_range) as $row) {
            $key = Carbon::parse($row->date)->format('m-Y');
            if (array_key_exists($key, $sent)) {
                $sent[$key] += $row->amount;
            }
        }

        foreach (Expense::where('date', '>=', $date_range)->get(['date', 'amount']) as $expense) {
            $key = Carbon::parse($expense->date)->format('m-Y');
            if (array_key_exists($key, $sent)) {
                $sent[$key] += $expense->getRawOriginal('amount');
            }
        }

        return response()->json([
            'payment_sent' => array_map(fn ($v) => $v / 100, array_values($sent)),
            'payment_received' => array_map(fn ($v) => $v / 100, array_values($received)),
            'months' => array_keys($received),
        ]);
    }

    public function salesChartData() {
        $dates = collect();
        foreach (range(-6, 0) as $i) {
            $date = Carbon::now()->addDays($i)->format('d-m-y');
            $dates->put($date, 0);
        }

        $date_range = Carbon::today()->subDays(6);

        $sales = Sale::where('status', 'Completed')
            ->where('date', '>=', $date_range)
            ->get(['date', 'total_amount'])
            ->groupBy(fn ($row) => Carbon::parse($row->date)->format('d-m-y'))
            ->map(fn ($rows) => $rows->sum(fn ($row) => $row->getRawOriginal('total_amount')));

        $dates = $dates->merge($sales);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value / 100;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);
    }


    public function purchasesChartData() {
        $dates = collect();
        foreach (range(-6, 0) as $i) {
            $date = Carbon::now()->addDays($i)->format('d-m-y');
            $dates->put($date, 0);
        }

        $date_range = Carbon::today()->subDays(6);

        $purchases = Purchase::where('status', 'Completed')
            ->where('date', '>=', $date_range)
            ->get(['date', 'total_amount'])
            ->groupBy(fn ($row) => Carbon::parse($row->date)->format('d-m-y'))
            ->map(fn ($rows) => $rows->sum(fn ($row) => $row->getRawOriginal('total_amount')));

        $dates = $dates->merge($purchases);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value / 100;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);

    }
}
