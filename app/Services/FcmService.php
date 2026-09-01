<?php

namespace App\Services;

use App\Models\User;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\Cheque;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Notify Directors about a newly posted Expense with its Color Tier.
     */
    public static function notifyExpenseCreated(Expense $expense): void
    {
        $tierInfo = Expense::resolveTier((float) $expense->amount);
        $amountFormatted = 'Rs. ' . number_format($expense->amount, 0);

        $colorEmoji = match ($tierInfo['color']) {
            'green' => '🟢',
            'blue' => '🔵',
            'orange' => '🟠',
            'red' => '🔴',
            default => '⚪',
        };

        $title = "{$colorEmoji} New Expense: {$amountFormatted}";
        $body = "{$expense->title} ({$expense->category}) logged by {$expense->creator->name}. Tier: {$tierInfo['label']}";

        self::notifyDirectors($title, $body, [
            'type' => 'expense_created',
            'expense_id' => (string) $expense->id,
            'amount' => (string) $expense->amount,
            'tier' => (string) $tierInfo['tier'],
            'color' => $tierInfo['color'],
        ]);
    }

    /**
     * Notify Directors about a newly received Government Grant / Tranche.
     */
    public static function notifyGrantReceived(Grant $grant): void
    {
        $amountFormatted = 'Rs. ' . number_format($grant->amount, 0);
        $title = "🏛️ Govt Grant Credited: {$amountFormatted}";
        $body = "{$grant->title} received in {$grant->bank_account} on {$grant->deposit_date->format('d-M-Y')}.";

        self::notifyDirectors($title, $body, [
            'type' => 'grant_received',
            'grant_id' => (string) $grant->id,
            'amount' => (string) $grant->amount,
        ]);
    }

    /**
     * Notify Directors when a Cheque is Cashed Out from the bank.
     */
    public static function notifyChequeCashed(Cheque $cheque): void
    {
        $amountFormatted = 'Rs. ' . number_format($cheque->amount, 0);
        $tierInfo = Cheque::resolveTier((float) $cheque->amount);

        $title = "🧾 Cheque Cashed: {$cheque->cheque_no} ({$amountFormatted})";
        $body = "Paid to {$cheque->beneficiary} for {$cheque->purpose}. Cashed on {$cheque->cashed_date?->format('d-M-Y')}.";

        self::notifyDirectors($title, $body, [
            'type' => 'cheque_cashed',
            'cheque_id' => (string) $cheque->id,
            'cheque_no' => $cheque->cheque_no,
            'amount' => (string) $cheque->amount,
            'tier' => (string) $tierInfo['tier'],
        ]);
    }

    /**
     * Notify Directors when a Director Capital Deposit or Withdrawal is recorded.
     */
    public static function notifyDirectorTransaction(\App\Models\DirectorTransaction $transaction): void
    {
        $directorName = $transaction->director->name;
        $amountFormatted = 'Rs. ' . number_format($transaction->amount, 0);
        $dateFormatted = $transaction->date ? $transaction->date->format('d-M-Y') : date('d-M-Y');
        $creatorName = $transaction->creator->name ?? 'System';

        if ($transaction->type === 'capital_injection') {
            $title = "💎 Capital Injected: {$amountFormatted}";
            $body = "Director {$directorName} deposited {$amountFormatted} on {$dateFormatted} ({$transaction->payment_method}). Logged by {$creatorName}.";
        } elseif ($transaction->type === 'withdrawal') {
            $title = "💸 Director Withdrawal: {$amountFormatted}";
            $body = "Director {$directorName} withdrew {$amountFormatted} on {$dateFormatted} ({$transaction->payment_method}). Logged by {$creatorName}.";
        } else {
            $title = "📈 Profit Credited: {$amountFormatted}";
            $body = "Director {$directorName} credited {$amountFormatted} for {$transaction->period_month}.";
        }

        self::notifyDirectors($title, $body, [
            'type' => 'director_transaction',
            'transaction_id' => (string) $transaction->id,
            'director_id' => (string) $transaction->director_id,
            'amount' => (string) $transaction->amount,
            'txn_type' => $transaction->type,
        ]);
    }

    /**
     * Notify Directors when Monthly Profit Distribution is executed.
     */
    public static function notifyProfitDistribution(\App\Models\ProfitDistribution $distribution): void
    {
        $poolFormatted = 'Rs. ' . number_format($distribution->total_profit_pool, 0);
        $distributorName = $distribution->distributor->name ?? 'Board';

        $title = "🥧 Monthly Profit Distributed ({$distribution->period_month})";
        $body = "Total Surplus Pool of {$poolFormatted} distributed across all partners according to equity shares by {$distributorName}.";

        self::notifyDirectors($title, $body, [
            'type' => 'profit_distribution',
            'distribution_id' => (string) $distribution->id,
            'period_month' => $distribution->period_month,
            'total_pool' => (string) $distribution->total_profit_pool,
        ]);
    }

    /**
     * Send push notification to all active Directors, Chairman, and Admins.
     */
    public static function notifyDirectors(string $title, string $body, array $data = []): void
    {
        $directors = User::whereIn('role', ['director', 'chairman', 'admin', 'super_admin'])
            ->whereNotNull('fcm_token')
            ->get();

        foreach ($directors as $director) {
            self::sendToToken($director, $title, $body, $data);
        }
    }

    /**
     * Send push notification to a specific user.
     */
    public static function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (!$user->fcm_token) {
            return;
        }

        self::sendToToken($user, $title, $body, $data);
    }

    /**
     * Dispatch FCM payload with stale token cleanup.
     */
    private static function sendToToken(User $user, string $title, string $body, array $data = []): void
    {
        $serverKey = env('FCM_SERVER_KEY');

        // If FCM server key is not configured locally, log and return safely
        if (!$serverKey) {
            Log::info("FCM (Simulated) to {$user->email}: [{$title}] {$body}", $data);
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $user->fcm_token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                ],
                'data' => $data,
            ]);

            if ($response->failed() || str_contains($response->body(), 'NotRegistered') || str_contains($response->body(), 'InvalidRegistration')) {
                Log::warning("Pruning invalid FCM token for user {$user->id}");
                $user->update(['fcm_token' => null]);
            }
        } catch (\Throwable $e) {
            Log::error("FCM dispatch error for user {$user->id}: " . $e->getMessage());
        }
    }
}
