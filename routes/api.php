<?php

use App\Controllers\Api\TokenController;
use App\Controllers\Api\UserController as ApiUserController;
use App\Controllers\Api\WalletController as ApiWalletController;
use App\Controllers\Api\SocialTaskApiController;
use App\Controllers\Api\InfluencerController as ApiInfluencerController;
use App\Middleware\ApiAuthMiddleware;

$r = app()->router;

/**
 * ─────────────────────────────
 * API v1 ROOT GROUP
 * ─────────────────────────────
 */
$r->group(['prefix' => '/api/v1'], function ($r) {

    /**
     * AUTH (Public)
     */
    $r->post('/auth/token', [TokenController::class, 'issue'], [\App\Middleware\RateLimitMiddleware::class]);

    /**
     * AUTH (Protected)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class]], function ($r) {
        $r->post('/auth/revoke', [TokenController::class, 'revoke']);
        $r->get('/auth/tokens', [TokenController::class, 'list']);
        $r->post('/auth/tokens/{id}/revoke', [TokenController::class, 'revokeById']);
    });

    /**
     * USER
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class]], function ($r) {
        $r->get('/user/profile', [ApiUserController::class, 'profile']);
        $r->get('/user/notifications', [ApiUserController::class, 'notifications']);
        $r->post('/user/notifications/read', [ApiUserController::class, 'markRead']);
    });

    /**
     * WALLET
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class]], function ($r) {
        $r->get('/wallet', [ApiWalletController::class, 'balance']);
        $r->get('/wallet/transactions', [ApiWalletController::class, 'transactions']);
    });

    /**
     * INFLUENCER MARKETPLACE
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class]], function ($r) {

        // Profile
        $r->get('/influencer/profile', [ApiInfluencerController::class, 'myProfile']);
        $r->post('/influencer/profile', [ApiInfluencerController::class, 'saveProfile']);
        $r->post('/influencer/profile/verify', [ApiInfluencerController::class, 'submitVerification']);

        // Marketplace
        $r->get('/influencer/list', [ApiInfluencerController::class, 'list']);
        $r->get('/influencer/{id}', [ApiInfluencerController::class, 'show']);

        // Orders - advertiser
        $r->post('/influencer/orders', [ApiInfluencerController::class, 'createOrder']);
        $r->get('/influencer/orders/placed', [ApiInfluencerController::class, 'myPlacedOrders']);
        $r->post('/influencer/orders/{id}/confirm', [ApiInfluencerController::class, 'buyerConfirm']);
        $r->post('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'buyerDispute']);

        // Orders - influencer
        $r->get('/influencer/orders/received', [ApiInfluencerController::class, 'receivedOrders']);
        $r->post('/influencer/orders/{id}/respond', [ApiInfluencerController::class, 'respondOrder']);
        $r->post('/influencer/orders/{id}/proof', [ApiInfluencerController::class, 'submitProof']);

        // Disputes
        $r->get('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'getDispute']);
        $r->post('/influencer/orders/{id}/dispute/message', [ApiInfluencerController::class, 'sendDisputeMessage']);
        $r->post('/influencer/orders/{id}/dispute/escalate', [ApiInfluencerController::class, 'escalateDispute']);
        $r->post('/influencer/orders/{id}/dispute/resolve', [ApiInfluencerController::class, 'resolveDispute']);

    });

    /**
     * SOCIAL TASK SYSTEM
     */
    $r->group(['prefix' => '/social', 'middleware' => [ApiAuthMiddleware::class]], function ($r) {

        // Accounts
        $r->get('/accounts', [SocialTaskApiController::class, 'accounts']);
        $r->post('/accounts', [SocialTaskApiController::class, 'storeAccount']);
        $r->put('/accounts/{id}', [SocialTaskApiController::class, 'updateAccount']);
        $r->delete('/accounts/{id}', [SocialTaskApiController::class, 'deleteAccount']);

        // Ads
        $r->get('/ads', [SocialTaskApiController::class, 'myAds']);
        $r->post('/ads', [SocialTaskApiController::class, 'createAd']);
        $r->get('/ads/{id}', [SocialTaskApiController::class, 'showAd']);
        $r->post('/ads/{id}/pause', [SocialTaskApiController::class, 'pauseAd']);
        $r->post('/ads/{id}/resume', [SocialTaskApiController::class, 'resumeAd']);
        $r->post('/ads/{id}/cancel', [SocialTaskApiController::class, 'cancelAd']);

        // Tasks
        $r->get('/tasks', [SocialTaskApiController::class, 'tasks']);
        $r->post('/tasks/{id}/start', [SocialTaskApiController::class, 'startTask']);
        $r->post('/tasks/{id}/submit', [SocialTaskApiController::class, 'submitTask']);
        $r->get('/tasks/history', [SocialTaskApiController::class, 'history']);

        // Disputes
        $r->post('/executions/{id}/dispute', [SocialTaskApiController::class, 'openDispute']);
        $r->get('/disputes', [SocialTaskApiController::class, 'disputes']);

    });

    /**
     * REAL-TIME INFRASTRUCTURE
     * ✅ WebSocket + Long Polling support
     */
    $r->group(['prefix' => '/real-time', 'middleware' => [ApiAuthMiddleware::class]], function ($r) {
        // Long Polling - Fallback for clients that don't support WebSocket
        $r->post('/poll', [\App\Controllers\Api\RealTimeController::class, 'poll']);
        
        // Room subscriptions
        $r->post('/rooms/join', [\App\Controllers\Api\RealTimeController::class, 'joinRoom']);
        $r->post('/rooms/leave', [\App\Controllers\Api\RealTimeController::class, 'leaveRoom']);
        $r->get('/rooms/{room}/members', [\App\Controllers\Api\RealTimeController::class, 'getRoomMembers']);
        
        // Presence tracking
        $r->get('/presence/online', [\App\Controllers\Api\RealTimeController::class, 'getOnlineUsers']);
        $r->get('/presence/online/{room}', [\App\Controllers\Api\RealTimeController::class, 'getOnlineInRoom']);
        
        // Real-time stats
        $r->get('/stats', [\App\Controllers\Api\RealTimeController::class, 'getStats']);
    });

    /**
     * VERIFICATION SYSTEM
     * ✅ Influencer verification without external APIs
     */
    $r->group(['prefix' => '/verification', 'middleware' => [ApiAuthMiddleware::class]], function ($r) {
        // Generate verification code
        $r->post('/generate-code', [\App\Controllers\Api\VerificationController::class, 'generateCode']);
        
        // Get verification status
        $r->get('/status', [\App\Controllers\Api\VerificationController::class, 'getStatus']);
        
        // Submit verification proof (screenshot)
        $r->post('/submit-proof', [\App\Controllers\Api\VerificationController::class, 'submitProof']);
        
        // Get verification history
        $r->get('/history', [\App\Controllers\Api\VerificationController::class, 'getHistory']);
    });

});