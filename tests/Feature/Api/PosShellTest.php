<?php

declare(strict_types=1);

it('serves the pos shell publicly: state and auth live in the device and the api', function (): void {
    $this->get('/pos')
        ->assertOk()
        ->assertSee('id="pos"', false)
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('pos-sw.js', false);
});
