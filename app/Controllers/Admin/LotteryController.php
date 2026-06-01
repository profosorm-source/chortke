<?php
// app/Controllers/Admin/LotteryController.php

namespace App\Controllers\Admin;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryChanceLog;
use App\Services\Lottery\LotteryService;
use App\Validators\Requests\LotteryRoundRequest;
use App\Controllers\Admin\BaseAdminController;

class LotteryController extends BaseAdminController
{
    private \App\Models\LotteryRound $lotteryRoundModel;
    private \App\Models\LotteryParticipation $lotteryParticipationModel;
    private \App\Models\LotteryDailyNumber $lotteryDailyNumberModel;
    private LotteryService $lotteryService;

    public function __construct(
        \App\Models\LotteryDailyNumber $lotteryDailyNumberModel,
        \App\Models\LotteryParticipation $lotteryParticipationModel,
        \App\Models\LotteryRound $lotteryRoundModel,
        \App\Services\Lottery\LotteryService $lotteryService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->lotteryService = $lotteryService;
        $this->lotteryDailyNumberModel = $lotteryDailyNumberModel;
        $this->lotteryParticipationModel = $lotteryParticipationModel;
        $this->lotteryRoundModel = $lotteryRoundModel;
    }

    public function index()
    {
        $filters = ['status' => $this->request->get('status')];
        $page = \max(1, (int)$this->request->get('page', 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $result = $this->lotteryService->listRounds($filters, $perPage, $offset);
        $rounds = $result['rounds'] ?? [];
        $total = $result['total'] ?? 0;
        $totalPages = \ceil($total / $perPage);
        $stats = $this->lotteryService->getStats();

        $roundIds = \array_map(fn($r) => (int)$r->id, $rounds);
        $participationCounts = !empty($roundIds) ? $this->lotteryService->getParticipationCounts($roundIds) : [];

        foreach ($roundIds as $rid) {
            if (!isset($participationCounts[$rid])) {
                $participationCounts[$rid] = 0;
            }
        }

        return view('admin.lottery.index', [
            'user' => user(),
            'rounds' => $rounds,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
            'participationCounts' => $participationCounts,
        ]);
    }

    public function create()
    {
        return view('admin.lottery.create', ['user' => user()]);
    }

    public function store()
    {
        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $request = new LotteryRoundRequest($input);
        if (!$request->validate()) {
            return $this->response->json(['success' => false, 'errors' => $request->errors()], 422);
        }

        $data = $request->validated();
        $result = $this->lotteryService->createRound(user_id(), $data);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function generateNumbers()
    {
                        $id = (int)$this->request->param('id');

        $result = $this->lotteryService->generateDailyNumbers($id);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function finalizeDaily()
    {
                        $dailyId = (int)$this->request->param('daily_id');

        $result = $this->lotteryService->finalizeDailyNumber($dailyId);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function selectWinner()
    {
                        $id = (int)$this->request->param('id');

        $result = $this->lotteryService->selectWinner($id, user_id());

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function cancel()
    {
        $id = (int)$this->request->param('id');

        $result = $this->lotteryService->cancelRound($id);
        
        if (!$result['success']) {
            return $this->response->json(['success' => false, 'message' => $result['message'] ?? 'دوره قابل لغو نیست.'], 422);
        }

        $this->logger->info('lottery_cancelled', ['message' => "Admin " . user_id() . " cancelled round #{$id}"]);

        return $this->response->json(['success' => true, 'message' => 'دوره لغو شد.']);
    }
}