<?php

use Inertia\Testing\AssertableInertia as Assert;

test('root renders the login page', function () {
    $this->withoutVite();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('login'),
        );
});

test('login renders the login page', function () {
    $this->withoutVite();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('login'),
        );
});
