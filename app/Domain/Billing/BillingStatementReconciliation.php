<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingBankAccount;
use App\Models\BillingPayment;
use App\Models\BillingPaymentRefund;
use App\Models\BillingReconciliationBatch;
use App\Models\BillingReconciliationLine;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingStatementReconciliation
{
    private const HEADER = ['transaction_date', 'reference', 'description', 'direction', 'amount', 'currency'];

    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array{campus_id?: int|string|null, account_code: string, display_name: string, currency: string} $data */
    public function createAccount(array $data, User $actor): BillingBankAccount
    {
        $membership = $this->manager();
        $campusId = $data['campus_id'] ?? null;
        if ($campusId !== null && $membership->campus_id !== null && (int) $campusId !== (int) $membership->campus_id) {
            abort(404);
        }
        if ($membership->campus_id !== null && $campusId === null) {
            abort(404);
        }

        return DB::transaction(function () use ($data, $actor, $membership, $campusId): BillingBankAccount {
            $account = BillingBankAccount::query()->create([
                'institution_id' => $membership->institution_id, 'campus_id' => $campusId,
                'account_code' => Str::upper(trim($data['account_code'])), 'display_name' => trim($data['display_name']),
                'currency' => Str::upper($data['currency']), 'status' => 'active', 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('billing.bank_account.created', $actor, (int) $membership->tenant_id, $account, ['currency' => $account->currency, 'campus_id' => $campusId]);

            return $account;
        }, 3);
    }

    public function import(BillingBankAccount $account, UploadedFile $file, User $actor): BillingReconciliationBatch
    {
        $membership = $this->manager();
        $this->assertAccountScope($account, $membership);
        if ($account->status !== 'active' || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['statement' => 'Choose an active account and a CSV file no larger than 5 MiB.']);
        }
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['statement' => 'The uploaded statement could not be read.']);
        }
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['statement' => 'The uploaded statement could not be fingerprinted.']);
        }
        $rows = $this->readRows($path, (string) $account->currency);
        if ($rows === []) {
            throw ValidationException::withMessages(['statement' => 'The statement contains no transaction rows.']);
        }
        $dates = array_column($rows, 'date');
        $originalName = Str::limit(basename((string) $file->getClientOriginalName()), 180, '');

        return DB::transaction(function () use ($account, $actor, $membership, $hash, $rows, $dates, $originalName): BillingReconciliationBatch {
            $lockedAccount = BillingBankAccount::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($account->getKey());
            $this->assertAccountScope($lockedAccount, $membership);
            if (BillingReconciliationBatch::query()->where('tenant_id', $membership->tenant_id)->where('bank_account_id', $lockedAccount->getKey())->where('statement_sha256', $hash)->exists()) {
                throw new ConflictHttpException('This exact statement file was already imported for the selected bank account.');
            }
            $batch = BillingReconciliationBatch::query()->create([
                'institution_id' => $lockedAccount->institution_id, 'campus_id' => $lockedAccount->campus_id,
                'bank_account_id' => $lockedAccount->getKey(), 'batch_number' => 'REC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'statement_sha256' => $hash, 'original_filename' => $originalName, 'currency' => $lockedAccount->currency,
                'period_start' => min($dates), 'period_end' => max($dates), 'status' => 'review', 'line_count' => count($rows), 'created_by' => $actor->getKey(),
            ]);
            foreach ($rows as $index => $row) {
                $suggestion = $this->suggest($lockedAccount, $row);
                BillingReconciliationLine::query()->create([
                    'batch_id' => $batch->getKey(), 'row_number' => $index + 2, 'row_fingerprint' => $row['fingerprint'],
                    'transaction_date' => $row['date'], 'reference' => $row['reference'], 'description' => $row['description'],
                    'direction' => $row['direction'], 'amount' => BillingMoney::fromMinor($row['amount_minor']), 'currency' => $lockedAccount->currency,
                    'match_status' => $suggestion['status'], 'suggested_payment_id' => $suggestion['payment_id'], 'suggested_refund_id' => $suggestion['refund_id'],
                ]);
            }
            $this->audit->record('billing.reconciliation.imported', $actor, (int) $membership->tenant_id, $batch, ['line_count' => count($rows), 'currency' => $batch->currency, 'statement_sha256' => $hash]);

            return $batch->load('lines');
        }, 3);
    }

    public function matchLine(int $lineId, ?int $paymentId, ?int $refundId, User $actor): BillingReconciliationLine
    {
        $membership = $this->reviewer();

        return DB::transaction(function () use ($lineId, $paymentId, $refundId, $actor, $membership): BillingReconciliationLine {
            $line = BillingReconciliationLine::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($lineId);
            $batch = BillingReconciliationBatch::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($line->batch_id);
            $this->assertBatchScope($batch, $membership);
            if ($batch->status !== 'review' || $line->match_status === 'matched' || $line->match_status === 'excluded' || (($paymentId === null) === ($refundId === null))) {
                throw new ConflictHttpException('Only an unresolved line in an open review batch can receive one explicit match.');
            }
            if ($paymentId !== null) {
                if ($line->direction !== 'credit') {
                    throw ValidationException::withMessages(['payment_id' => 'Credit statement rows can only match payment records.']);
                }
                $record = BillingPayment::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($paymentId);
                $this->assertPaymentScope($record, $batch, $membership);
                if (! in_array($record->method, ['bank_transfer', 'cash'], true) || $record->reference !== $line->reference
                    || $record->currency !== $line->currency || BillingMoney::toMinor((string) $record->amount) !== BillingMoney::toMinor((string) $line->amount)) {
                    throw ValidationException::withMessages(['payment_id' => 'The payment must match this statement line reference, amount, currency, institution and campus exactly.']);
                }
                if (BillingReconciliationLine::query()->where('tenant_id', $membership->tenant_id)->where('matched_payment_id', $paymentId)->exists()) {
                    throw new ConflictHttpException('This payment has already been reconciled against a statement line.');
                }
                $line->forceFill(['match_status' => 'matched', 'matched_payment_id' => $paymentId, 'matched_refund_id' => null,
                    'suggested_payment_id' => null, 'suggested_refund_id' => null, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()])->save();
            } else {
                if ($line->direction !== 'debit') {
                    throw ValidationException::withMessages(['refund_id' => 'Debit statement rows can only match executed refunds.']);
                }
                $record = BillingPaymentRefund::query()->where('tenant_id', $membership->tenant_id)->where('status', 'refunded')->lockForUpdate()->findOrFail($refundId);
                $payment = $record->payment;
                $this->assertPaymentScope($payment, $batch, $membership);
                if ($record->payout_reference !== $line->reference || $record->currency !== $line->currency
                    || BillingMoney::toMinor((string) $record->amount) !== BillingMoney::toMinor((string) $line->amount)) {
                    throw ValidationException::withMessages(['refund_id' => 'The executed refund must match this statement line reference, amount, currency, institution and campus exactly.']);
                }
                if (BillingReconciliationLine::query()->where('tenant_id', $membership->tenant_id)->where('matched_refund_id', $refundId)->exists()) {
                    throw new ConflictHttpException('This refund has already been reconciled against a statement line.');
                }
                $line->forceFill(['match_status' => 'matched', 'matched_refund_id' => $refundId, 'matched_payment_id' => null,
                    'suggested_payment_id' => null, 'suggested_refund_id' => null, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()])->save();
            }
            $this->audit->record('billing.reconciliation.line_matched', $actor, (int) $membership->tenant_id, $line, ['batch_id' => (int) $batch->getKey(), 'direction' => $line->direction]);

            return $line;
        }, 3);
    }

    public function excludeLine(int $lineId, string $reason, User $actor): BillingReconciliationLine
    {
        $membership = $this->reviewer();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record a reason for excluding the statement row.']);
        }

        return DB::transaction(function () use ($lineId, $reason, $actor, $membership): BillingReconciliationLine {
            $line = BillingReconciliationLine::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($lineId);
            $batch = BillingReconciliationBatch::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($line->batch_id);
            $this->assertBatchScope($batch, $membership);
            if ($batch->status !== 'review' || ! in_array($line->match_status, ['unmatched', 'suggested'], true)) {
                throw new ConflictHttpException('Only unresolved statement rows in an open review batch can be excluded.');
            }
            $line->forceFill(['match_status' => 'excluded', 'suggested_payment_id' => null, 'suggested_refund_id' => null,
                'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => mb_substr(trim($reason), 0, 500)])->save();
            $this->audit->record('billing.reconciliation.line_excluded', $actor, (int) $membership->tenant_id, $line, ['batch_id' => (int) $batch->getKey(), 'reason' => mb_substr(trim($reason), 0, 500)]);

            return $line;
        }, 3);
    }

    public function closeBatch(int $batchId, User $actor): BillingReconciliationBatch
    {
        $membership = $this->reviewer();

        return DB::transaction(function () use ($batchId, $actor, $membership): BillingReconciliationBatch {
            $batch = BillingReconciliationBatch::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($batchId);
            $this->assertBatchScope($batch, $membership);
            $unresolved = BillingReconciliationLine::query()->where('tenant_id', $membership->tenant_id)->where('batch_id', $batchId)->whereNotIn('match_status', ['matched', 'excluded'])->exists();
            if ($batch->status !== 'review' || $unresolved || (int) $batch->line_count < 1) {
                throw new ConflictHttpException('A batch can close only once every imported line is matched or explicitly excluded.');
            }
            $batch->forceFill(['status' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now()])->save();
            $this->audit->record('billing.reconciliation.closed', $actor, (int) $membership->tenant_id, $batch, ['line_count' => (int) $batch->line_count]);

            return $batch;
        }, 3);
    }

    /** @return list<array{date:string,reference:string,description:?string,direction:string,amount_minor:int,fingerprint:string}> */
    private function readRows(string $path, string $currency): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['statement' => 'The statement CSV could not be opened.']);
        }
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($header)) {
                throw ValidationException::withMessages(['statement' => 'The CSV file is empty.']);
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            if ($header !== self::HEADER) {
                throw ValidationException::withMessages(['statement' => 'Use the provided template columns in the required order: transaction_date, reference, description, direction, amount, currency.']);
            }
            $rows = [];
            $rowNumber = 1;
            while (($columns = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if (count($columns) !== count(self::HEADER) || count($rows) >= 5000) {
                    throw ValidationException::withMessages(['statement' => 'The CSV has a malformed row or exceeds the 5,000-row limit.']);
                }
                [$dateInput, $reference, $description, $direction, $amount, $rowCurrency] = array_map(static fn ($value): string => trim((string) $value), $columns);
                try {
                    $date = Carbon::createFromFormat('!Y-m-d', $dateInput);
                } catch (\Throwable) {
                    $date = null;
                }
                if ($date === null || $date->format('Y-m-d') !== $dateInput || $reference === '' || strlen($reference) > 120
                    || ! in_array($direction, ['credit', 'debit'], true) || Str::upper($rowCurrency) !== Str::upper($currency)) {
                    throw ValidationException::withMessages(['statement' => "CSV row {$rowNumber} contains an invalid date, reference, direction, or currency."]);
                }
                try {
                    $amountMinor = BillingMoney::toMinor($amount);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['statement' => "CSV row {$rowNumber} contains an invalid amount."]);
                }
                if ($amountMinor < 1 || strlen($description) > 240) {
                    throw ValidationException::withMessages(['statement' => "CSV row {$rowNumber} must have a positive amount and a description under 240 characters."]);
                }
                $normalized = ['date' => $dateInput, 'reference' => $reference, 'description' => $description === '' ? null : $description,
                    'direction' => $direction, 'amount_minor' => $amountMinor];
                $rows[] = [...$normalized, 'fingerprint' => hash('sha256', implode("\0", [$dateInput, $reference, $description, $direction, $amount, Str::upper($rowCurrency)]))];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param array{date:string,reference:string,description:?string,direction:string,amount_minor:int} $row
     * @return array{status:string,payment_id:int|null,refund_id:int|null}
     */
    private function suggest(BillingBankAccount $account, array $row): array
    {
        $matches = $row['direction'] === 'credit'
            ? BillingPayment::query()->where('tenant_id', $account->tenant_id)->where('institution_id', $account->institution_id)
                ->when($account->campus_id !== null, fn ($q) => $q->where('campus_id', $account->campus_id))->where('method', 'bank_transfer')
                ->where('reference', $row['reference'])->where('currency', $account->currency)->where('amount', BillingMoney::fromMinor($row['amount_minor']))
                ->whereIn('status', ['confirmed', 'pending_verification'])->limit(2)->pluck('id')
            : BillingPaymentRefund::query()->where('tenant_id', $account->tenant_id)->where('status', 'refunded')
                ->where('payout_reference', $row['reference'])->where('currency', $account->currency)->where('amount', BillingMoney::fromMinor($row['amount_minor']))
                ->whereHas('payment', fn ($q) => $q->where('institution_id', $account->institution_id)->when($account->campus_id !== null, fn ($scope) => $scope->where('campus_id', $account->campus_id)))->limit(2)->pluck('id');
        if ($matches->count() !== 1) {
            return ['status' => 'unmatched', 'payment_id' => null, 'refund_id' => null];
        }

        $candidateId = (int) $matches->first();
        $alreadyMatched = BillingReconciliationLine::query()->where('tenant_id', $account->tenant_id)
            ->where($row['direction'] === 'credit' ? 'matched_payment_id' : 'matched_refund_id', $candidateId)->exists();
        $alreadySuggested = BillingReconciliationLine::query()->where('tenant_id', $account->tenant_id)->where('match_status', 'suggested')
            ->where($row['direction'] === 'credit' ? 'suggested_payment_id' : 'suggested_refund_id', $candidateId)->exists();
        if ($alreadyMatched || $alreadySuggested) {
            return ['status' => 'unmatched', 'payment_id' => null, 'refund_id' => null];
        }

        return $row['direction'] === 'credit'
            ? ['status' => 'suggested', 'payment_id' => $candidateId, 'refund_id' => null]
            : ['status' => 'suggested', 'payment_id' => null, 'refund_id' => $candidateId];
    }

    private function manager(): TenantMembership
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.reconciliation.manage'), 403);

        return $membership;
    }

    private function reviewer(): TenantMembership
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.reconciliation.review'), 403);

        return $membership;
    }

    private function assertAccountScope(BillingBankAccount $account, TenantMembership $membership): void
    {
        if ((int) $account->tenant_id !== (int) $membership->tenant_id || (int) $account->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $account->campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
    }

    private function assertBatchScope(BillingReconciliationBatch $batch, TenantMembership $membership): void
    {
        if ((int) $batch->tenant_id !== (int) $membership->tenant_id || (int) $batch->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $batch->campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
    }

    private function assertPaymentScope(BillingPayment $payment, BillingReconciliationBatch $batch, TenantMembership $membership): void
    {
        if ((int) $payment->tenant_id !== (int) $membership->tenant_id || (int) $payment->institution_id !== (int) $batch->institution_id
            || ($batch->campus_id !== null && (int) $payment->campus_id !== (int) $batch->campus_id) || $payment->currency !== $batch->currency) {
            abort(404);
        }
    }
}
