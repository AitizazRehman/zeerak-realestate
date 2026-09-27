<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Services\BankStatementFileReader;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BankStatementImportController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function accessibleBankAccount($id)
    {
        $query = BankAccount::query()->where('is_active', true);

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    public function index(Request $request)
    {
        $request->validate([
            'bank_account_id' => ['nullable','integer'],
            'status' => ['nullable','in:uploaded,imported,failed'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = $this->scopeBranch(
            BankStatementImport::with([
                'bankAccount:id,branch_id,name,bank_name,account_number,currency',
                'branch:id,name,code',
                'uploadedBy:id,name',
            ])
        );

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->bank_account_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->latest('id')->paginate(
                min(max((int) $request->get('per_page', 20), 1), 100)
            )
        );
    }

    public function preview(Request $request, BankStatementFileReader $reader)
    {
        $data = $request->validate([
            'bank_account_id' => ['required','integer','exists:bank_accounts,id'],
            'file' => ['required','file','max:10240'],
            'header_row' => ['nullable','integer','min:1','max:50'],
        ]);

        $account = $this->accessibleBankAccount($data['bank_account_id']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['csv','txt','xlsx'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Use a CSV or XLSX bank statement file.'],
            ]);
        }

        $headerRow = (int) ($data['header_row'] ?? 1);
        $hash = hash_file('sha256', $file->getRealPath());
        $filename = now()->format('YmdHis').'-'.Str::random(16).'.'.$extension;
        $storedPath = $file->storeAs('bank-statements/'.now()->format('Y/m'), $filename);

        try {
            $preview = $reader->preview(Storage::path($storedPath), $extension, $headerRow, 12);
        } catch (RuntimeException $exception) {
            Storage::delete($storedPath);

            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        $previousImport = BankStatementImport::where('bank_account_id', $account->id)
            ->where('file_hash', $hash)
            ->where('status', 'imported')
            ->latest('id')
            ->first();

        $import = BankStatementImport::create([
            'bank_account_id' => $account->id,
            'branch_id' => $account->branch_id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_type' => $extension,
            'file_hash' => $hash,
            'header_row' => $headerRow,
            'status' => 'uploaded',
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'import' => $import->load('bankAccount:id,branch_id,name,bank_name,account_number,currency'),
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'detected_rows' => $preview['detected_rows'],
            'duplicate_file_warning' => $previousImport ? [
                'import_id' => $previousImport->id,
                'imported_at' => optional($previousImport->imported_at)->toISOString(),
                'message' => 'This exact file was imported before. Row-level duplicate detection will still prevent duplicate transactions.',
            ] : null,
            'mapping_fields' => $this->mappingFields(),
        ], 201);
    }

    public function refreshPreview(Request $request, BankStatementImport $bankStatementImport, BankStatementFileReader $reader)
    {
        $import = $this->scopeBranch(BankStatementImport::query())
            ->findOrFail($bankStatementImport->id);

        if ($import->status !== 'uploaded') {
            abort(422, 'Only an uploaded, unprocessed statement can be previewed again.');
        }

        $data = $request->validate([
            'header_row' => ['required','integer','min:1','max:50'],
        ]);

        if (!Storage::exists($import->stored_path)) {
            abort(422, 'The uploaded statement file is no longer available.');
        }

        try {
            $preview = $reader->preview(
                Storage::path($import->stored_path),
                $import->file_type,
                (int) $data['header_row'],
                12
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'header_row' => [$exception->getMessage()],
            ]);
        }

        $import->update(['header_row' => (int) $data['header_row']]);

        return response()->json([
            'import' => $import->fresh(),
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'detected_rows' => $preview['detected_rows'],
            'mapping_fields' => $this->mappingFields(),
        ]);
    }

    public function commit(Request $request, BankStatementImport $bankStatementImport, BankStatementFileReader $reader)
    {
        $import = $this->scopeBranch(BankStatementImport::query())
            ->findOrFail($bankStatementImport->id);

        if ($import->status !== 'uploaded') {
            abort(422, 'This bank statement has already been processed.');
        }

        if (!Storage::exists($import->stored_path)) {
            abort(422, 'The uploaded statement file is no longer available.');
        }

        $data = $request->validate([
            'mapping' => ['required','array'],
            'mapping.transaction_date' => ['required','integer','min:0'],
            'mapping.value_date' => ['nullable','integer','min:0'],
            'mapping.deposit' => ['nullable','integer','min:0'],
            'mapping.withdrawal' => ['nullable','integer','min:0'],
            'mapping.amount' => ['nullable','integer','min:0'],
            'mapping.running_balance' => ['nullable','integer','min:0'],
            'mapping.reference_number' => ['nullable','integer','min:0'],
            'mapping.cheque_number' => ['nullable','integer','min:0'],
            'mapping.external_transaction_id' => ['nullable','integer','min:0'],
            'mapping.description' => ['nullable','integer','min:0'],
            'amount_mode' => ['nullable','in:positive_deposit,positive_withdrawal'],
            'statement_opening_balance' => ['nullable','numeric'],
            'statement_closing_balance' => ['nullable','numeric'],
            'notes' => ['nullable','string','max:5000'],
        ]);

        $mapping = $data['mapping'];
        $hasDebitCredit = (array_key_exists('deposit', $mapping) && $mapping['deposit'] !== null) ||
            (array_key_exists('withdrawal', $mapping) && $mapping['withdrawal'] !== null);
        $hasAmount = array_key_exists('amount', $mapping) && $mapping['amount'] !== null;

        if (!$hasDebitCredit && !$hasAmount) {
            throw ValidationException::withMessages([
                'mapping' => ['Map Deposit/Withdrawal columns or map one signed Amount column.'],
            ]);
        }

        if ($hasAmount && empty($data['amount_mode'])) {
            throw ValidationException::withMessages([
                'amount_mode' => ['Choose whether positive values in the Amount column are deposits or withdrawals.'],
            ]);
        }

        try {
            $file = $reader->dataRows(
                Storage::path($import->stored_path),
                $import->file_type,
                (int) $import->header_row
            );

            $result = $this->importRows(
                $import,
                $file['rows'],
                $mapping,
                $data['amount_mode'] ?? null,
                $request->user()->id
            );
        } catch (\Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'error_message' => substr($exception->getMessage(), 0, 10000),
            ]);

            throw $exception;
        }

        $import->update([
            'column_mapping' => array_merge($mapping, [
                '_amount_mode' => $data['amount_mode'] ?? null,
            ]),
            'status' => 'imported',
            'total_rows' => $result['total_rows'],
            'imported_rows' => $result['imported_rows'],
            'duplicate_rows' => $result['duplicate_rows'],
            'skipped_rows' => $result['skipped_rows'],
            'statement_opening_balance' => $data['statement_opening_balance'] ?? null,
            'statement_closing_balance' => $data['statement_closing_balance'] ?? null,
            'notes' => $data['notes'] ?? null,
            'error_message' => null,
            'imported_at' => now(),
        ]);

        return response()->json([
            'message' => 'Bank statement imported successfully.',
            'import' => $import->fresh()->load([
                'bankAccount:id,branch_id,name,bank_name,account_number,currency',
                'uploadedBy:id,name',
            ]),
            'summary' => $result,
        ]);
    }

    private function importRows(BankStatementImport $import, array $rows, array $mapping, $amountMode, $userId)
    {
        $candidates = [];
        $issues = [];
        $totalRows = count($rows);

        foreach ($rows as $index => $row) {
            $rowNumber = (int) $import->header_row + $index + 1;

            try {
                $candidate = $this->candidateFromRow(
                    $import,
                    $row,
                    $mapping,
                    $amountMode,
                    $userId
                );

                if (!$candidate) {
                    $issues[] = ['row' => $rowNumber, 'reason' => 'Empty or zero-value transaction row.'];
                    continue;
                }

                $candidate['_row_number'] = $rowNumber;
                $candidates[] = $candidate;
            } catch (RuntimeException $exception) {
                if (count($issues) < 25) {
                    $issues[] = ['row' => $rowNumber, 'reason' => $exception->getMessage()];
                }
            }
        }

        $existingHashes = [];
        $existingExternalIds = [];
        $hashes = array_values(array_unique(array_column($candidates, 'transaction_hash')));
        $externalIds = array_values(array_unique(array_filter(array_column($candidates, 'external_transaction_id'))));

        foreach (array_chunk($hashes, 500) as $chunk) {
            $values = BankTransaction::withTrashed()
                ->where('bank_account_id', $import->bank_account_id)
                ->whereIn('transaction_hash', $chunk)
                ->pluck('transaction_hash')
                ->all();

            foreach ($values as $value) {
                $existingHashes[$value] = true;
            }
        }

        foreach (array_chunk($externalIds, 500) as $chunk) {
            $values = BankTransaction::withTrashed()
                ->where('bank_account_id', $import->bank_account_id)
                ->whereIn('external_transaction_id', $chunk)
                ->pluck('external_transaction_id')
                ->all();

            foreach ($values as $value) {
                $existingExternalIds[$value] = true;
            }
        }

        $seenHashes = [];
        $seenExternalIds = [];
        $toInsert = [];
        $duplicates = 0;

        foreach ($candidates as $candidate) {
            $hash = $candidate['transaction_hash'];
            $externalId = $candidate['external_transaction_id'];
            $duplicate = isset($existingHashes[$hash]) || isset($seenHashes[$hash]);

            if ($externalId) {
                $duplicate = $duplicate ||
                    isset($existingExternalIds[$externalId]) ||
                    isset($seenExternalIds[$externalId]);
            }

            if ($duplicate) {
                $duplicates++;
                continue;
            }

            $seenHashes[$hash] = true;

            if ($externalId) {
                $seenExternalIds[$externalId] = true;
            }

            unset($candidate['_row_number']);
            $toInsert[] = $candidate;
        }

        DB::transaction(function () use ($toInsert) {
            foreach (array_chunk($toInsert, 500) as $chunk) {
                DB::table('bank_transactions')->insert($chunk);
            }
        });

        return [
            'total_rows' => $totalRows,
            'imported_rows' => count($toInsert),
            'duplicate_rows' => $duplicates,
            'skipped_rows' => $totalRows - count($toInsert) - $duplicates,
            'issues' => $issues,
        ];
    }

    private function candidateFromRow(BankStatementImport $import, array $row, array $mapping, $amountMode, $userId)
    {
        $dateRaw = $this->mapped($row, $mapping, 'transaction_date');

        if ($dateRaw === '') {
            return null;
        }

        $transactionDate = $this->parseDate($dateRaw);

        if (!$transactionDate) {
            throw new RuntimeException('Transaction date could not be parsed: '.$dateRaw);
        }

        $valueDateRaw = $this->mapped($row, $mapping, 'value_date');
        $valueDate = $valueDateRaw !== '' ? $this->parseDate($valueDateRaw) : null;

        if ($valueDateRaw !== '' && !$valueDate) {
            throw new RuntimeException('Value date could not be parsed: '.$valueDateRaw);
        }

        $debit = 0.0;
        $credit = 0.0;
        $hasDeposit = array_key_exists('deposit', $mapping) && $mapping['deposit'] !== null;
        $hasWithdrawal = array_key_exists('withdrawal', $mapping) && $mapping['withdrawal'] !== null;

        if ($hasDeposit || $hasWithdrawal) {
            $debit = $hasDeposit ? abs($this->parseMoney($this->mapped($row, $mapping, 'deposit'))) : 0.0;
            $credit = $hasWithdrawal ? abs($this->parseMoney($this->mapped($row, $mapping, 'withdrawal'))) : 0.0;
        } else {
            $amount = $this->parseMoney($this->mapped($row, $mapping, 'amount'));

            if (abs($amount) < 0.005) {
                return null;
            }

            $positiveIsDeposit = $amountMode === 'positive_deposit';
            $isDeposit = $amount > 0 ? $positiveIsDeposit : !$positiveIsDeposit;

            if ($isDeposit) {
                $debit = abs($amount);
            } else {
                $credit = abs($amount);
            }
        }

        if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
            throw new RuntimeException('Row must contain exactly one deposit or withdrawal amount.');
        }

        $reference = $this->nullableText($this->mapped($row, $mapping, 'reference_number'), 150);
        $cheque = $this->nullableText($this->mapped($row, $mapping, 'cheque_number'), 100);
        $externalId = $this->nullableText($this->mapped($row, $mapping, 'external_transaction_id'), 191);
        $description = $this->nullableText($this->mapped($row, $mapping, 'description'), 5000);
        $runningBalanceRaw = $this->mapped($row, $mapping, 'running_balance');
        $runningBalance = $runningBalanceRaw === '' ? null : $this->parseMoney($runningBalanceRaw);

        $candidate = [
            'bank_account_id' => $import->bank_account_id,
            'branch_id' => $import->branch_id,
            'transaction_date' => $transactionDate,
            'value_date' => $valueDate,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'running_balance' => $runningBalance === null ? null : number_format($runningBalance, 2, '.', ''),
            'reference_number' => $reference,
            'cheque_number' => $cheque,
            'external_transaction_id' => $externalId,
            'description' => $description,
            'source' => 'statement_import',
            'project_id' => null,
            'customer_id' => null,
            'journal_entry_id' => null,
            'bank_statement_import_id' => $import->id,
            'reconciliation_status' => 'unmatched',
            'match_method' => null,
            'reconciled_at' => null,
            'reconciled_by' => null,
            'created_by' => $userId,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        $candidate['transaction_hash'] = $this->transactionHash($candidate);

        return $candidate;
    }

    private function mapped(array $row, array $mapping, $field)
    {
        if (!array_key_exists($field, $mapping) || $mapping[$field] === null) {
            return '';
        }

        $index = (int) $mapping[$field];

        return isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    private function parseMoney($value)
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-') {
            return 0.0;
        }

        $negative = false;

        if (preg_match('/^\(.*\)$/', $value)) {
            $negative = true;
        }

        $value = str_ireplace(['PKR','RS.','RS','₨',',',' '], '', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || $value === '-' || !is_numeric($value)) {
            throw new RuntimeException('Amount could not be parsed.');
        }

        $amount = (float) $value;

        return $negative ? -abs($amount) : $amount;
    }

    private function parseDate($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            if ($serial > 20000 && $serial < 100000) {
                return Carbon::create(1899, 12, 30)->addDays((int) floor($serial))->format('Y-m-d');
            }
        }

        $formats = [
            'Y-m-d',
            'Y/m/d',
            'd/m/Y',
            'd-m-Y',
            'd.m.Y',
            'd M Y',
            'd-M-Y',
            'j M Y',
            'j-M-Y',
            'm/d/Y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable $exception) {
                // Try the next supported bank date format.
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function nullableText($value, $limit)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function transactionHash(array $data)
    {
        return hash('sha256', implode('|', [
            (int) $data['bank_account_id'],
            $data['transaction_date'],
            $data['value_date'] ?? '',
            number_format((float) $data['debit'], 2, '.', ''),
            number_format((float) $data['credit'], 2, '.', ''),
            strtolower((string) ($data['reference_number'] ?? '')),
            strtolower((string) ($data['external_transaction_id'] ?? '')),
            strtolower((string) ($data['description'] ?? '')),
        ]));
    }

    private function mappingFields()
    {
        return [
            ['key' => 'transaction_date', 'label' => 'Transaction Date', 'required' => true],
            ['key' => 'value_date', 'label' => 'Value Date', 'required' => false],
            ['key' => 'deposit', 'label' => 'Deposit / Money In', 'required' => false],
            ['key' => 'withdrawal', 'label' => 'Withdrawal / Money Out', 'required' => false],
            ['key' => 'amount', 'label' => 'Signed Amount', 'required' => false],
            ['key' => 'running_balance', 'label' => 'Running Balance', 'required' => false],
            ['key' => 'reference_number', 'label' => 'Reference Number', 'required' => false],
            ['key' => 'cheque_number', 'label' => 'Cheque Number', 'required' => false],
            ['key' => 'external_transaction_id', 'label' => 'Bank Transaction ID', 'required' => false],
            ['key' => 'description', 'label' => 'Description / Narration', 'required' => false],
        ];
    }
}
