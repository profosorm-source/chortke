<?php
namespace Helpers;

/**
 * کلاس تبدیل تاریخ میلادی به شمسی و بالعکس
 */
class JalaliDate
{
    /**
     * تبدیل میلادی به شمسی
     */
    public static function toJalali($timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $gregorianDate = getdate($timestamp);
        
        list($jYear, $jMonth, $jDay) = self::gregorianToJalali(
            $gregorianDate['year'],
            $gregorianDate['mon'],
            $gregorianDate['mday']
        );
        
        return [$jYear, $jMonth, $jDay];
    }

    /**
     * فرمت کردن تاریخ شمسی
     */
    public static function format($format, $timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        list($jYear, $jMonth, $jDay) = self::toJalali($timestamp);
        
        $monthNames = [
            1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
        ];
        
        $dayNames = [
            'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'
        ];
        
        $dayOfWeek = $dayNames[date('w', $timestamp)];
        
        $replacements = [
            'Y' => $jYear,
            'm' => str_pad($jMonth, 2, '0', STR_PAD_LEFT),
            'd' => str_pad($jDay, 2, '0', STR_PAD_LEFT),
            'H' => date('H', $timestamp),
            'i' => date('i', $timestamp),
            's' => date('s', $timestamp),
            'F' => $monthNames[$jMonth],
            'l' => $dayOfWeek,
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $format);
    }

    /**
     * تبدیل شمسی به میلادی
     */
    public static function toGregorian($jalaliDate)
    {
        // فرمت: 1402/12/15 یا 1402-12-15
        $parts = preg_split('/[-\/]/', $jalaliDate);
        
        if (count($parts) !== 3) {
            throw new \Exception('Invalid Jalali date format');
        }
        
        list($jYear, $jMonth, $jDay) = $parts;
        
        list($gYear, $gMonth, $gDay) = self::jalaliToGregorian($jYear, $jMonth, $jDay);
        
        return $gYear . '-' . str_pad($gMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($gDay, 2, '0', STR_PAD_LEFT);
    }

    /**
     * الگوریتم تبدیل میلادی به شمسی
     */
    private static function gregorianToJalali($gYear, $gMonth, $gDay)
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        
        $gy = $gYear - 1600;
        $gm = $gMonth - 1;
        $gd = $gDay - 1;
        
        $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
        
        for ($i = 0; $i < $gm; ++$i) {
            $gDayNo += $gDaysInMonth[$i];
        }
        
        if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
            $gDayNo++;
        }
        
        $gDayNo += $gd;
        $jDayNo = $gDayNo - 79;
        $jNp = floor($jDayNo / 12053);
        $jDayNo %= 12053;
        $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
        $jDayNo %= 1461;
        
        if ($jDayNo >= 366) {
            $jy += floor(($jDayNo - 1) / 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }
        
        for ($i = 0; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; ++$i) {
            $jDayNo -= $jDaysInMonth[$i];
        }
        
        $jm = $i + 1;
        $jd = $jDayNo + 1;
        
        return [$jy, $jm, $jd];
    }

    /**
     * الگوریتم تبدیل شمسی به میلادی
     */
    private static function jalaliToGregorian($jYear, $jMonth, $jDay)
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        
        $jy = $jYear - 979;
        $jm = $jMonth - 1;
        $jd = $jDay - 1;
        
        $jDayNo = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
        
        for ($i = 0; $i < $jm; ++$i) {
            $jDayNo += $jDaysInMonth[$i];
        }
        
        $jDayNo += $jd;
        $gDayNo = $jDayNo + 79;
        $gy = 1600 + 400 * floor($gDayNo / 146097);
        $gDayNo %= 146097;
        
        $leap = true;
        if ($gDayNo >= 36525) {
            $gDayNo--;
            $gy += 100 * floor($gDayNo / 36524);
            $gDayNo %= 36524;
            
            if ($gDayNo >= 365) {
                $gDayNo++;
            }
            $leap = false;
        }
        
        $gy += 4 * floor($gDayNo / 1461);
        $gDayNo %= 1461;
        
        if ($gDayNo >= 366) {
            $leap = false;
            $gDayNo--;
            $gy += floor($gDayNo / 365);
            $gDayNo %= 365;
        }
        
        for ($i = 0; $gDayNo >= $gDaysInMonth[$i] + ($i == 1 && $leap ? 1 : 0); $i++) {
            $gDayNo -= $gDaysInMonth[$i] + ($i == 1 && $leap ? 1 : 0);
        }
        
        $gm = $i + 1;
        $gd = $gDayNo + 1;
        
        return [$gy, $gm, $gd];
    }
}