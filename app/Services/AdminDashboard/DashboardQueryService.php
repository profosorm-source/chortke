<?php

namespace App\Services\AdminDashboard;

use Core\Database;
use App\Contracts\LoggerInterface;
class DashboardQueryService extends \App\Services\BaseService
{
    private Database $db;
    public function __construct(Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->db     = $db;
    }


    
    private function timeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)      return 'لحظاتی پیش';
        if ($diff < 3600)    return (int)($diff / 60)    . ' دقیقه پیش';
        if ($diff < 86400)   return (int)($diff / 3600)  . ' ساعت پیش';
        if ($diff < 604800)  return (int)($diff / 86400)  . ' روز پیش';
        if ($diff < 2592000) return (int)($diff / 604800) . ' هفته پیش';
        return (int)($diff / 2592000) . ' ماه پیش';
    }

    
}

