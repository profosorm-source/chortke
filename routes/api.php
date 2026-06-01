<?php

use App\Controllers\Api\TokenController;
use App\Controllers\Api\UserController as ApiUserController;
use App\Controllers\Api\WalletController as ApiWalletController;
use App\Controllers\Api\SocialTaskApiController;
use App\Controllers\Api\InfluencerController as ApiInfluencerController;
use App\Controllers\Api\InteractionApiController;
use App\Middleware\ApiAuthMiddleware;

$r = app()->router;

/**
 * ─────────────────────────────
 * API v1 ROOT GROUP
 * ─────────────────────────────
 */
$r->group(['prefix' => '/api/v1'], function ($r) {

    /**
     * HEALTH CHECKS
     */
    $r->get('/health/live', [\App\Controllers\Api\HealthCheckController::class, 'live']);
    $r->get('/health/ready', [\App\Controllers\Api\HealthCheckController::class, 'ready']);

    /**
     * AUTH (Public)
     */
    $r->post('/auth/token', [TokenController::class, 'issue'], [\App\Middleware\RateLimitMiddleware::class]);

    /**
     * AUTH (Protected)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':auth.manage']], function ($r) {
        $r->post('/auth/revoke', [TokenController::class, 'revoke']);
        $r->get('/auth/tokens', [TokenController::class, 'list']);
        $r->post('/auth/tokens/{id}/revoke', [TokenController::class, 'revokeById']);
    });

    /**
     * USER
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':user.read']], function ($r) {
        $r->get('/user/profile', [ApiUserController::class, 'profile']);
        $r->get('/user/notifications', [ApiUserController::class, 'notifications']);
    });
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':user.write']], function ($r) {
        $r->post('/user/notifications/read', [ApiUserController::class, 'markRead']);
    });

    /**
     * WALLET
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':wallet.read']], function ($r) {
        $r->get('/wallet', [ApiWalletController::class, 'balance']);
        $r->get('/wallet/transactions', [ApiWalletController::class, 'transactions']);
    });

    /**
     * INFLUENCER MARKETPLACE (Read)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':influencer.read']], function ($r) {
        $r->get('/influencer/profile', [ApiInfluencerController::class, 'myProfile']);
        $r->get('/influencer/list', [ApiInfluencerController::class, 'getList']);
        $r->get('/influencer/{id}', [ApiInfluencerController::class, 'show']);
        $r->get('/influencer/orders/placed', [ApiInfluencerController::class, 'myPlacedOrders']);
        $r->get('/influencer/orders/received', [ApiInfluencerController::class, 'receivedOrders']);
        $r->get('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'getDispute']);
    });

    /**
     * INFLUENCER MARKETPLACE (Write)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':influencer.write']], function ($r) {
        $r->post('/influencer/profile', [ApiInfluencerController::class, 'saveProfile']);
        $r->post('/influencer/profile/verify', [ApiInfluencerController::class, 'submitVerification']);
        $r->post('/influencer/orders', [ApiInfluencerController::class, 'createOrder']);
        $r->post('/influencer/orders/{id}/confirm', [ApiInfluencerController::class, 'buyerConfirm']);
        $r->post('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'buyerDispute']);
        $r->post('/influencer/orders/{id}/respond', [ApiInfluencerController::class, 'respondOrder']);
        $r->post('/influencer/orders/{id}/proof', [ApiInfluencerController::class, 'submitProof']);
        $r->post('/influencer/orders/{id}/dispute/message', [ApiInfluencerController::class, 'sendDisputeMessage']);
        $r->post('/influencer/orders/{id}/dispute/escalate', [ApiInfluencerController::class, 'escalateDispute']);
        $r->post('/influencer/orders/{id}/dispute/resolve', [ApiInfluencerController::class, 'resolveDispute']);
    });

    /**
     * SOCIAL TASK SYSTEM (Read)
     */
    $r->group(['prefix' => '/social', 'middleware' => [ApiAuthMiddleware::class . ':social.read']], function ($r) {
        $r->get('/accounts', [SocialTaskApiController::class, 'accounts']);
        $r->get('/ads', [SocialTaskApiController::class, 'myAds']);
        $r->get('/ads/{id}', [SocialTaskApiController::class, 'showAd']);
        $r->get('/tasks', [SocialTaskApiController::class, 'tasks']);
        $r->get('/tasks/history', [SocialTaskApiController::class, 'history']);
        $r->get('/disputes', [SocialTaskApiController::class, 'disputes']);
    });

    /**
     * SOCIAL TASK SYSTEM (Write)
     */
    $r->group(['prefix' => '/social', 'middleware' => [ApiAuthMiddleware::class . ':social.write']], function ($r) {
        $r->post('/accounts', [SocialTaskApiController::class, 'storeAccount']);
        $r->put('/accounts/{id}', [SocialTaskApiController::class, 'updateAccount']);
        $r->delete('/accounts/{id}', [SocialTaskApiController::class, 'deleteAccount']);
        $r->post('/ads', [SocialTaskApiController::class, 'createAd']);
        $r->post('/ads/{id}/pause', [SocialTaskApiController::class, 'pauseAd']);
        $r->post('/ads/{id}/resume', [SocialTaskApiController::class, 'resumeAd']);
        $r->post('/ads/{id}/cancel', [SocialTaskApiController::class, 'cancelAd']);
        $r->post('/tasks/{id}/start', [SocialTaskApiController::class, 'startTask']);
        $r->post('/tasks/{id}/submit', [SocialTaskApiController::class, 'submitTask']);
        $r->post('/executions/{id}/dispute', [SocialTaskApiController::class, 'openDispute']);
    });

    /**
     * REAL-TIME INFRASTRUCTURE
     * ✅ WebSocket + Long Polling support
     */
    $r->group(['prefix' => '/real-time', 'middleware' => [ApiAuthMiddleware::class . ':realtime']], function ($r) {
        $r->post('/poll', [\App\Controllers\Api\RealTimeController::class, 'poll']);
        $r->post('/rooms/join', [\App\Controllers\Api\RealTimeController::class, 'joinRoom']);
        $r->post('/rooms/leave', [\App\Controllers\Api\RealTimeController::class, 'leaveRoom']);
        $r->get('/rooms/{room}/members', [\App\Controllers\Api\RealTimeController::class, 'getRoomMembers']);
        $r->get('/presence/online', [\App\Controllers\Api\RealTimeController::class, 'getOnlineUsers']);
        $r->get('/presence/online/{room}', [\App\Controllers\Api\RealTimeController::class, 'getOnlineInRoom']);
        $r->get('/stats', [\App\Controllers\Api\RealTimeController::class, 'getStats']);
    });

    /**
     * VERIFICATION SYSTEM
     * ✅ Influencer verification without external APIs
     */
    $r->group(['prefix' => '/verification', 'middleware' => [ApiAuthMiddleware::class . ':verification.read']], function ($r) {
        $r->get('/status', [\App\Controllers\Api\VerificationController::class, 'getStatus']);
        $r->get('/history', [\App\Controllers\Api\VerificationController::class, 'getHistory']);
    });

    $r->group(['prefix' => '/verification', 'middleware' => [ApiAuthMiddleware::class . ':verification.write']], function ($r) {
        $r->post('/generate-code', [\App\Controllers\Api\VerificationController::class, 'generateCode']);
        $r->post('/submit-proof', [\App\Controllers\Api\VerificationController::class, 'submitProof']);
    });

    /**
     * INTERACTIONS (Polymorphic Likes, Ratings, Reports)
     */
    $r->group(['prefix' => '/interactions', 'middleware' => [ApiAuthMiddleware::class . ':user.write']], function ($r) {
        $r->post('/favorite/toggle', [InteractionApiController::class, 'toggleFavorite']);
        $r->post('/rate', [InteractionApiController::class, 'rate']);
        $r->post('/report', [InteractionApiController::class, 'report']);
    });

    /**
     * SECURITY ENDPOINTS
     */
    $r->post('/security/csp-report', [\App\Controllers\Api\SecurityController::class, 'cspReport'], [\App\Middleware\RateLimitMiddleware::class]);

});