<?php

namespace App\Exports;

use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Sales report (.xlsx): one row per sale in the date range, then a TOTAL row.
// The TOTAL row counts COMPLETED (PAID) sales only. Voided sales are listed but never added to the money.
// This class only builds the sheet; the sales come from SaleService::salesForRange().
// WithStrictNullComparison: write 0 as 0 (₱0.00). Without it Laravel Excel treats 0 like null and leaves the cell blank.
class SalesExport implements FromArray, WithHeadings, WithColumnFormatting, ShouldAutoSize, WithStyles, WithStrictNullComparison
{
    public const NO_ROWS = 'No sales in this date range';

    // Peso money format used by all reports.
    public const PESO = '"₱"#,##0.00';

    // Row number of the TOTAL row (heading is row 1). Set in array(), used in styles().
    private int $totalRow = 2;

    public function __construct(private Collection $sales, private SaleService $saleService)
    {
    }

    public function headings(): array
    {
        return ['Sale ID', 'Date/Time', 'Cashier', 'Items', 'Subtotal', 'Discount', 'Total', 'Cash Received', 'Change', 'Status'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->sales as $sale) {
            $rows[] = [
                $sale->receiptNumber(),                                // S-0001
                Date::dateTimeToExcel($sale->sale_datetime),           // a real Excel date, not text
                $sale->cashier?->username ?? '-',
                $this->saleService->itemsSummary($sale),               // "Serum x1, Makeup x3"
                (float) $sale->subtotal,
                (float) $sale->subtotal - (float) $sale->total_amount, // discount in pesos
                (float) $sale->total_amount,
                (float) $sale->cash_received,                          // cash the customer gave
                (float) $sale->change_amount,                          // change given back
                $sale->sale_status === 'PAID' ? 'Completed' : 'Voided',
            ];
        }

        // No sales at all: one row that says so (the TOTAL row below still shows 0 and ₱0.00).
        if ($rows === []) {
            $rows[] = [self::NO_ROWS];
        }

        // TOTAL row: only PAID (completed) sales are counted and added up (0 when there are none).
        $paid = $this->sales->where('sale_status', 'PAID');
        $rows[] = [
            'TOTAL (completed only)',
            null,
            'Completed sales',
            $paid->count(),
            round($paid->sum(fn (Sale $s) => (float) $s->subtotal), 2),
            round($paid->sum(fn (Sale $s) => (float) $s->subtotal - (float) $s->total_amount), 2),
            round($paid->sum(fn (Sale $s) => (float) $s->total_amount), 2),
            round($paid->sum(fn (Sale $s) => (float) $s->cash_received), 2),
            round($paid->sum(fn (Sale $s) => (float) $s->change_amount), 2),
            null,
        ];

        $this->totalRow = count($rows) + 1; // +1 for the heading row

        return $rows;
    }

    // Column B is a date and time, E to I are money (Subtotal, Discount, Total, Cash Received, Change).
    public function columnFormats(): array
    {
        return [
            'B' => 'yyyy-mm-dd hh:mm',
            'E' => self::PESO,
            'F' => self::PESO,
            'G' => self::PESO,
            'H' => self::PESO,
            'I' => self::PESO,
        ];
    }

    // Heading row and TOTAL row in bold.
    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => ['font' => ['bold' => true]],
            $this->totalRow => ['font' => ['bold' => true]],
        ];
    }
}
