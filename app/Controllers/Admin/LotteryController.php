<?php
// app/Controllers/Admin/LotteryController.php

namespace App\Controllers\Admin;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryChanceLog;
use App\Services\LotteryService;
use Core\Validator;
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
        \App\Services\LotteryService $lotteryService)
    {
        parent::__construct();
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

        $validator = new Validator($input, [
            'title' => 'required|min:3|max:255',
            'type' => 'required|in:weekly,monthly',
            'entry_fee' => 'required|numeric|min:0',
            'prize_amount' => 'required|numeric|min:0',
            'duration_days' => 'required|numeric|min:1|max:31',
            'start_date' => 'required',
            'end_date' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->data();
        $result = $this->lotteryService->createRound(user_id(), (array)$data);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function show()
    {
                $id = (int)$this->request->param('id');

        $roundModel = $this->lotteryRoundModel;
        $participationModel = $this->lotteryParticipationModel;
        $dailyModel = $this->lotteryDailyNumberModel;

        $round = $this->lotteryService->getRound($id);
        if (!$round) return view('errors.404');

        $participants = $this->lotteryService->getRoundParticipants($id, 100);
        $participantCount = $this->lotteryService->countRoundParticipants($id);
        $dailyNumbers = $this->lotteryService->getRoundDailyNumbers($id);
        $distribution = $this->lotteryService->getChanceDistribution($id);

        return view('admin.lottery.show', [
            'user' => user(),
            'round' => $round,
            'participants' => $participants,
            'participantCount' => $participantCount,
            'dailyNumbers' => $dailyNumbers,
            'distribution' => $distribution,
        ]);
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