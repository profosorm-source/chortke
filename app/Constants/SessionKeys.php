<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * SessionKeys
 * 
 * کلیدهای ثابت برای مدیریت نشست‌ها (Session) در سراسر اپلیکیشن.
 * جهت جلوگیری از خطاهای تایپی و ناهماهنگی بین کنترلرها.
 */
class SessionKeys
{
    public const USER_ID = 'user_id';
    public const USER_ROLE = 'user_role';
    public const LOGGED_IN = 'logged_in';
    public const USERNAME = 'username';
    public const IS_ADMIN = 'is_admin';
    
    // 2FA related
    public const PENDING_2FA_USER_ID = 'pending_2fa_user_id';
    
    // Security/OAuth related
    public const CSP_NONCE = 'csp_nonce';
    public const OAUTH_STATE = 'oauth_state';
    public const OAUTH_LINKING_USER_ID = 'oauth_linking_user_id';
    
    // Auth data
    public const USER_EMAIL = 'user_email';
    
    // 2FA Setup
    public const TWO_FACTOR_SETUP_AUTHORIZED = '2fa_setup_authorized';
}
