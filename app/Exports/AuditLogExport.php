<?php

namespace App\Exports;

use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Audit log report (.xlsx): the same columns as the Audit Log screen, newest first.
// The rows come from AuditService::forExport(). Reading only.
// WithStrictNullComparison: a value 0 is written as 0, never turned into a blank cell (same as the other reports).
class AuditLogExport implements FromArray, WithHeadings, WithColumnFormatting, ShouldAutoSize, WithStyles, WithStrictNullComparison
{
    public const NO_ROWS = 'No audit records in this date range';

    public function __construct(private Collection $rows)
    {
    }

    // One sheet row per audit row, or one "No audit records" row when there are none.
    public function array(): array
    {
        $rows = $this->rows->map(fn (AuditLog $row) => $this->row($row))->all();

        return $rows === [] ? [[self::NO_ROWS]] : $rows;
    }

    public function headings(): array
    {
        return ['Date/Time', 'User', 'Action', 'Module', 'Reference', 'Details'];
    }

    private function row(AuditLog $row): array
    {
        return [
            Date::dateTimeToExcel($row->created_at), // a real Excel date, not text
            $row->user?->username ?? '-',
            $row->action_type,
            $row->module,
            filled($row->transaction_reference) ? $row->transaction_reference : '-',
            filled($row->details) ? $row->details : '-',
        ];
    }

    // A is a date and time.
    public function columnFormats(): array
    {
        return ['A' => 'yyyy-mm-dd hh:mm'];
    }

    // Heading row in bold.
    public function styles(Worksheet $sheet): ?array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
