<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Interaction\FavoriteService;
use App\Services\Interaction\RatingService;
use App\Services\Interaction\ReportService;
use App\Enums\ModuleContext;
use Core\Validator;

/**
 * InteractionApiController - نقطه پایانی واحد برای تمامی تعاملات (لایک، امتیاز، ریپورت) به صورت پلیمورفیک
 */
class InteractionApiController extends BaseApiController
{
    private FavoriteService $favoriteService;
    private RatingService $ratingService;
    private ReportService $reportService;

    public function __construct(
        FavoriteService $favoriteService,
        RatingService $ratingService,
        ReportService $reportService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->favoriteService = $favoriteService;
        $this->ratingService = $ratingService;
        $this->reportService = $reportService;
    }

    /**
     * POST /api/v1/interactions/favorite/toggle
     */
    public function toggleFavorite(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();

        $validator = Validator::create($body, [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'context' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, $validator->errors());
        }

        $data = $validator->data();
        $context = ModuleContext::tryFrom($data['context']) ?? ModuleContext::GLOBAL;

        $success = $this->favoriteService->toggle(
            $user->id,
            $data['type'],
            (int)$data['id'],
            $context
        );

        if ($success) {
            $hasFavorited = $this->favoriteService->hasFavorited($user->id, $data['type'], (int)$data['id']);
            $this->success([
                'message' => 'عملیات با موفقیت انجام شد.',
                'is_favorited' => $hasFavorited
            ]);
        } else {
            $this->error('خطا در ثبت علاقه‌مندی.', 500);
        }
    }

    /**
     * POST /api/v1/interactions/rate
     */
    public function rate(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();

        $validator = Validator::create($body, [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'rating' => 'required|integer|min:1|max:5',
            'context' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, $validator->errors());
        }

        $data = $validator->data();
        $context = ModuleContext::tryFrom($data['context']) ?? ModuleContext::GLOBAL;

        $success = $this->ratingService->rate(
            $user->id,
            $data['type'],
            (int)$data['id'],
            $context,
            (int)$data['rating']
        );

        if ($success) {
            $average = $this->ratingService->getAverageRating($data['type'], (int)$data['id']);
            $this->success([
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'average_rating' => $average
            ]);
        } else {
            $this->error('خطا در ثبت امتیاز.', 500);
        }
    }

    /**
     * POST /api/v1/interactions/report
     */
    public function report(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();

        $validator = Validator::create($body, [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'reason' => 'required|string|min:3|max:100',
            'description' => 'nullable|string|max:1000',
            'context' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, $validator->errors());
        }

        $data = $validator->data();
        $context = ModuleContext::tryFrom($data['context']) ?? ModuleContext::GLOBAL;

        $success = $this->reportService->submit(
            $user->id,
            $data['type'],
            (int)$data['id'],
            $context,
            $data['reason'],
            $data['description'] ?? null
        );

        if ($success) {
            $this->success([
                'message' => 'گزارش شما با موفقیت ثبت شد و بررسی خواهد شد.'
            ]);
        } else {
            $this->error('خطا در ثبت گزارش.', 500);
        }
    }
}
