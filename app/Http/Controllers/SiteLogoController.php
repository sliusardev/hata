<?php

namespace App\Http\Controllers;

use App\Support\Settings\SiteLogo;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteLogoController extends Controller
{
    /**
     * Serve an uploaded logo. The URL carries a version, so the file may be cached for good.
     * The CSP sandbox keeps scripts inside an uploaded SVG from running when it is opened directly.
     */
    public function __invoke(string $variant): StreamedResponse
    {
        $path = SiteLogo::path($variant);

        abort_if($path === null || ! SiteLogo::disk()->exists($path), 404);

        return SiteLogo::disk()->response($path, headers: [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
