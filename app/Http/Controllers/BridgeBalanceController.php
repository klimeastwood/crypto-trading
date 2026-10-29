<?php

namespace App\Http\Controllers;

use App\Exceptions\TradingException;
use App\Http\Requests\AdjustBalanceRequest;
use App\Http\Requests\AdjustDepositRequest;
use App\Http\Requests\ReviewWithdrawalRequest;
use App\Models\User;
use App\Services\TradingService;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;

/**
 * Bridge called by Krayin CRM to change a trader balance by hand, to credit an
 * approved deposit or to record the decision on a payout.
 *
 * Everything money related stays in TradingService: this controller only
 * translates an authenticated request into a service call and back.
 */
class BridgeBalanceController extends Controller
{
    public function __construct(
        protected TradingService $trading,
        protected WithdrawalService $withdrawals,
    ) {}

    public function adjust(AdjustBalanceRequest $request): JsonResponse
    {
        try {
            $user = User::query()->findOrFail($request->integer('user_id'));

            $result = $this->trading->adjustBalance(
                $user,
                (float) $request->input('amount'),
                (string) $request->input('reason'),
                $request->filled('adjustment_id') ? $request->integer('adjustment_id') : null,
                $request->input('operator_name'),
            );
        } catch (TradingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'user_id' => $user->id,
            'account_id' => $result['account']->id,
            'event_id' => $result['event']->id,
            'amount' => round((float) $request->input('amount'), 2),
            'balance_before' => round($result['balance_before'], 2),
            'balance_after' => round($result['balance_after'], 2),
            'applied' => $result['applied'],
        ]);
    }

    /**
     * Credit a deposit the CRM operator has approved.
     *
     * The balance and the `deposit` event are written by TradingService, so the
     * client sees the money in the cabinet and in the CRM timeline. A request
     * delivered twice answers with `applied = false` and the balances of the
     * first delivery instead of crediting again.
     */
    public function deposit(AdjustDepositRequest $request): JsonResponse
    {
        try {
            $user = User::query()->findOrFail($request->integer('user_id'));

            $result = $this->trading->creditDeposit(
                $user,
                (float) $request->input('amount'),
                (string) $request->input('reference'),
            );
        } catch (TradingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'user_id' => $user->id,
            'account_id' => $result['account']->id,
            'event_id' => $result['event']?->id,
            'amount' => round((float) $request->input('amount'), 2),
            'balance_before' => round($result['balance_before'], 2),
            'balance_after' => round($result['balance_after'], 2),
            'applied' => $result['applied'],
        ]);
    }

    /**
     * Record the payout decision made in the CRM.
     *
     * Approval alone moves no money: `approved` closes the request for the
     * client and the back office pays it out. The CRM then sends `paid` once
     * the money has actually left the account, and that call takes the amount
     * out of the trader's balance in the same transaction — an approved payout
     * the balance can no longer cover is refused with 422 and stays approved.
     * A replay of the same decision is answered, not refused, because the CRM
     * cannot know whether its first call reached us; it reports
     * `applied = false` and no balance movement.
     */
    public function withdrawal(ReviewWithdrawalRequest $request): JsonResponse
    {
        try {
            $result = $this->withdrawals->review(
                $request->integer('request_id'),
                (string) $request->input('status'),
                $request->input('note'),
                $request->filled('reviewer_id') ? $request->integer('reviewer_id') : null,
            );
        } catch (TradingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'request_id' => $result['request']->id,
            'status' => $result['request']->status,
            'is_approved' => (bool) $result['request']->is_approved,
            'reviewed_at' => $result['request']->reviewed_at?->toIso8601String(),
            'balance_before' => $result['balance_before'] === null ? null : round($result['balance_before'], 2),
            'balance_after' => $result['balance_after'] === null ? null : round($result['balance_after'], 2),
            'applied' => $result['applied'],
        ]);
    }
}
