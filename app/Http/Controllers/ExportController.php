<?php

namespace App\Http\Controllers;

use App\Exports\AuditLogExport;
use App\Exports\InventoryExport;
use App\Exports\SalesExport;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Excel export (screen 11, export side, FR-06/FR-08). OWNER only (route group can:owner, the cashier gets 403).
// Thin controller: the rows come from the services, the sheet layout from app/Exports, the audit row from AuditService.
class ExportController extends Controller
{
    public function __construct(
        private SaleService $sales,
        private InventoryService $inventory,
        private StockStatusService $status,
        private AuditService $audit,
    ) {
    }

    // GET /exports/download?report=sales&from=2026-11-16&to=2026-11-16 (the Export card on the Audit Log page).
    public function download(Request $request): BinaryFileResponse
    {
        // Errors go into the "export" bag, so they show in the Export card and not in the Audit filters.
        $data = $request->validateWithBag('export', [
            'report' => ['required', 'in:sales,inventory,audit'],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            // To may not be before From (only checked when From is filled, like the Audit filters).
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today',
                Rule::when($request->filled('from'), 'after_or_equal:from')],
        ], [
            'report.required' => 'Choose what to export.',
            'report.in' => 'Choose what to export.',
            'from.before_or_equal' => "Dates can't be in the future.",
            'to.before_or_equal' => "Dates can't be in the future.",
            'to.after_or_equal' => "The To date can't be before the From date.",
            'from.date_format' => 'Enter a valid From date.',
            'to.date_format' => 'Enter a valid To date.',
        ]);

        // Only one date filled: use the same day for the other one. No dates: today (Asia/Manila).
        $fromText = $data['from'] ?? $data['to'] ?? null;
        $toText = $data['to'] ?? $data['from'] ?? null;
        $from = $fromText ? Carbon::createFromFormat('Y-m-d', $fromText)->startOfDay() : Carbon::today();
        $to = $toText ? Carbon::createFromFormat('Y-m-d', $toText)->startOfDay() : Carbon::today();
        $range = $from->format('Y-m-d').'_to_'.$to->format('Y-m-d');
        $rangeText = $from->format('Y-m-d').' to '.$to->format('Y-m-d');

        // Build the file. Module: the audit_log ENUM only has SALES and INVENTORY.
        [$export, $fileName, $module, $details] = match ($data['report']) {
            'sales' => [
                new SalesExport($this->sales->salesForRange($from, $to), $this->sales),
                "JeanRose_Sales_{$range}.xlsx", 'SALES', "Sales report {$rangeText}",
            ],
            'inventory' => [
                new InventoryExport($this->inventory->rowsForExport(), $this->status),
                'JeanRose_Inventory_'.Carbon::today()->format('Y-m-d').'.xlsx', 'INVENTORY',
                'Inventory report '.Carbon::today()->format('Y-m-d'),
            ],
            'audit' => [
                new AuditLogExport($this->audit->forExport($from, $to)),
                "JeanRose_AuditLog_{$range}.xlsx", 'SALES', "Audit log report {$rangeText}",
            ],
        };

        $response = Excel::download($export, $fileName);

        // Log the export only after the file was made (if building it failed, there is nothing to log).
        $this->audit->log($request->user(), 'EXPORT', $module, null, $details);

        return $response;
    }
}
