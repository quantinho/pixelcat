<?php

it('sends baseline security headers on pages', function () {
    $this->get('/')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('asks browsers to stay on HTTPS once a page was served over it', function () {
    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
