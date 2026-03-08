<?php

namespace App\Modules\Reporting\Exports;

use App\Modules\Reporting\DTOs\DateRange;
use App\Modules\Reporting\Services\ReportingService;
use Illuminate\Support\Facades\Log;

/**
 * ExportService
 *
 * Generates CSV and Excel (XLSX) exports for every report type.
 *
 * Uses PHP's built-in fputcsv for CSV (zero dependencies).
 * Uses PhpSpreadsheet for XLSX — installed via Composer.
 *
 * NOTE: PhpSpreadsheet is listed as a dependency in the dev guideline
 * but may need to be added to composer.json:
 *   composer require phpoffice/phpspreadsheet
 */
class ExportService
{
    public function __construct(private ReportingService $reporting) {}

    // ── CSV ───────────────────────────────────────────────────────────────────

    /**
     * Stream a CSV directly to the HTTP response.
     * Returns a StreamedResponse — controller calls this and returns it.
     */
    public function streamCsv(string $reportType, DateRange $range, array $filters = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = $this->filename($reportType, $range, 'csv');
        $rows     = $this->getRows($reportType, $range, $filters);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if (empty($rows)) {
                fputcsv($handle, ['No data for the selected period.']);
                fclose($handle);
                return;
            }

            // Header row from first item's keys
            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Write CSV to a file path and return the path.
     * Used for scheduled report generation.
     */
    public function writeCsv(string $reportType, DateRange $range, string $path, array $filters = []): string
    {
        $rows   = $this->getRows($reportType, $range, $filters);
        $handle = fopen($path, 'w');

        if (! empty($rows)) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
        }

        fclose($handle);

        return $path;
    }

    // ── EXCEL (XLSX) ──────────────────────────────────────────────────────────

    /**
     * Generate an XLSX file, save it to a temp path, and return the path.
     * Controller calls response()->download($path, $filename) on it.
     */
    public function generateXlsx(string $reportType, DateRange $range, array $filters = []): string
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new \RuntimeException(
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet'
            );
        }

        $rows     = $this->getRows($reportType, $range, $filters);
        $filename = $this->filename($reportType, $range, 'xlsx');
        $path     = sys_get_temp_dir() . '/' . $filename;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ucwords(str_replace('_', ' ', $reportType)));

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'No data for the selected period.');
            $this->saveXlsx($spreadsheet, $path);
            return $path;
        }

        // Header row — styled
        $headers = array_keys($rows[0]);
        foreach ($headers as $col => $header) {
            $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($cellRef, strtoupper(str_replace('_', ' ', $header)));

            $sheet->getStyle($cellRef)->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3C6B']],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            ]);
        }

        // Data rows
        foreach ($rows as $rowIdx => $row) {
            $excelRow = $rowIdx + 2; // 1-indexed, row 1 is header
            $bg       = $rowIdx % 2 === 0 ? 'FFFFFF' : 'F1F5F9';

            foreach (array_values($row) as $col => $value) {
                $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $excelRow;
                $sheet->setCellValue($cellRef, $value);
                $sheet->getStyle($cellRef)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($bg);
            }
        }

        // Auto-size columns
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        // Add metadata sheet
        $meta = $spreadsheet->createSheet();
        $meta->setTitle('Report Info');
        $meta->setCellValue('A1', 'Report Type');    $meta->setCellValue('B1', ucwords(str_replace('_', ' ', $reportType)));
        $meta->setCellValue('A2', 'Period');          $meta->setCellValue('B2', $range->label());
        $meta->setCellValue('A3', 'Generated At');    $meta->setCellValue('B3', now()->toDateTimeString());
        $meta->setCellValue('A4', 'Row Count');       $meta->setCellValue('B4', count($rows));

        $this->saveXlsx($spreadsheet, $path);

        return $path;
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function getRows(string $reportType, DateRange $range, array $filters): array
    {
        return match ($reportType) {
            'sales_summary'    => [$this->reporting->salesSummary($range)->toArray()],
            'revenue_trend'    => array_map(fn($p) => $p->toArray(), $this->reporting->revenueTrend($range)),
            'vendor_performance' => array_map(fn($v) => $v->toArray(), $this->reporting->vendorPerformance($range, $filters['limit'] ?? 100)),
            'top_products'     => array_map(fn($p) => $p->toArray(), $this->reporting->topProducts($range, $filters['limit'] ?? 100, $filters['category_id'] ?? null, $filters['vendor_id'] ?? null)),
            'category_breakdown' => array_map(fn($c) => $c->toArray(), $this->reporting->categoryBreakdown($range)),
            'low_stock'        => array_map(fn($p) => (array) $p, $this->reporting->lowStockProducts()),
            default            => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
        };
    }

    private function filename(string $type, DateRange $range, string $ext): string
    {
        return sprintf(
            '%s_%s_%s.%s',
            $type,
            $range->from->format('Ymd'),
            $range->to->format('Ymd'),
            $ext
        );
    }

    private function saveXlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $path): void
    {
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }
}
