<?php

namespace Database\Seeders;

use App\Models\CommunicationTemplate;
use App\Services\AuditWriter;
use App\Services\Communications\TemplateRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommunicationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (config('communications.defaults') as $slot => $default) {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [14065, $slot]);
                if (CommunicationTemplate::query()->where('channel', 'email')->where('code', 'email_'.$slot)->where('version', 1)->exists()) {
                    continue;
                }
                $content = app(TemplateRenderer::class)->validate([...$default, 'required' => array_keys(config('communications.placeholders'))], true);
                $template = new CommunicationTemplate;
                $template->forceFill([...$content, 'name' => $default['name'], 'channel' => 'email', 'code' => 'email_'.$slot, 'email_slot' => $slot, 'version' => 1, 'status' => 'approved', 'approved_at' => now(), 'approval_source' => 'owner_default', 'approval_reference' => 'Product owner approved initial defaults on 18 October 2026'])->save();
                app(AuditWriter::class)->record(null, 'template.owner_default_applied', $template->public_id, ['status' => 'approved'], module: 'M14', entityType: 'communication_template');
            }
        });
    }
}
