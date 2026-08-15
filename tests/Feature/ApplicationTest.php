<?php

it('identifies itself as Maintainer', function () {
    expect(config('app.name'))->toBe('Maintainer');
});
