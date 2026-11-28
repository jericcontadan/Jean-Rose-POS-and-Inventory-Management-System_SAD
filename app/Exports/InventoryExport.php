<?php

namespace App\Exports;

use App\Models\Inventory;
use App\Services\StockStatusService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Inventory report (.xlsx): one row per batch (stock row), like the Inventory screen.
// Stock status is worked out NOW by StockStatusService (same labels as the screen).
// The rows come from InventoryService::rowsForExport().
// WithStrictNullComparison: write Qty 0 as 0. Without it Laravel Excel treats 0 like null and leaves the cell blank.
class InventoryExport implements FromArray, WithHeadings, WithColumnFormatting, ShouldAutoSize, WithStyles, WithStrictNullComparison
{
    public const NO_ROWS = 'No products in the inventory';

    public function __construct(private Collection $rows, private StockStatusService $status)
    {
    }

    // One sheet row per stock row, or one "No products" row when there are none.
    public function array(): array
    {
        $rows = $this->rows->map(fn (Inventory $row) => $this->row($row))->all();

        return $rows === [] ? [[self::NO_ROWS]] : $rows;
    }

    public function headings(): array
    {
        return ['Product', 'Category', 'Batch', 'Expiry date', 'Qty', 'Price', 'Stock status'];
    }

    private function row(Inventory $row): array
    {
        $batch = $row->batch;
        $product = $batch?->product;

        return [
            $product?->product_name ?? '-',
            $product?->category?->category_name ?? '-',
            $batch?->batch_number ?? '-',
            $batch?->expiry_date ? Date::dateTimeToExcel($batch->expiry_date) : null, // a real Excel date
            (int) $row->quantity_on_hand,
            (float) ($product?->unit_price ?? 0),
            $this->status->statusForInventory($row), // EXPIRED / OUT OF STOCK / NEAR EXPIRY / LOW STOCK / OKAY
        ];
    }

    // D is a date, E a whole number, F money.
    public function columnFormats(): array
    {
        return [
            'D' => 'yyyy-mm-dd',
            'E' => '0',
            'F' => SalesExport::PESO,
        ];
    }

    // Heading row in bold.
    public function styles(Worksheet $sheet): ?array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
