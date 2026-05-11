<?php

// ═══════════════════════════════════════════════════════════════════════════
// CustomTaskSubmission
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// StoryOrder
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// SEOExecution
// ═══════════════════════════════════════════════════════════════════════════

/**
 * نام فارسی وضعیت اجرای SEO
 */
function seo_execution_status_label(string $status): string
{
    $labels = [
        'pending'   => 'در انتظار',
        'approved'  => 'تایید شده',
        'rejected'  => 'رد شده',
        'expired'   => 'منقضی',
    ];
    return $labels[$status] ?? $status;
}

/**
 * کلاس CSS badge وضعیت اجرای SEO
 */
function seo_execution_status_badge(string $status): string
{
    $badges = [
        'pending'  => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'expired'  => 'secondary',
    ];
    return $badges[$status] ?? 'secondary';
}


// ═══════════════════════════════════════════════════════════════════════════
// Map helpers (برای views که از array کامل نیاز دارند)
// ═══════════════════════════════════════════════════════════════════════════

function custom_task_status_labels_map(): array  { return ['draft'=>'پیشنویس','review_pending'=>'در انتظار بررسی','active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل‌شده','rejected'=>'رد شده','expired'=>'منقضی']; }
function custom_task_status_classes_map(): array { return ['draft'=>'badge-secondary','review_pending'=>'badge-warning','active'=>'badge-success','paused'=>'badge-info','completed'=>'badge-primary','rejected'=>'badge-danger','expired'=>'badge-danger']; }
function story_order_status_labels_map(): array  { return ['pending'=>'در انتظار','active'=>'فعال','completed'=>'تکمیل‌شده','cancelled'=>'لغو شده','rejected'=>'رد شده']; }
function story_order_status_classes_map(): array { return ['pending'=>'badge-warning','active'=>'badge-success','completed'=>'badge-primary','cancelled'=>'badge-secondary','rejected'=>'badge-danger']; }
