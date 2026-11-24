<?php

namespace Hata\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    /**
     * `/robots.txt`: everything is allowed, and the sitemap is named when Hata serves one. The admin path is never
     * written here (`HATA_ADMIN_PATH` may be set to hide it). No query, no session.
     */
    public function __invoke(): Response
    {
        $body = "User-agent: *\nDisallow:\n";

        if (config('hata.seo.sitemap')) {
            $body .= "\nSitemap: ".url('sitemap.xml')."\n";
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
