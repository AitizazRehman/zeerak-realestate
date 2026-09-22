<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#222}h1{color:#165134;margin-bottom:4px}.period{margin-bottom:16px;color:#666}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#165134;color:#fff}tr:nth-child(even){background:#f5f5f5}.footer{margin-top:14px;font-size:9px;color:#777}
</style></head><body>
<h1>{{ $title }}</h1><div class="period">Period: {{ $from }} to {{ $to }}</div>
@if($rows->count())
<table><thead><tr>@foreach(array_keys($rows->first()) as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
<tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody></table>
@else <p>No records found for the selected period.</p> @endif
<div class="footer">ZeeraK Real Estate & Builders — Generated {{ now()->format('Y-m-d H:i') }}</div>
</body></html>