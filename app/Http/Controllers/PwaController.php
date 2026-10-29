<?php

namespace App\Http\Controllers;

class PwaController extends Controller
{
    public function worker()
    {
        $manifestPath = public_path('build/manifest.json');
        abort_unless(is_file($manifestPath), 503);
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $assets = ['/offline.html', '/favicon.svg', '/pwa/icon-192.png', '/pwa/icon-512.png', '/pwa/maskable-512.png', '/pwa/apple-touch-icon.png'];
        foreach ($manifest as $entry) {
            foreach ([$entry['file'], ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
                if (preg_match('~^assets/[A-Za-z0-9_.-]+\.(js|css|woff2?|png|svg)$~D', $file)) {
                    $assets[] = '/build/'.$file;
                }
            }
        }
        $source = file_get_contents(resource_path('js/service-worker.js'));
        $staticHashes = array_map(fn ($path) => hash_file('sha256', public_path($path)), array_filter($assets, fn ($path) => ! str_starts_with($path, '/build/')));
        $version = substr(hash('sha256', $source.json_encode($manifest).json_encode($staticHashes)), 0, 20);
        $script = 'const VERSION = '.json_encode($version).'; const STATIC_ASSETS = '.json_encode(array_values(array_unique($assets))).";\n".$source;

        return response($script)->header('Content-Type', 'application/javascript')->header('Cache-Control', 'no-cache, no-store, must-revalidate')->header('Service-Worker-Allowed', '/')->header('X-Content-Type-Options', 'nosniff');
    }
}
