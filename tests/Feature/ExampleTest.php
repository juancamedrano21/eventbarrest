<?php

declare(strict_types=1);

it('returns a successful response on the home page', function (): void {
    $this->get('/')->assertStatus(200);
});
