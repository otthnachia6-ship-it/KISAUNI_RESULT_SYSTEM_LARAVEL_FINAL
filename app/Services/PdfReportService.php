<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

class PdfReportService
{
    public static function shortSubjectLabel(string $name, int $maxLen = 12): string
    {
        $label = strtoupper($name);
        if (mb_strlen($label) <= $maxLen) {
            return $label;
        }
        $words = array_filter(explode(' ', $label), fn($w) => !in_array($w, ['AND', 'OF', 'THE', '&'], true));
        $short = implode(' ', array_map(fn($w) => mb_substr($w, 0, 4), $words));
        return mb_substr($short ?: $label, 0, $maxLen);
    }

    public static function gradeColour(string $letter): string
    {
        return match ($letter) {
            'A' => '#16a34a',
            'B' => '#2563eb',
            'C' => '#b45309',
            'D' => '#ea580c',
            'E' => '#dc2626',
            default => '#6c757d',
        };
    }

    public static function buildStudentReportPdf(array $data, ?string $logoAbsPath = null): string
    {
        $logoBase64 = null;
        if ($logoAbsPath && File::exists($logoAbsPath)) {
            $mime = File::mimeType($logoAbsPath) ?: 'image/png';
            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(File::get($logoAbsPath));
        }

        $pdf = Pdf::loadView('pdf.student_report', [
            'data' => $data,
            'logoBase64' => $logoBase64,
        ])->setPaper('a4', 'portrait')
          ->setOption('isHtml5ParserEnabled', true)
          ->setOption('isRemoteEnabled', true);

        return $pdf->output();
    }

    public static function buildClassResultPdf(array $data, ?string $logoAbsPath = null): string
    {
        $logoBase64 = null;
        if ($logoAbsPath && File::exists($logoAbsPath)) {
            $mime = File::mimeType($logoAbsPath) ?: 'image/png';
            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(File::get($logoAbsPath));
        }

        $pdf = Pdf::loadView('pdf.class_result', [
            'data' => $data,
            'logoBase64' => $logoBase64,
        ])->setPaper('a4', 'landscape')
          ->setOption('isHtml5ParserEnabled', true)
          ->setOption('isRemoteEnabled', true);

        return $pdf->output();
    }
}
