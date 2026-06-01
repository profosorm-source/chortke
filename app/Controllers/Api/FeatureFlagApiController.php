<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\FeatureFlagService;

/**
 * RESTful API برای مدیریت Feature Flags از راه دور
 */
class FeatureFlagApiController extends BaseApiController
{
    private FeatureFlagService $featureService;
    
    public function __construct(FeatureFlagService $featureService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->featureService = $featureService;
        
        // API Authentication check
        $this->authenticate();
    }
    
    /**
     * Authentication برای API
     */
    private function authenticate(): void
    {
        $apiKey = $this->request->header('X-API-Key');
        
        if (!$apiKey) {
            $this->error('Missing API key', 401, 'API_KEY_MISSING');
        }
        
        $validKey = config('feature_flags.api_key', '');
        
        if (!$validKey || !hash_equals($validKey, $apiKey)) {
            $this->error('Invalid API key', 403, 'INVALID_API_KEY');
        }
    }
    
    /**
     * GET /api/v1/features
     */
    public function index(): void
    {
        try {
            $features = $this->featureService->getAll();
            
            $public = array_map(function($feature) {
                return [
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'enabled' => (bool)$feature->enabled,
                    'enabled_percentage' => (int)$feature->enabled_percentage,
                    'priority' => (int)($feature->priority ?? 0),
                    'tags' => $feature->tags ? json_decode($feature->tags) : [],
                    'created_at' => $feature->created_at,
                    'updated_at' => $feature->updated_at,
                ];
            }, $features);
            
            $this->success($public, '', 200);
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/v1/features/{name}
     */
    public function show(string $name): void
    {
        try {
            $feature = $this->featureService->findByName($name);
            
            if (!$feature) {
                $this->error("Feature '{$name}' does not exist", 404);
            }
            
            $this->success([
                'name' => $feature->name,
                'description' => $feature->description,
                'enabled' => (bool)$feature->enabled,
                'enabled_percentage' => (int)$feature->enabled_percentage,
                'enabled_for_roles' => $feature->enabled_for_roles ? json_decode($feature->enabled_for_roles) : null,
                'enabled_for_users' => $feature->enabled_for_users ? json_decode($feature->enabled_for_users) : null,
                'enabled_from' => $feature->enabled_from,
                'enabled_until' => $feature->enabled_until,
                'depends_on' => $feature->depends_on ? json_decode($feature->depends_on) : null,
                'environments' => $feature->environments ? json_decode($feature->environments) : null,
                'priority' => (int)($feature->priority ?? 0),
                'tags' => $feature->tags ? json_decode($feature->tags) : [],
                'metadata' => $feature->metadata ? json_decode($feature->metadata) : null,
                'created_at' => $feature->created_at,
                'updated_at' => $feature->updated_at,
            ]);
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/check
     */
    public function check(string $name): void
    {
        try {
            $data = $this->request->body();
            $userId = $data['user_id'] ?? null;
            
            $enabled = $this->featureService->isEnabled($name, $userId);
            
            $this->success([
                'feature' => $name,
                'enabled' => $enabled,
                'user_id' => $userId,
                'checked_at' => date('Y-m-d H:i:s'),
            ]);
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/v1/features
     */
    public function create(): void
    {
        try {
            $data = $this->request->body();
            
            if (empty($data['name']) || empty($data['description'])) {
                $this->error('name and description are required', 400);
            }
            
            $this->featureService->create($data);
            
            $this->success(['name' => $data['name']], 'Feature created successfully', 201);
            
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * PATCH /api/v1/features/{name}
     */
    public function update(string $name): void
    {
        try {
            $data = $this->request->body();
            $this->featureService->update($name, $data);
            $this->success(['name' => $name], 'Feature updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/toggle
     */
    public function toggle(string $name): void
    {
        try {
            $result = $this->featureService->toggle($name);
            
            if (!$result) {
                $this->error("Feature '{$name}' does not exist", 404);
            }
            
            $feature = $this->featureService->findByName($name);
            
            $this->success([
                'name' => $name,
                'enabled' => (bool)$feature->enabled,
            ], 'Feature toggled successfully');
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/rollout
     */
    public function rollout(string $name): void
    {
        try {
            $data = $this->request->body();
            
            if (!isset($data['percentage'])) {
                $this->error('percentage is required', 400);
            }
            
            $percentage = (int)$data['percentage'];
            
            if ($percentage < 0 || $percentage > 100) {
                $this->error('percentage must be between 0 and 100', 400);
            }
            
            $this->featureService->update($name, ['enabled_percentage' => $percentage]);
            
            $this->success([
                'name' => $name,
                'percentage' => $percentage,
            ], 'Rollout percentage updated successfully');
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * DELETE /api/v1/features/{name}
     */
    public function delete(string $name): void
    {
        try {
            $result = $this->featureService->delete($name);
            
            if (!$result) {
                $this->error("Feature '{$name}' does not exist", 404);
            }
            
            $this->success(['name' => $name], 'Feature deleted successfully');
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/v1/features/{name}/stats
     */
    public function stats(string $name): void
    {
        try {
            $feature = $this->featureService->findByName($name);
            
            if (!$feature) {
                $this->error('Feature not found', 404);
            }
            
            $metrics = $this->featureService->getMetrics($name, 24);
            $history = $this->featureService->getHistory($name, 10);
            
            $this->success([
                'feature' => $name,
                'enabled' => (bool)$feature->enabled,
                'metrics_24h' => $metrics,
                'recent_changes' => $history,
            ]);
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/v1/stats
     */
    public function systemStats(): void
    {
        try {
            $stats = $this->featureService->getStats();
            $this->success($stats);
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/v1/features/bulk-check
     */
    public function bulkCheck(): void
    {
        try {
            $data = $this->request->body();
            
            if (empty($data['features']) || !is_array($data['features'])) {
                $this->error('features array is required', 400);
            }
            
            $userId = $data['user_id'] ?? null;
            $results = [];
            
            foreach ($data['features'] as $featureName) {
                $results[$featureName] = $this->featureService->isEnabled($featureName, $userId);
            }
            
            $this->success([
                'user_id' => $userId,
                'features' => $results,
                'checked_at' => date('Y-m-d H:i:s'),
            ]);
            
        } catch (\Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
}
