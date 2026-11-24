<?php

namespace Hata\Http\Controllers;

use Hata\Support\Seo\Sitemap;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    /**
     * `/sitemap.xml`. One query decides whether the crawler's copy is current (304, no body built); otherwise the XML
     * is streamed from cursors. `Sitemap` is a parameter, not a constructor argument: it holds a scoped service.
     */
    public function __invoke(Request $request, Sitemap $sitemap): Response
    {
        $response = new Response;
        $response->setPublic();
        $response->setMaxAge(3600);

        $modified = $sitemap->lastModified();

        if ($modified !== null) {
            $response->setLastModified($modified);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        $headers = ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => $response->headers->get('Cache-Control')];

        if ($modified !== null) {
            $headers['Last-Modified'] = $response->headers->get('Last-Modified');
        }

        return response()->stream(function () use ($sitemap): void {
            foreach ($sitemap->lines() as $line) {
                echo $line;
            }
        }, 200, $headers);
    }
}
