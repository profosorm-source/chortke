<?php

namespace App\Controllers\User;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Services\User\UserLevelService;
use App\Controllers\User\BaseUserController;

class LevelController extends BaseUserController
{
    private \App\Services\User\UserLevelService $levelService;
    private \App\Models\UserLevel $levelModel;
    private \App\Models\UserLevelHistory $historyModel;

    public function __construct(
        \App\Services\User\UserLevelService $levelService,
        \App\Models\UserLevel $levelModel,
        \App\Models\UserLevelHistory $historyModel
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->levelService  = $levelService;
        $this->levelModel    = $levelModel;
        $this->historyModel  = $historyModel;
    }

    /**
     * صفحه سطح‌بندی کاربر
     */
    public function index()
    {
                $userId = $this->userId();

        $progress = $this->levelService->getProgress($userId);
        $allLevels = $this->levelModel->all(true);
        $history = $this->historyModel->getByUser($userId, 15, 0);
        $bonuses = $this->levelService->getUserBonuses($userId);

        $currencyMode = setting('currency_mode', 'irt');

        return view('user.level.index', [
            'progress' => $progress,
            'allLevels' => $allLevels,
            'history' => $history,
            'bonuses' => $bonuses,
            'currencyMode' => $currencyMode,
        ]);
    }

    /**
     * خرید سطح (Ajax)
     */
    public function purchase()
    {
                        
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $levelSlug = $body['level'] ?? '';
        $currency = $body['currency'] ?? 'irt';



        $userId = $this->userId();
        $result = $this->levelService->purchaseLevel($userId, $levelSlug, $currency);

        $statusCode = $result['success'] ? 200 : 422;
        $this->response->json($result, $statusCode);
    }
}