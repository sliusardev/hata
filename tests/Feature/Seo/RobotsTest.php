<?php

it('serves robots.txt with the sitemap address', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=86400, public')
        ->assertContent("User-agent: *\nDisallow:\n\nSitemap: ".url('sitemap.xml')."\n");
});

it('has no sitemap line when the sitemap route is off', function () {
    // the route list is built at boot, so the controller reads the same setting
    config(['hata.seo.sitemap' => false]);

    $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow:\n");
});

it('never names the admin path', function () {
    expect($this->get('/robots.txt')->getContent())->not->toContain(config('hata.admin.path'));
});

it('sets no cookie and starts no session', function () {
    $response = $this->get('/robots.txt')->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('runs no query', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get('/robots.txt')->assertOk();

    expect($queries)->toBe(0);
});
