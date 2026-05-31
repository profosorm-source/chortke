<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;

use App\Contracts\LoggerInterface;
/**
 * TorListUpdater
 * 
 * به‌روزرسانی لیست Tor exit nodes
 */
class TorListUpdater
{
    private IpAndDeviceModel $model;
    
    public function __construct(
        IpAndDeviceModel $model
    )
    {
                $this->model = $model;
    }
    
    /**
     * دانلود و به‌روزرسانی لیست Tor
     */
    public function update(): array
    {
        $url = 'https://check.torproject.org/torbulkexitlist';
        
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5.0, // 5 seconds
                ]
            ]);
            $content = @file_get_contents($url, false, $context);
            
            if ($content === false) {
                return [
                    'success' => false,
                    'message' => 'خطا در دانلود لیست Tor'
                ];
            }
            
            $ips = array_filter(array_map('trim', explode("\n", $content)));
            $validIPs = [];
            
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $validIPs[] = $ip;
                }
            }
            
            if (empty($validIPs)) {
                return [
                    'success' => false,
                    'message' => 'هیچ IP معتبری یافت نشد'
                ];
            }
            
            $this->model->truncateTorNodes();
            
            $inserted = 0;
            foreach ($validIPs as $ip) {
                if ($this->model->insertTorNode($ip)) {
                    $inserted++;
                }
            }
            
            return [
                'success' => true,
                'message' => "{$inserted} Tor exit node به‌روز شد",
                'count' => $inserted
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * دریافت تعداد Tor nodes
     */
    public function getCount(): int
    {
        return $this->model->getTorNodesCount();
    }
    
    /**
     * دریافت آخرین زمان به‌روزرسانی
     */
    public function getLastUpdate(): ?string
    {
        return $this->model->getLastUpdateTime();
    }
}

