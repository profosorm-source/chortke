<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exceptions Index - تمام Custom Exceptions کا ایک ریفرنس
 * 
 * یہ فائل تمام exceptions کو document کرتی ہے اور ان کا ریفرنس دیتی ہے۔
 */

// ════════════════════════════════════════════════════════════════════════════
// Payment Gateway Exceptions
// ════════════════════════════════════════════════════════════════════════════

/**
 * PaymentGatewayException
 * @see \App\Exceptions\PaymentGatewayException
 * 
 * Root exception برائے تمام payment gateway errors
 * - Gateway configuration missing
 * - Invalid gateway type
 * - API errors
 */

/**
 * PaymentGatewayConnectionException
 * @see \App\Exceptions\PaymentGatewayConnectionException
 * 
 * Connection-related errors
 * - Connection timeout (503)
 * - SSL verification failed
 * - Gateway unreachable
 * - Network error
 */

/**
 * PaymentVerificationException
 * @see \App\Exceptions\PaymentVerificationException
 * 
 * Payment verification failures (402)
 * - Invalid transaction ID
 * - Amount mismatch
 * - Signature verification failed
 * - Transaction not found
 */

// ════════════════════════════════════════════════════════════════════════════
// OAuth Exceptions
// ════════════════════════════════════════════════════════════════════════════

/**
 * OAuthException
 * @see \App\Exceptions\OAuthException
 * 
 * OAuth/Social login errors (401)
 * - Token invalid or expired
 * - User not found
 * - Insufficient permissions
 * - Account linking failed
 * - Provider connection error
 */

// ════════════════════════════════════════════════════════════════════════════
// Session Exceptions
// ════════════════════════════════════════════════════════════════════════════

/**
 * SessionException
 * @see \App\Exceptions\SessionException
 * 
 * Session management errors (419)
 * - Session expired (REASON_EXPIRED)
 * - Invalid session ID (REASON_INVALID)
 * - Hijacking detected (REASON_HIJACKING) [terminates session]
 * - Anomalous behavior (REASON_ANOMALY) [terminates session]
 * - Session timeout (REASON_TIMEOUT)
 */

// ════════════════════════════════════════════════════════════════════════════
// Usage Examples
// ════════════════════════════════════════════════════════════════════════════

/**
 * Example 1: Payment Gateway Flow
 * 
 * try {
 *     $gateway = $factory->create('zarinpal');
 *     $result = $gateway->createPayment($amount, $desc, $url);
 * } catch (PaymentGatewayConnectionException $e) {
 *     // Retry logic
 *     $logger->warning('Gateway timeout, retrying...', ['gateway' => $e]);
 * } catch (PaymentGatewayException $e) {
 *     // Generic payment gateway error
 *     $logger->error('Payment failed', ['error' => $e]);
 * }
 */

/**
 * Example 2: OAuth Login
 * 
 * try {
 *     $user = $this->oauth->getUserInfo($provider, $token);
 * } catch (OAuthException $e) {
 *     $logger->warning('OAuth failed', ['provider' => $provider, 'error' => $e]);
 *     return redirect()->route('login');
 * }
 */

/**
 * Example 3: Session Validation
 * 
 * try {
 *     $this->session->validate($sessionId);
 * } catch (SessionException $e) {
 *     if ($e->shouldTerminateSession()) {
 *         $this->session->destroy($sessionId);
 *         $logger->warning('Session terminated', ['reason' => $e->getReason()]);
 *     }
 *     throw $e;
 * }
 */

// ════════════════════════════════════════════════════════════════════════════
// Exception Hierarchy
// ════════════════════════════════════════════════════════════════════════════

/**
 * Exception (PHP built-in)
 *   ├── PaymentGatewayException (500)
 *   │   ├── PaymentGatewayConnectionException (503)
 *   │   └── PaymentVerificationException (402)
 *   ├── OAuthException (401)
 *   └── SessionException (419)
 */

// ════════════════════════════════════════════════════════════════════════════
// HTTP Status Codes
// ════════════════════════════════════════════════════════════════════════════

const EXCEPTION_HTTP_CODES = [
    'PaymentGatewayException' => 500,
    'PaymentGatewayConnectionException' => 503,
    'PaymentVerificationException' => 402,
    'OAuthException' => 401,
    'SessionException' => 419,
];

// ════════════════════════════════════════════════════════════════════════════
// Error Response Format
// ════════════════════════════════════════════════════════════════════════════

/**
 * All exceptions implement getErrorResponse():
 * 
 * [
 *     'success' => false,
 *     'error' => 'Error message',
 *     'error_code' => 500,
 *     'type' => 'exception_type',
 *     'details' => [ ... ]  // Optional, for specific exceptions
 * ]
 */
