<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\PredictionGame;
use App\Models\PredictionBet;
use App\Services\PredictionService;

class PredictionController extends BaseUserController
{
    public function __construct(
        private PredictionGame    $gameModel,
        private PredictionBet     $betModel,
        private PredictionService $predictionService
    ) {
        parent::__construct();
    }

    // ─── لیست بازی‌های باز ────────────────────────────────────────────
    public function index(): void
    {
        $userId = (int)user_id();
        $games  = $this->gameModel->getOpen(20, 0);

        // وضعیت شرط کاربر برای هر بازی
        $userBets = [];
        foreach ($games as $g) {
            $userBets[(int)$g->id] = $this->betModel->userHasBet($userId, (int)$g->id);
        }

        view('user/prediction/index', [
            'title'    => 'پیش‌بینی بازی‌های ورزشی',
            'games'    => $games,
            'userBets' => $userBets,
        ]);
    }

    // ─── صفحه جزئیات بازی + فرم شرط‌بندی ────────────────────────────
    public function show(): void
    {
        $id   = (int)$this->request->param('id');
        $game = $this->gameModel->find($id);

        if (!$game || $game->deleted_at) {
            $this->session->setFlash('error', 'بازی یافت نشد.');
            redirect(url('/prediction'));
            return;
        }

        $userId = (int)user_id();
        $hasBet = $this->betModel->userHasBet($userId, $id);
        $myBet  = null;

        if ($hasBet) {
            $myBets = $this->betModel->getByUser($userId, 1, 0);
            foreach ($myBets as $b) {
                if ((int)$b->game_id === $id) {
                    $myBet = $b;
                    break;
                }
            }
        }

        view('user/prediction/show', [
            'title'  => 'پیش‌بینی: ' . e($game->title),
            'game'   => $game,
            'hasBet' => $hasBet,
            'myBet'  => $myBet,
        ]);
    }

    // ─── ثبت شرط ──────────────────────────────────────────────────────
    public function placeBet(): void
    {
        $userId     = (int)user_id();
        $gameId     = (int)$this->request->param('id');
        $prediction = trim((string)($this->request->post('prediction') ?? ''));
        $amount     = (float)($this->request->post('amount_usdt') ?? 0);

        try {
            $result = $this->predictionService->placeBet($userId, $gameId, $prediction, $amount);
            $this->response->json($result);

        } catch (\InvalidArgumentException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('prediction.placeBet.failed', [
                'user_id' => $userId,
                'game_id' => $gameId,
                'error'   => $e->getMessage(),
            ]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    // ─── تاریخچه شرط‌های کاربر ────────────────────────────────────────
    public function myBets(): void
    {
        $userId  = (int)user_id();
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $bets  = $this->betModel->getByUser($userId, $perPage, $offset);
        $total = $this->betModel->countByUser($userId);

        view('user/prediction/my-bets', [
            'title'      => 'پیش‌بینی‌های من',
            'bets'       => $bets,
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ]);
    }
}
