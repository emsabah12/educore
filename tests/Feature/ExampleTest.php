<?php

test('home page redirects to the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
});
