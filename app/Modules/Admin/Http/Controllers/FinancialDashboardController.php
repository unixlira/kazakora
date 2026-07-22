<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\CashFlowEntry;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FinancialDashboardController extends Controller
{
    public function index(): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();
        $start14 = Carbon::today()->subDays(13);

        $incomeAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->sum('amount');
        $expenseAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->sum('amount');

        $incomeMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->where('entry_date', '>=', $startOfMonth)->sum('amount');
        $expenseMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->where('entry_date', '>=', $startOfMonth)->sum('amount');

        return Inertia::render('Admin/Financeiro/Dashboard', [
            'summary' => [
                'balance' => $incomeAllTime - $expenseAllTime,
                'incomeMonth' => $incomeMonth,
                'expenseMonth' => $expenseMonth,
                'profitMonth' => $incomeMonth - $expenseMonth,
                'salesRevenue' => (float) Order::query()->whereNot('status', Order::STATUS_CANCELLED)->sum('total'),
            ],
            'cashFlowSeries' => $this->cashFlowSeries($start14),
        ]);
    }

    private function cashFlowSeries(Carbon $start): array
    {
        $income = CashFlowEntry::query()
            ->selectRaw('entry_date as date, SUM(amount) as total')
            ->where('type', CashFlowEntry::TYPE_INCOME)
            ->where('entry_date', '>=', $start)
            ->groupBy('entry_date')
            ->pluck('total', 'date');

        $expense = CashFlowEntry::query()
            ->selectRaw('entry_date as date, SUM(amount) as total')
            ->where('type', CashFlowEntry::TYPE_EXPENSE)
            ->where('entry_date', '>=', $start)
            ->groupBy('entry_date')
            ->pluck('total', 'date');

        $series = [];

        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'income' => (float) ($income[$key] ?? 0),
                'expense' => (float) ($expense[$key] ?? 0),
            ];
        }

        return $series;
    }
}
