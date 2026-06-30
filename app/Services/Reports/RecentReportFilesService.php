<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\Carbon;

class RecentReportFilesService
{
    /**
     * يرجع ملفات PDF المولدة للمتجر خلال آخر عدد محدد من الأيام.
     */
    public function recentForStore(Store $store, int $days = 10): array
    {
        $reportsFolder = public_path('reports/');
        $cutoffDate = now()->subDays($days);
        $reportFiles = collect();

        if (is_dir($reportsFolder)) {
            $reportPathPattern = $reportsFolder . 'Report_*_' . $store->id . '.pdf';
            $reportPaths = glob($reportPathPattern) ?: [];

            $reportFiles = collect($reportPaths)
                ->map(function ($reportPath) {
                    return [
                        'name' => basename($reportPath),
                        'url' => url('reports/' . basename($reportPath)),
                        'created_at' => Carbon::createFromTimestamp(filemtime($reportPath)),
                        'size_kb' => round(filesize($reportPath) / 1024, 2),
                    ];
                })
                ->filter(fn ($reportFile) => $reportFile['created_at']->greaterThanOrEqualTo($cutoffDate))
                ->sortByDesc('created_at')
                ->values();
        }

        return [
            'reports' => $reportFiles,
            'cutoffDate' => $cutoffDate,
        ];
    }
}
