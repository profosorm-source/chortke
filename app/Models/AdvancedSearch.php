<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * AdvancedSearch Model - Secured against LIKE Injections
 */
class AdvancedSearch extends Model
{
    protected static string $table = '';

    /**
     * Escape and validate search query
     */
    private function sanitizeSearchQuery(string $q, int $maxLength = 100): string
    {
        return $this->escapeLikeValue($q, $maxLength);
    }

    public function searchUsers(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('users')
            ->select('id', 'full_name', 'email', 'mobile', 'kyc_status', 'tier_level', 'created_at')
            ->whereNull('deleted_at')
            ->where(function($query) use ($like, $q) {
                $query->where('full_name', 'LIKE', $like)
                      ->orWhere('email', 'LIKE', $like)
                      ->orWhere('mobile', 'LIKE', $like)
                      ->orWhere('referral_code', '=', $q);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchTransactions(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('transactions as t')
            ->select('t.id', 't.type', 't.amount', 't.currency', 't.status', 't.description', 't.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('t.reference_id', 'LIKE', $like)
                      ->orWhere('t.description', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('t.id', '=', $q);
            })
            ->orderBy('t.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchTickets(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('tickets as tk')
            ->select('tk.id', 'tk.subject', 'tk.status', 'tk.priority', 'tk.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'tk.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('tk.subject', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('tk.id', '=', $q);
            })
            ->orderBy('tk.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchWithdrawals(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('withdrawals as w')
            ->select('w.id', 'w.amount', 'w.currency', 'w.status', 'w.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('w.tracking_code', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('w.id', '=', $q);
            })
            ->orderBy('w.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchDeposits(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        $manual = $this->db->table('manual_deposits as md')
            ->selectRaw("md.id, md.amount, 'manual' as type, md.status, md.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'md.user_id')
            ->where(function($query) use ($like) {
                $query->where('md.tracking_code', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            });

        $crypto = $this->db->table('crypto_deposits as cd')
            ->selectRaw("cd.id, cd.amount, 'crypto' as type, cd.status, cd.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'cd.user_id')
            ->where(function($query) use ($like) {
                $query->where('cd.tx_hash', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            });

        $results = array_merge($manual->get() ?? [], $crypto->get() ?? []);
        usort($results, fn($a, $b) => strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']));
        return array_slice($results, 0, $limit);
    }

    public function searchAds(string $q, int $limit): array
    {
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('advertisements as a')
            ->select('a.id', 'a.title', 'a.platform', 'a.task_type', 'a.status', 'a.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'a.advertiser_id')
            ->whereNull('a.deleted_at')
            ->where(function($query) use ($like) {
                $query->where('a.title', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            })
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchUserTransactions(string $q, int $userId, int $limit): array
    {
        $this->validateId($userId);
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('transactions')
            ->select('id', 'type', 'amount', 'currency', 'status', 'description', 'created_at')
            ->where('user_id', '=', $userId)
            ->where(function($query) use ($like) {
                $query->where('description', 'LIKE', $like)
                      ->orWhere('reference_id', 'LIKE', $like);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchUserTickets(string $q, int $userId, int $limit): array
    {
        $this->validateId($userId);
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('tickets')
            ->select('id', 'subject', 'status', 'priority', 'created_at')
            ->where('user_id', '=', $userId)
            ->where('subject', 'LIKE', $like)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchUserAds(string $q, int $userId, int $limit): array
    {
        $this->validateId($userId);
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('advertisements')
            ->select('id', 'title', 'platform', 'task_type', 'status', 'created_at')
            ->where('advertiser_id', '=', $userId)
            ->whereNull('deleted_at')
            ->where('title', 'LIKE', $like)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchUserTasks(string $q, int $userId, int $limit): array
    {
        $this->validateId($userId);
        $q = $this->sanitizeSearchQuery($q);
        $like = "%{$q}%";
        
        return $this->db->table('task_executions as te')
            ->select('te.id', 'te.status', 'te.reward_amount', 'te.created_at', 'a.title as ad_title')
            ->join('advertisements as a', 'a.id', '=', 'te.advertisement_id')
            ->where('te.executor_id', '=', $userId)
            ->where('a.title', 'LIKE', $like)
            ->orderBy('te.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('social_ads')
            ->select('id', 'title', 'description', 'platform', 'task_type', 'reward', 'status', 'created_at')
            ->where('status', '=', 'active');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('description', 'LIKE', $like);
            });
        }

        if (!empty($filters['platform'])) {
            $query->where('platform', '=', e($filters['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($filters['task_type'])) {
            $query->where('task_type', '=', e($filters['task_type'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($filters['min_reward'])) {
            $query->where('reward', '>=', (float)$filters['min_reward']);
        }
        if (!empty($filters['max_reward'])) {
            $query->where('reward', '<=', (float)$filters['max_reward']);
        }

        return [
            'total' => $this->countSocialTasks($filters),
            'items' => $query->orderBy(...$this->getSortDirection($filters['sort'] ?? 'newest'))
                            ->limit($limit)
                            ->offset($offset)
                            ->get() ?? []
        ];
    }

    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->select('ip.id', 'ip.display_name', 'ip.bio', 'ip.platform', 'ip.followers', 'ip.avg_engagement', 'ip.status', 'ip.created_at')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('ip.display_name', 'LIKE', $like)->orWhere('ip.bio', 'LIKE', $like);
            });
        }

        if (!empty($filters['platform'])) {
            $query->where('ip.platform', '=', e($filters['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($filters['min_followers'])) {
            $query->where('ip.followers', '>=', (int)$filters['min_followers']);
        }
        if (!empty($filters['max_followers'])) {
            $query->where('ip.followers', '<=', (int)$filters['max_followers']);
        }

        return [
            'total' => $this->countInfluencers($filters),
            'items' => $query->orderBy(...$this->getSortDirection($filters['sort'] ?? 'newest'))
                            ->limit($limit)
                            ->offset($offset)
                            ->get() ?? []
        ];
    }

    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->select('vl.id', 'vl.title', 'vl.description', 'vl.category', 'vl.platform', 'vl.price_usdt', 'vl.listing_type', 'vl.status', 'vl.created_at')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('vl.title', 'LIKE', $like)->orWhere('vl.description', 'LIKE', $like);
            });
        }

        if (!empty($filters['category'])) {
            $query->where('vl.category', '=', e($filters['category'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($filters['platform'])) {
            $query->where('vl.platform', '=', e($filters['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($filters['min_price'])) {
            $query->where('vl.price_usdt', '>=', (float)$filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $query->where('vl.price_usdt', '<=', (float)$filters['max_price']);
        }

        return [
            'total' => $this->countVitrine($filters),
            'items' => $query->orderBy(...$this->getSortDirection($filters['sort'] ?? 'newest'))
                            ->limit($limit)
                            ->offset($offset)
                            ->get() ?? []
        ];
    }

    public function countSocialTasks(array $filters): int
    {
        $query = $this->db->table('social_ads')->where('status', '=', 'active');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('description', 'LIKE', $like);
            });
        }
        if (!empty($filters['platform'])) $query->where('platform', '=', $filters['platform']);
        if (!empty($filters['task_type'])) $query->where('task_type', '=', $filters['task_type']);
        if (!empty($filters['min_reward'])) $query->where('reward', '>=', (float)$filters['min_reward']);
        if (!empty($filters['max_reward'])) $query->where('reward', '<=', (float)$filters['max_reward']);

        return (int)$query->count();
    }

    public function countInfluencers(array $filters): int
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('ip.display_name', 'LIKE', $like)->orWhere('ip.bio', 'LIKE', $like);
            });
        }
        if (!empty($filters['platform'])) $query->where('ip.platform', '=', $filters['platform']);
        if (!empty($filters['min_followers'])) $query->where('ip.followers', '>=', (int)$filters['min_followers']);
        if (!empty($filters['max_followers'])) $query->where('ip.followers', '<=', (int)$filters['max_followers']);

        return (int)$query->count();
    }

    public function countVitrine(array $filters): int
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        if (!empty($filters['q'])) {
            $qClean = $this->sanitizeSearchQuery($filters['q']);
            $like = "%{$qClean}%";
            $query->where(function($sub) use ($like) {
                $sub->where('vl.title', 'LIKE', $like)->orWhere('vl.description', 'LIKE', $like);
            });
        }
        if (!empty($filters['category'])) $query->where('vl.category', '=', $filters['category']);
        if (!empty($filters['platform'])) $query->where('vl.platform', '=', $filters['platform']);
        if (!empty($filters['min_price'])) $query->where('vl.price_usdt', '>=', (float)$filters['min_price']);
        if (!empty($filters['max_price'])) $query->where('vl.price_usdt', '<=', (float)$filters['max_price']);

        return (int)$query->count();
    }

    private function getSortDirection(string $sort): array
    {
        return match ($sort) {
            'oldest' => ['created_at', 'ASC'],
            'reward_high' => ['reward', 'DESC'],
            'reward_low' => ['reward', 'ASC'],
            'followers' => ['ip.followers', 'DESC'],
            'engagement' => ['ip.avg_engagement', 'DESC'],
            'price_asc' => ['vl.price_usdt', 'ASC'],
            'price_desc' => ['vl.price_usdt', 'DESC'],
            default => ['created_at', 'DESC'],
        };
    }
}
