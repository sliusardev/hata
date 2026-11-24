<?php

it('does not register sitemap.xml when the sitemap is switched off in the config', function () {
    $this->get('/sitemap.xml')->assertNotFound();
});

it('does not register robots.txt when it is switched off in the config', function () {
    $this->get('/robots.txt')->assertNotFound();
});
