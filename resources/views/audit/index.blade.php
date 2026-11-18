{{--
    Audit Log (screen 13), OWNER only. Mockup: docs/design/09-audit-log-export.png.
    Left: filters (Date From/To, User, Action, Apply) and the table, newest first, 50 per page.
    Right: the Export to Excel card (Inventory list, Sales Records or Audit log as .xlsx, ExportController).
    READ ONLY: no edit or delete buttons. All data comes from AuditLogController / AuditService; this view only shows it.
--}}
@extends('layouts.app')

@section('title', 'Audit Log')

@php
    // Badge colour for each action (classes in public/css/jeanrose.css, section 11).
    $badgeClass = [
        'LOGIN' => 'jr-audit-login',
        'SALE' => 'jr-audit-sale',
        'VOID' => 'jr-audit-void',
        'INVENTORY_UPDATE' => 'jr-audit-inventory',
        'EXPORT' => 'jr-audit-action-export',
        'PASSWORD_RESET' => 'jr-audit-action-reset',
    ];
@endphp

@section('content')
    <div class="jr-audit">
        <h1 class="jr-page-title">Audit Log</h1>

        <div class="jr-audit-layout">
            {{-- ========== Left: filters and table ========== --}}
            <section class="jr-audit-main" aria-label="Audit records">
                {{-- GET form: Apply reloads the page with the filters. Nothing is saved. --}}
                <form method="GET" action="{{ route('audit.index') }}" class="jr-audit-filters">
                    <fieldset class="jr-audit-field">
                        <legend class="jr-audit-label">Date</legend>
                        <div class="jr-audit-dates">
                            <label for="auditFrom" class="visually-hidden">From</label>
                            <input id="auditFrom" name="from" type="date"
                                   class="form-control jr-audit-date @error('from') is-invalid @enderror"
                                   value="{{ $from->format('Y-m-d') }}">
                            <span class="jr-audit-to" aria-hidden="true">to</span>
                            <label for="auditTo" class="visually-hidden">To</label>
                            <input id="auditTo" name="to" type="date"
                                   class="form-control jr-audit-date @error('to') is-invalid @enderror"
                                   value="{{ $to->format('Y-m-d') }}">
                        </div>
                    </fieldset>

                    <div class="jr-audit-field">
                        <label for="auditUser" class="jr-audit-label">User</label>
                        <select id="auditUser" name="user" class="form-select jr-audit-select @error('user') is-invalid @enderror">
                            <option value="">All users</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->user_id }}" @selected($userId === (int) $user->user_id)>{{ $user->username }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="jr-audit-field">
                        <label for="auditAction" class="jr-audit-label">Action</label>
                        <select id="auditAction" name="action" class="form-select jr-audit-select-wide @error('action') is-invalid @enderror">
                            <option value="">All actions</option>
                            @foreach ($actions as $value)
                                <option value="{{ $value }}" @selected($action === $value)>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-pill">Apply</button>

                    {{-- Jumps to the Export card on the right (below the table on a narrow screen). --}}
                    <a href="#exportCard" class="btn btn-outline-primary btn-pill">Export Excel</a>
                </form>

                @if ($errors->any())
                    <div class="alert alert-danger mb-0" role="alert">
                        @foreach ($errors->all() as $message)
                            <div>{{ $message }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="jr-audit-table-wrap">
                    <table class="table jr-audit-table">
                        <thead>
                            <tr>
                                <th scope="col">Date / Time</th>
                                <th scope="col">User</th>
                                <th scope="col">Action</th>
                                <th scope="col">Module</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="text-nowrap">{{ $row->created_at->format('m/d/Y H:i') }}</td>
                                    <td>{{ $row->user?->username ?? '-' }}</td>
                                    <td><span class="jr-audit-badge {{ $badgeClass[$row->action_type] ?? '' }}">{{ $row->action_type }}</span></td>
                                    <td>{{ $row->module }}</td>
                                    {{-- "-" when there is no reference (for example LOGIN rows) --}}
                                    <td class="text-nowrap">{{ filled($row->transaction_reference) ? $row->transaction_reference : '-' }}</td>
                                    <td>{{ filled($row->details) ? $row->details : '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="jr-empty">No audit records for these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Page links only appear when there are more than 50 rows --}}
                @if ($rows->hasPages())
                    <div class="jr-audit-pages">{{ $rows->links() }}</div>
                @endif

                <p class="jr-audit-note mb-0">Read-only list. Newest records first.</p>
            </section>

            {{-- ========== Right: Export to Excel (OWNER only, ExportController) ========== --}}
            {{-- GET form: the browser downloads the .xlsx. Errors come back in the "export" error bag. --}}
            @php
                $exportErrors = $errors->getBag('export');
                $report = old('report', 'sales'); // Sales Records is chosen by default
            @endphp
            <aside class="card jr-audit-export" id="exportCard" aria-labelledby="exportTitle">
                <h2 class="jr-audit-export-title" id="exportTitle">Export to Excel</h2>

                <form method="GET" action="{{ route('exports.download') }}" class="d-flex flex-column gap-3">
                    <fieldset class="jr-audit-export-what">
                        <legend class="jr-audit-label">What to export</legend>
                        <label class="jr-audit-option" for="exportInventory">
                            <input class="form-check-input" type="radio" name="report" value="inventory" id="exportInventory"
                                   @checked($report === 'inventory')> Inventory list
                        </label>
                        <label class="jr-audit-option" for="exportSales">
                            <input class="form-check-input" type="radio" name="report" value="sales" id="exportSales"
                                   @checked($report === 'sales')> Sales Records
                        </label>
                        <label class="jr-audit-option" for="exportAudit">
                            <input class="form-check-input" type="radio" name="report" value="audit" id="exportAudit"
                                   @checked($report === 'audit')> Audit log
                        </label>
                        @if ($exportErrors->has('report'))
                            <div class="invalid-feedback d-block">{{ $exportErrors->first('report') }}</div>
                        @endif
                    </fieldset>

                    <div class="jr-audit-export-dates">
                        <div class="jr-audit-field">
                            <label for="exportFrom" class="jr-audit-label">From</label>
                            {{-- max = today: the date picker does not offer future days (the server checks it too). --}}
                            <input id="exportFrom" name="from" type="date" max="{{ now()->format('Y-m-d') }}"
                                   class="form-control {{ $exportErrors->has('from') ? 'is-invalid' : '' }}"
                                   value="{{ old('from', $from->format('Y-m-d')) }}">
                            @if ($exportErrors->has('from'))
                                <div class="invalid-feedback">{{ $exportErrors->first('from') }}</div>
                            @endif
                        </div>
                        <div class="jr-audit-field">
                            <label for="exportTo" class="jr-audit-label">To</label>
                            <input id="exportTo" name="to" type="date" max="{{ now()->format('Y-m-d') }}"
                                   class="form-control {{ $exportErrors->has('to') ? 'is-invalid' : '' }}"
                                   value="{{ old('to', $to->format('Y-m-d')) }}">
                            @if ($exportErrors->has('to'))
                                <div class="invalid-feedback">{{ $exportErrors->first('to') }}</div>
                            @endif
                        </div>
                    </div>

                    <p class="jr-audit-note mb-0">Dates apply to Sales Records and Audit log. Inventory list is always today.</p>

                    <button type="submit" class="btn btn-primary jr-audit-download">Download .xlsx</button>
                </form>
            </aside>
        </div>
    </div>
@endsection
