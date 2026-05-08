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
        $roundModel = $this->lotteryRoundModel;

        $filters = ['status' => $_GET['status'] ?? null];
        $page = \max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $rounds = $roundModel->getAll($filters, $perPage, $offset);
        $total = $roundModel->countAll($filters);
        $totalPages = \ceil($total / $perPage);
        $stats = $roundModel->getStats();

        $roundIds = \array_map(fn($r) => (int)$r->id, $rounds);
        $participationCounts = !empty($roundIds) ? $this->lotteryParticipationModel->getCountsByRounds($roundIds) : [];

        foreach ($roundIds as $rid) {
            if (!isset($participationCounts[$rid])) {
                $participationCounts[$rid] = 0;
            }
        }

        $user = auth()->user();

        return view('admin.lottery.index', [
            'user' => $user,
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
        $user = auth()->user();
        return view('admin.lottery.create', ['user' => $user]);
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

        $round = $roundModel->findWithWinner($id);
        if (!$round) return view('errors.404');

        $participants = $participationModel->getByRound($id, 100);
        $participantCount = $participationModel->countByRound($id);
        $dailyNumbers = $dailyModel->getByRound($id);
        $distribution = $participationModel->getChanceDistribution($id);

        $user = auth()->user();

        return view('admin.lottery.show', [
            'user' => $user,
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

        $roundModel = $this->lotteryRoundModel;
        $round = $roundModel->find($id);

        if (!$round || $round->status === LotteryRound::STATUS_COMPLETED) {
            return $this->response->json(['success' => false, 'message' => 'دوره قابل لغو نیست.'], 422);
        }

        $roundModel->update($id, ['status' => LotteryRound::STATUS_CANCELLED]);
        $this->logger->info('lottery_cancelled', ['message' => "Admin " . user_id() . " cancelled round #{$id}"]);

        return $this->response->json(['success' => true, 'message' => 'دوره لغو شد.']);
    }
}