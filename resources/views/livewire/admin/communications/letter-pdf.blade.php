<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>DebtOps correspondence</title>
<style>
@page { margin: 25mm 22mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #171717; line-height: 1.7; }
.masthead { border-bottom: 3px solid #fcde08; padding-bottom: 14px; margin-bottom: 25px; }
.logo { width: 36px; height: 36px; vertical-align: middle; margin-right: 10px; }
.brand { font-size: 23pt; font-weight: bold; }
.meta { color: #525252; font-size: 8pt; overflow-wrap: break-word; }
h1 { font-size: 13pt; line-height: 1.6; margin: 24px 0; overflow-wrap: break-word; }
.body { white-space: pre-wrap; overflow-wrap: break-word; }
.footer { position: fixed; bottom: -12mm; font-size: 7pt; color: #525252; }
</style></head><body>
<div class="footer">Private correspondence · {{ $letter->public_id }}</div>
<div class="masthead"><img class="logo" src="{{ $logo }}" alt=""><span class="brand">DebtOps</span><div class="meta">Debt Collection Management System · Developed by InteXcode (IXC)</div></div>
<div class="meta">Reference: {{ $letter->public_id }}<br>Prepared: {{ $letter->created_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT · Template version {{ $letter->context_snapshot_ciphertext['template_version'] }}.0</div>
<h1>{{ $letter->subject_ciphertext }}</h1><div class="body">{{ $letter->body_ciphertext }}</div>
</body></html>
