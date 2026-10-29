<?php

namespace App\Http\Controllers;

use App\Exceptions\TradingException;
use App\Http\Requests\OpenPositionRequest;
use App\Models\PriceHistory;
use App\Models\TradingEvent;
use App\Models\TradingPosition;
use App\Services\DepositService;
use App\Services\TradingService;
use App\Services\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TradingService $trading,
        private readonly WithdrawalService $withdrawals,
        private readonly DepositService $deposits,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $account = $this->trading->accountFor($user);

        $symbols = (array) config('trading.symbols', []);
        $symbolNames = array_keys($symbols);
        $defaultSymbol = (string) config('trading.default_symbol', 'BTC');

        $selected = strtoupper((string) $request->query('symbol', $defaultSymbol));

        if (! in_array($selected, $symbolNames, true)) {
            $selected = $defaultSymbol;
        }

        $days = (int) config('trading.history_days', 7);

        $history = PriceHistory::query()
            ->where('symbol', $selected)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderBy('recorded_at')
            ->get(['price', 'recorded_at']);

        $chartData = [
            'symbol' => $selected,
            'labels' => $history->map(fn (PriceHistory $row) => $row->recorded_at->format('d.m H:i'))->all(),
            'prices' => $history->map(fn (PriceHistory $row) => round((float) $row->price, 2))->all(),
        ];

        $latestPrices = $this->trading->latestPrices($symbolNames);

        $openPositions = $user->tradingPositions()
            ->where('status', TradingPosition::STATUS_OPEN)
            ->latest()
            ->get();

        $unrealizedPnl = $openPositions->sum(
            fn (TradingPosition $position) => $position->pnl($latestPrices[$position->symbol] ?? null) ?? 0.0
        );

        $changePercent = null;

        if ($history->count() >= 2) {
            $first = (float) $history->first()->price;
            $last = (float) $history->last()->price;

            if ($first > 0) {
                $changePercent = round((($last - $first) / $first) * 100, 2);
            }
        }

        $recentEvents = TradingEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_type', [
                TradingEvent::TYPE_DEPOSIT,
                TradingEvent::TYPE_POSITION_CLOSED,
                TradingEvent::TYPE_BALANCE_ADJUSTMENT,
            ])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('dashboard', [
            'account' => $account,
            'symbols' => $symbols,
            'selectedSymbol' => $selected,
            'chartData' => $chartData,
            'latestPrices' => $latestPrices,
            'openPositions' => $openPositions,
            'unrealizedPnl' => $unrealizedPnl,
            'changePercent' => $changePercent,
            'recentEvents' => $recentEvents,
            'openDeposit' => $this->deposits->openRequestFor($user),
            'depositCards' => $this->deposits->cardsFor($user),
            'depositHistory' => $this->deposits->historyFor($user),
            'openWithdrawal' => $this->withdrawals->openRequestFor($user),
            'withdrawalCards' => $this->withdrawals->cardsFor($user),
            'withdrawalHistory' => $this->withdrawals->historyFor($user),
        ]);
    }

    public function openPosition(OpenPositionRequest $request): RedirectResponse
    {
        try {
            $this->trading->openPosition(
                $request->user(),
                (string) $request->validated('symbol'),
                (string) $request->validated('side'),
                (float) $request->validated('amount'),
                (string) ($request->validated('size_unit') ?: TradingService::SIZE_UNIT_COIN),
            );
        } catch (TradingException $exception) {
            return back()
                ->withErrors(['trade' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Позиция открыта.');
    }

    public function closePosition(Request $request, TradingPosition $position): RedirectResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);

        try {
            $this->trading->closePosition($position);
        } catch (TradingException $exception) {
            return back()->withErrors(['trade' => $exception->getMessage()]);
        }

        return back()->with('status', 'Позиция закрыта, результат зачислен на баланс.');
    }
}
