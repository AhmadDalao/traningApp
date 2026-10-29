<?php

namespace App\Console\Commands;

use App\Services\Notifications\PushTransport;
use Illuminate\Console\Command;

class CheckPwa extends Command
{
    protected $signature = 'pwa:check';

    protected $description = 'Check PWA build, scheduler and push configuration without printing secrets';

    public function handle(PushTransport $push): int
    {
        $manifest = json_decode(@file_get_contents(public_path('manifest.webmanifest')) ?: '{}', true);
        $checks = [
            'Manifest' => ($manifest['display'] ?? null) === 'standalone' && ($manifest['scope'] ?? null) === '/',
            'Icons' => count($manifest['icons'] ?? []) >= 3 && collect($manifest['icons'] ?? [])->every(fn ($icon) => is_file(public_path($icon['src']))),
            'Offline fallback' => is_file(public_path('offline.html')),
            'Vite production build' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
            'Service worker source' => is_file(resource_path('js/service-worker.js')),
            'Scheduler command' => array_key_exists('notifications:send', $this->getApplication()->all()),
            'VAPID enabled and configured' => $push->configured(),
        ];
        foreach ($checks as $label => $valid) {
            $this->line(($valid ? 'PASS' : 'MISSING').' '.$label);
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
