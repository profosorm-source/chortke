<?php
// app/Controllers/User/LotteryController.php

namespace App\Controllers\User;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Services\LotteryService;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class LotteryController extends BaseUserController
{
    private \App\Models\LotteryVote $lotteryVoteModel;
    private \App\Models\LotteryRound $lotteryRoundModel;
    private \App\Models\LotteryParticipation $lotteryParticipationModel;
    private \App\Models\LotteryDailyNumber $lotteryDailyNumberModel;
    private LotteryService $lotteryService;

    public function __construct(
        \App\Models\LotteryDailyNumber $lotteryDailyNumberModel,
        \App\Models\LotteryParticipation $lotteryParticipationModel,
        \App\Models\LotteryRound $lotteryRoundModel,
        \App\Models\LotteryVote $lotteryVoteModel,
        \App\Services\LotteryService $lotteryService)
    {
        parent::__construct();
        $this->lotteryService = $lotteryService;
        $this->lotteryDailyNumberModel = $lotteryDailyNumberModel;
        $this->lotteryParticipationModel = $lotteryParticipationModel;
        $this->lotteryRoundModel = $lotteryRoundModel;
        $this->lotteryVoteModel = $lotteryVoteModel;
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
        $roundId = (int)($input['round_id'] ?? 0);

        $result = $this->lotteryService->participate(user_id(), $roundId);
        ApiRateLimiter::enforce('lottery_participate', (int)user_id(), true);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function vote()
    {
                $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $roundId = (int)($input['round_id'] ?? 0);
        $votedNumber = (int)($input['voted_number'] ?? -1);

        if ($votedNumber < 0 || $votedNumber > 9) {
            return $this->response->json(['success' => false, 'message' => 'عدد نامعتبر.'], 422);
        }

        $result = $this->lotteryService->vote(user_id(), $roundId, $votedNumber);
        ApiRateLimiter::enforce('lottery_vote', (int)user_id(), true);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }
}