<?php

declare(strict_types=1);

namespace App\Adapters;

/**
 * KycFaceVerificationAdapter Interface
 * Handles AI-based validation of identity verification images.
 */
interface KycFaceVerificationAdapter
{
    /**
     * Verify an uploaded image containing a user face and ID document.
     *
     * @param string $absoluteFilePath The local path to the uploaded image.
     * @return array ['success' => bool, 'is_valid' => bool, 'confidence' => float, 'ai_notes' => string]
     */
    public function analyzeImage(string $absoluteFilePath): array;

    /**
     * Checks if the external AI microservice is properly configured to function.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}


