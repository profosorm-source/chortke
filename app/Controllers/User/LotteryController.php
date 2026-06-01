<?php
// app/Controllers/User/LotteryController.php

namespace App\Controllers\User;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Services\Lottery\LotteryService;
use App\Services\Lottery\LotteryParticipationService;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class LotteryController extends BaseUserController
{
    private \App\Models\LotteryVote $lotteryVoteModel;
    private \App\Models\LotteryRound $lotteryRoundModel;
    private \App\Models\LotteryParticipation $lotteryParticipationModel;
    private \App\Models\LotteryDailyNumber $lotteryDailyNumberModel;
    private LotteryService $lotteryService;
    private LotteryParticipationService $participationService;

    public function __construct(
        \App\Models\LotteryDailyNumber $lotteryDailyNumberModel,
        \App\Models\LotteryParticipation $lotteryParticipationModel,
        \App\Models\LotteryRound $lotteryRoundModel,
        \App\Models\LotteryVote $lotteryVoteModel,
        \App\Services\Lottery\LotteryService $lotteryService,
        \App\Services\Lottery\LotteryParticipationService $participationService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);

        $this->lotteryDailyNumberModel = $lotteryDailyNumberModel;
        $this->lotteryParticipationModel = $lotteryParticipationModel;
        $this->lotteryRoundModel = $lotteryRoundModel;
        $this->lotteryVoteModel = $lotteryVoteModel;
        $this->lotteryService = $lotteryService;
        $this->participationService = $participationService;
    }

    public function index()
    {
        $userId = user_id();
        $roundModel = $this->lotteryRoundModel;
        $participationModel = $this->lotteryParticipationModel;
        $dailyModel = $this->lotteryDailyNumberModel;
        $voteModel = $this->lotteryVoteModel;
        $activeRound = $roundModel->getActiveRound();
        $participation = null;
        $todayNumbers = null;
        $userVote = null;
        $distribution = null;
        $dailyHistory = [];

        if ($activeRound) {
            $participation = $participationModel->findByUserAndRound($userId, $activeRound->id);
            $todayNumbers = $dailyModel->getToday($activeRound->id);
            $distribution = $participationModel->getChanceDistribution($activeRound->id);
            $dailyHistory = $dailyModel->getByRound($activeRound->id);

            if ($todayNumbers && $participation) {
                $userVote = $voteModel->getUserVote($userId, $todayNumbers->id);
            }
        }

        $completedRounds = $roundModel->getCompletedRounds(5);
        $myParticipations = $participationModel->getByUser($userId, 10);

        $user = auth()->user();

        return view('user.lottery.index', [
            'user' => $user,
            'activeRound' => $activeRound,
            'participation' => $participation,
            'todayNumbers' => $todayNumbers,
            'userVote' => $userVote,
            'distribution' => $distribution,
            'dailyHistory' => $dailyHistory,
            'completedRounds' => $completedRounds,
            'myParticipations' => $myParticipations,
            'transparencyText' => $this->lotteryService->getTransparencyText(),
        ]);
    }

    public function join()
    {
        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = \Core\Validator::create($input, [
            'round_id' => 'required|numeric|min:1',
            'idempotency_key' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->data();
        $roundId = (int)($data['round_id'] ?? 0);
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? '')) ?: null;

        $result = $this->participationService->participate(user_id(), $roundId, $idempotencyKey);
        ApiRateLimiter::enforce('lottery_participate', (int)user_id(), true);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function vote()
    {
        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = \Core\Validator::create($input, [
            'round_id' => 'required|numeric|min:1',
            'voted_number' => 'required|integer|min:0|max:9',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->data();
        $roundId = (int)($data['round_id'] ?? 0);
        $votedNumber = (int)($data['voted_number'] ?? -1);

        $result = $this->participationService->vote(user_id(), $roundId, $votedNumber);
        ApiRateLimiter::enforce('lottery_vote', (int)user_id(), true);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }
}
