<?php

namespace App\Services;

use App\Models\ExportData;

use App\Contracts\LoggerInterface;
class ExportService extends \App\Services\BaseService
{
    public function __construct(
        private ExportData $exportData,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }
    /**
     * خروجی CSV
     */
    public function exportCsv(array $headers, array $rows, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.csv';

        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache, no-store, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');

        // BOM for UTF-8 Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = \fopen('php://output', 'w');

        // Header
        \fputcsv($output, $headers);

        // Rows
        foreach ($rows as $row) {
            if (\is_object($row)) {
                $row = (array)$row;
            }
            \fputcsv($output, \array_values($row));
        }

        \fclose($output);
        exit;
    }

    /**
     * خروجی JSON
     */
    public function exportJson(array $data, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.json';

        \header('Content-Type: application/json; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache');

        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی کاربران
     */
    public function prepareUsersExport(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        
        $rows = $this->exportData->getUsers($dateFrom, $dateTo);
        
        $headers = ['شناسه', 'نام', 'ایمیل', 'موبایل', 'سطح', 'وضعیت', 'تاریخ ثبت‌نام', 'آخرین ورود', 'موجودی تومان', 'موجودی تتر'];

        $statusMap = [0 => 'غیرفعال', 1 => 'فعال', 2 => 'تعلیق', 3 => 'مسدود'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->full_name,
                $r->email,
                $r->mobile ?? '',
                $r->tier_level ?? 'silver',
                $statusMap[(int)($r->status ?? 0)] ?? 'نامشخص',
                $r->created_at,
                $r->last_login ?? '',
                $r->balance_irt,
                $r->balance_usdt,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی تراکنش‌ها
     */
    public function prepareTransactionsExport(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        
        $rows = $this->exportData->getTransactions($dateFrom, $dateTo, $type, $status);

        $headers = ['شناسه', 'شماره تراکنش', 'کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->transaction_id,
                $r->full_name ?? '',
                $r->type,
                $r->currency,
                $r->amount,
                $r->balance_before,
                $r->balance_after,
                $r->status,
                $r->created_at,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * خروجی کاربران
     */
    public function exportUsers(array $filters = []): void
    {
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $kycStatus = $filters['kyc_status'] ?? null;
        $tierLevel = $filters['tier_level'] ?? null;
        
        $rows = $this->exportData->getUsers($dateFrom, $dateTo, $kycStatus, $tierLevel);
        
        $headers = ['#', 'نام', 'ایمیل', 'موبایل', 'KYC', 'سطح', 'کد معرف', 'مسدود', 'تاریخ'];
        
        $this->exportCsv($headers, $rows, 'users_export');
    }

    /**
     * خروجی تراکنش‌ها
     */
    public function exportTransactionsStream(array $filters = []): void
    {
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $type = $filters['type'] ?? null;
        $currency = $filters['currency'] ?? null;
        $status = $filters['status'] ?? null;
        
        $rows = $this->exportData->getTransactions($dateFrom, $dateTo, $type, $status, $currency);
        
        $headers = ['#', 'نام', 'ایمیل', 'نوع', 'مبلغ', 'ارز', 'وضعیت', 'توضیح', 'مرجع', 'تاریخ'];
        
        $this->exportCsv($headers, $rows, 'transactions_export');
    }

    /**
     * خروجی برداشت‌ها
     */
    public function exportWithdrawalsStream(array $filters = []): void
    {
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $status = $filters['status'] ?? null;
        $currency = $filters['currency'] ?? null;
        
        $rows = $this->exportData->getWithdrawals($dateFrom, $dateTo, $status, $currency);
        
        $headers = ['#', 'کد پیگیری', 'نام', 'ایمیل', 'مبلغ', 'کارمزد', 'مبلغ نهایی', 'ارز', 'وضعیت', 'روش', 'تاریخ'];
        
        $this->exportCsv($headers, $rows, 'withdrawals_export');
    }

    /**
     * خروجی AuditTrail
     */
    public function exportAuditTrail(array $filters = []): void
    {
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $event = $filters['event'] ?? null;
        $userId = isset($filters['user_id']) ? (int)$filters['user_id'] : null;
        
        $rows = $this->exportData->getAuditTrail($dateFrom, $dateTo, $event, $userId);
        
        $headers = ['#', 'رویداد', 'کاربر', 'انجام‌دهنده', 'جزئیات', 'IP', 'زمان'];
        
        $this->exportCsv($headers, $rows, 'audit_trail_export');
    }
}
