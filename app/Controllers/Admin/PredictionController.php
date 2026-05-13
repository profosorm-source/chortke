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

    public function __construct(
        private PredictionGame    $gameModel,
        private PredictionBet     $betModel,
        private PredictionService $predictionService
    ) {
        parent::__construct();
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

        // اعتبارسنجی
        $errors = $this->validateGameData($data);
        if (!empty($errors)) {
            $this->session->setFlash('errors', $errors);
            $this->session->setFlash('old', $data);
            redirect(url('/admin/prediction/create'));
            return;
        }

        $game = $this->gameModel->create(array_merge($data, [
            'created_by'         => (int)user_id(),
            'min_bet_usdt'       => (float)($data['min_bet_usdt'] ?? 1),
            'max_bet_usdt'       => (float)($data['max_bet_usdt'] ?? 1000),
            'commission_percent' => (float)($data['commission_percent'] ?? setting('prediction_commission_percent', 5)),
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

    // ─── validation ───────────────────────────────────────────────────
    private function validateGameData(array $d): array
    {
        $errors = [];

        if (empty(trim($d['title'] ?? ''))) {
            $errors[] = 'عنوان بازی الزامی است.';
        }
        if (empty(trim($d['team_home'] ?? ''))) {
            $errors[] = 'نام تیم خانه الزامی است.';
        }
        if (empty(trim($d['team_away'] ?? ''))) {
            $errors[] = 'نام تیم مهمان الزامی است.';
        }
        if (empty($d['match_date'])) {
            $errors[] = 'تاریخ بازی الزامی است.';
        }
        if (empty($d['bet_deadline'])) {
            $errors[] = 'ددلاین شرط‌بندی الزامی است.';
        }
        if (!empty($d['match_date']) && !empty($d['bet_deadline'])) {
            if (strtotime($d['bet_deadline']) >= strtotime($d['match_date'])) {
                $errors[] = 'ددلاین شرط‌بندی باید قبل از زمان بازی باشد.';
            }
            if (strtotime($d['bet_deadline']) <= time()) {
                $errors[] = 'ددلاین شرط‌بندی باید در آینده باشد.';
            }
        }

        $minBet = (float)($d['min_bet_usdt'] ?? 0);
        $maxBet = (float)($d['max_bet_usdt'] ?? 0);

        if ($minBet <= 0) {
            $errors[] = 'حداقل مبلغ شرط باید بیشتر از صفر باشد.';
        }
        if ($maxBet <= 0 || $maxBet < $minBet) {
            $errors[] = 'حداکثر مبلغ شرط باید بیشتر از حداقل باشد.';
        }

        $commission = (float)($d['commission_percent'] ?? 5);
        if ($commission < 0 || $commission > 30) {
            $errors[] = 'درصد کمیسیون باید بین ۰ و ۳۰ باشد.';
        }

        if (!array_key_exists($d['sport_type'] ?? '', self::SPORT_TYPES)) {
            $errors[] = 'نوع ورزش نامعتبر است.';
        }

        return $errors;
    }
}
