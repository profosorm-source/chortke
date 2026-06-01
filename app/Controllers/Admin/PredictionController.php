<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\PredictionGame;
use App\Models\PredictionBet;
use App\Services\PredictionService;

class PredictionController extends BaseAdminController
{
    private const SPORT_TYPES = [
        'football'   => 'فوتبال',
        'basketball' => 'بسکتبال',
        'tennis'     => 'تنیس',
        'volleyball' => 'والیبال',
        'baseball'   => 'بیسبال',
        'hockey'     => 'هاکی',
        'cricket'    => 'کریکت',
        'other'      => 'سایر',
    ];

    private PredictionGame $gameModel;
    private PredictionBet $betModel;
    private PredictionService $predictionService;
    public function __construct(
        PredictionGame $gameModel,
        PredictionBet $betModel,
        PredictionService $predictionService
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->gameModel = $gameModel;
        $this->betModel = $betModel;
        $this->predictionService = $predictionService;

        parent::__construct(null, null, null, null, $logger);
    }

    // ─── لیست بازی‌ها ─────────────────────────────────────────────────
    public function index(): void
    {
        $filters = [
            'status'     => $this->request->get('status', ''),
            'sport_type' => $this->request->get('sport_type', ''),
            'search'     => trim((string)$this->request->get('search', '')),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;

        $games = $this->gameModel->adminList($filters, $perPage, $offset);
        $total = $this->gameModel->adminCount($filters);

        view('admin/prediction/index', [
            'title'      => 'مدیریت پیش‌بینی بازی‌ها',
            'games'      => $games,
            'filters'    => $filters,
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'sportTypes' => self::SPORT_TYPES,
        ]);
    }

    // ─── فرم تعریف بازی ───────────────────────────────────────────────
    public function create(): void
    {
        view('admin/prediction/create', [
            'title'      => 'تعریف بازی جدید',
            'sportTypes' => self::SPORT_TYPES,
        ]);
    }

    // ─── ذخیره بازی جدید ──────────────────────────────────────────────
    public function store(): void
    {
        $data = $this->request->body();

        $request = new \App\Validators\Requests\CreatePredictionGameRequest($data);

        if (!$request->validate()) {
            $errors = [];
            foreach ($request->errors() as $fieldErrors) {
                foreach ((array)$fieldErrors as $err) {
                    $errors[] = $err;
                }
            }
            $this->session->setFlash('errors', $errors);
            $this->session->setFlash('old', $data);
            redirect(url('/admin/prediction/create'));
            return;
        }

        $validatedData = $request->validated();

        $game = $this->gameModel->create(array_merge($validatedData, [
            'created_by'         => (int)user_id(),
            'min_bet_usdt'       => (float)($validatedData['min_bet_usdt'] ?? 1),
            'max_bet_usdt'       => (float)($validatedData['max_bet_usdt'] ?? 1000),
            'commission_percent' => (float)($validatedData['commission_percent'] ?? setting('prediction_commission_percent', 5)),
        ]));

        if (!$game) {
            $this->session->setFlash('error', 'خطا در ثبت بازی.');
            redirect(url('/admin/prediction/create'));
            return;
        }

        $this->session->setFlash('success', 'بازی با موفقیت تعریف شد.');
        redirect(url("/admin/prediction/{$game->id}"));
    }

    // ─── جزئیات بازی ──────────────────────────────────────────────────
    public function show(): void
    {
        $id   = (int)$this->request->param('id');
        $game = $this->gameModel->find($id);

        if (!$game) {
            $this->session->setFlash('error', 'بازی یافت نشد.');
            redirect(url('/admin/prediction'));
            return;
        }

        $bets = $this->betModel->getByGame($id);
        $dist = $this->betModel->getDistribution($id);

        view('admin/prediction/show', [
            'title' => 'جزئیات بازی: ' . $game->title,
            'game'  => $game,
            'bets'  => $bets,
            'dist'  => $dist,
        ]);
    }

    // ─── ثبت نتیجه + پرداخت یکجا (atomic) ────────────────────────────
    public function settle(): void
    {
        $id     = (int)$this->request->param('id');
        $result = trim((string)($this->request->post('result') ?? ''));

        if (!in_array($result, ['home', 'away', 'draw'], true)) {
            $this->response->json(['success' => false, 'message' => 'نتیجه نامعتبر است.']);
            return;
        }

        try {
            $summary = $this->predictionService->settleGame($id, $result, (int)user_id());

            $this->logger->activity('prediction.settled', "تسویه بازی #{$id} با نتیجه: {$result}", (int)user_id(), $summary['summary'] ?? []);

            $s = $summary['summary'];
            $msg = "نتیجه ثبت شد. به {$s['winners_paid']} برنده پرداخت شد.";
            if (!empty($s['no_winners'])) {
                $msg = "نتیجه ثبت شد. برنده‌ای وجود نداشت — شرط‌ها برگشت داده شدند.";
            }

            $this->response->json(['success' => true, 'message' => $msg, 'summary' => $s]);

        } catch (\InvalidArgumentException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('prediction.settle.failed', ['id' => $id, 'error' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    // ─── لغو بازی ─────────────────────────────────────────────────────
    public function cancel(): void
    {
        $id = (int)$this->request->param('id');

        try {
            $result = $this->predictionService->cancelGame($id, (int)user_id());

            $this->logger->activity('prediction.cancelled', "لغو بازی #{$id}", (int)user_id(), ['refunded_count' => $result['refunded_count']] ?? []);

            $this->response->json($result);

        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('prediction.cancel.failed', ['id' => $id, 'error' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ─── بستن شرط‌گیری (بدون تغییر نتیجه) ────────────────────────────
    public function closeBetting(): void
    {
        $id = (int)$this->request->param('id');
        $ok = $this->gameModel->closeBetting($id);

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'شرط‌گیری بسته شد.' : 'عملیات انجام نشد.',
        ]);
    }

    // ─── ویرایش بازی ──────────────────────────────────────────────────
    public function update(): void
    {
        $id = (int)$this->request->param('id');
        $game = $this->gameModel->find($id);

        if (!$game) {
            $this->response->json(['success' => false, 'message' => 'بازی یافت نشد.']);
            return;
        }

        $data = $this->request->body() ?? [];

        $betCount = $this->betModel->countByGame($id);
        if ($betCount > 0) {
            // P-1: Only allow updating specific fields if bets exist to prevent changing names/bet limits mid-game
            $allowedFields = ['description', 'status'];
            $data = array_intersect_key($data, array_flip($allowedFields));
        }

        if (empty($data)) {
            $this->response->json(['success' => false, 'message' => 'هیچ فیلد معتبری برای بروزرسانی ارسال نشده است یا بازی دارای شرط فعال است.']);
            return;
        }

        // Validate only if there are fields requiring validation
        if (isset($data['title']) || isset($data['team_home']) || isset($data['team_away']) || isset($data['match_date']) || isset($data['bet_deadline'])) {
            $merged = array_merge((array)$game, $data);
            $request = new \App\Validators\Requests\CreatePredictionGameRequest($merged);
            if (!$request->validate()) {
                $errors = [];
                foreach ($request->errors() as $fieldErrors) {
                    foreach ((array)$fieldErrors as $err) {
                        $errors[] = $err;
                    }
                }
                $this->response->json(['success' => false, 'errors' => $errors]);
                return;
            }
        }

        $ok = $this->gameModel->update($id, $data);

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'بازی با موفقیت بروزرسانی شد.' : 'تغییری اعمال نشد یا خطایی رخ داد.',
        ]);
    }


}
