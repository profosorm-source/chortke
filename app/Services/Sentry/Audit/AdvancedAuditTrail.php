<?php

declare(strict_types=1);

namespace App\Services\Sentry\Audit;

use App\Models\SentryModel;
use Core\Logger;
use App\Services\AuditTrail;
use Core\Session;

/**
 * 📋 AdvancedAuditTrail - سیستم پیشرفته Audit Trail
 */
class AdvancedAuditTrail
{
    private array $config = [
        'retention_days' => 90,
        'batch_size' => 100,
        'enable_compression' => true,
    ];

    public function __construct(
        private SentryModel $model,
        private Logger $logger,
        private AuditTrail $auditTrail,
        private Session $session,
        array $config = []
    ) {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 📝 Record Event
     */
    public function record(string $event, ?int $userId = null, array $context = [], ?int $actorId = null, string $category = 'general'): void
    {
        try {
            $enrichedContext = $this->enrichContext($context);
            if ($actorId === null) {
                $actorId = $this->detectActor();
            }

            $this->auditTrail->record($event, $userId, array_merge($enrichedContext, ['category' => $category]), $actorId);
        } catch (\Throwable $e) {
            $this->logger->error('sentry.advanced_audit.record.failed', ['channel' => 'sentry', 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 🔍 Advanced Search
     */
    public function search(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = '(at.user_id = ? OR at.actor_id = ?)';
            $params[] = $filters['user_id'];
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['event'])) {
            $where[] = 'at.event LIKE ?';
            $params[] = '%' . $filters['event'] . '%';
        }

        if (!empty($filters['category'])) {
            $where[] = 'at.category = ?';
            $params[] = $filters['category'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'at.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'at.created_at <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['ip_address'])) {
            $where[] = 'at.ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['context_search'])) {
            $where[] = 'at.context LIKE ?';
            $params[] = '%' . $filters['context_search'] . '%';
        }

        $page = (int)($filters['page'] ?? 1);
        $perPage = (int)($filters['per_page'] ?? 50);
        $offset = ($page - 1) * $perPage;

        $whereClause = implode(' AND ', $where);

        $total = $this->model->getAuditCount($whereClause, $params);
        $records = $this->model->searchAuditRecords($whereClause, $params, $perPage, $offset);

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * 📊 Generate Compliance Report
     */
    public function generateComplianceReport(string $startDate, string $endDate, string $type = 'full'): array
    {
        $report = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'generated_at' => date('Y-m-d H:i:s'),
            'type' => $type,
        ];

        $report['summary'] = $this->getReportSummary($startDate, $endDate);
        $report['by_category'] = $this->model->getAuditEventsByCategory($startDate, $endDate);
        $report['critical_events'] = $this->getCriticalEvents($startDate, $endDate);

        if ($type === 'full' || $type === 'user_activity') {
            $report['user_activity'] = $this->model->getAuditUserActivity($startDate, $endDate);
        }

        if ($type === 'full' || $type === 'access_patterns') {
            $report['access_patterns'] = $this->model->getAuditAccessPatterns($startDate, $endDate);
        }

        if ($type === 'full' || $type === 'security') {
            $report['failed_operations'] = $this->model->getAuditFailedOperations($startDate, $endDate);
        }

        return $report;
    }

    /**
     * 💾 Export to CSV
     */
    public function exportToCSV(array $filters, string $filename): string
    {
        $data = $this->search(array_merge($filters, ['per_page' => 10000]));
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['ID', 'Event', 'Category', 'User', 'Actor', 'IP Address', 'Created At', 'Context']);

        foreach ($data['records'] as $record) {
            fputcsv($csv, [$record->id, $record->event, $record->category, $record->user_email ?? '-', $record->actor_email ?? '-', $record->ip_address, $record->created_at, $record->context]);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        $path = dirname(__DIR__, 4) . '/storage/exports/' . $filename;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * 🗑️ Data Retention
     */
    public function cleanupOldRecords(): int
    {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-{$this->config['retention_days']} days"));

            if ($this->config['enable_compression']) {
                $this->archiveOldRecords($cutoffDate);
            }

            $deleted = $this->model->deleteOldAuditRecords($cutoffDate);
            if ($deleted > 0) {
                $this->logger->info("Cleaned up {$deleted} old audit records");
            }
            return $deleted;
        } catch (\Throwable $e) {
            $this->logger->error('Cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function archiveOldRecords(string $cutoffDate): void
    {
        try {
            $archiveFile = "audit_archive_" . date('Y-m-d') . ".json.gz";
            $archivePath = dirname(__DIR__, 4) . '/storage/archives/' . $archiveFile;

            $oldRecords = $this->model->getOldAuditRecords($cutoffDate);
            if (empty($oldRecords)) return;

            $json = json_encode($oldRecords, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $compressed = gzencode($json, 9);
            
            if (!is_dir(dirname($archivePath))) mkdir(dirname($archivePath), 0755, true);
            file_put_contents($archivePath, $compressed);

            $this->logger->info("Archived " . count($oldRecords) . " records to {$archiveFile}");
        } catch (\Throwable $e) {
            $this->logger->error('Archive failed', ['error' => $e->getMessage()]);
        }
    }

    public function compareChanges(int $recordId1, int $recordId2): array
    {
        $record1 = $this->model->getAuditRecordById($recordId1);
        $record2 = $this->model->getAuditRecordById($recordId2);

        if (!$record1 || !$record2) return ['error' => 'Records not found'];

        $context1 = json_decode((string)$record1->context, true) ?: [];
        $context2 = json_decode((string)$record2->context, true) ?: [];

        return [
            'record1' => ['id' => $record1->id, 'event' => $record1->event, 'created_at' => $record1->created_at],
            'record2' => ['id' => $record2->id, 'event' => $record2->event, 'created_at' => $record2->created_at],
            'changes' => $this->arrayDiff($context1, $context2),
        ];
    }

    public function getActivityTimeline(int $userId, int $days = 30): array
    {
        return $this->model->getActivityTimeline($userId, $days);
    }

    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            '_timestamp' => microtime(true),
            '_server_time' => date('Y-m-d H:i:s'),
            '_request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)),
        ]);
    }

    private function detectActor(): ?int
    {
        try {
            return $this->session->get('user_id') ? (int)$this->session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function arrayDiff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $key => $value) {
            if (!isset($old[$key])) $changes[$key] = ['added' => $value];
            elseif ($old[$key] !== $value) $changes[$key] = ['from' => $old[$key], 'to' => $value];
        }
        foreach ($old as $key => $value) {
            if (!isset($new[$key])) $changes[$key] = ['removed' => $value];
        }
        return $changes;
    }

    private function getReportSummary(string $start, string $end): array
    {
        $stats = $this->model->getAuditReportSummary($start, $end);
        return [
            'total_events' => (int)($stats->total_events ?? 0),
            'unique_users' => (int)($stats->unique_users ?? 0),
            'unique_categories' => (int)($stats->unique_categories ?? 0),
        ];
    }

    private function getCriticalEvents(string $start, string $end): array
    {
        $criticalEvents = ['user.deleted', 'admin.role_changed', 'security.breach', 'payment.failed', 'data.exported', 'admin.impersonate'];
        return $this->model->getAuditCriticalEvents($criticalEvents, $start, $end);
    }
}
