<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_scheme', fn () => request()->getScheme());
});

afterEach(function () {
    TrustProxies::flushState();
});

it('ignores X-Forwarded-Proto by default', function () {
    $this->get('/_scheme', ['X-Forwarded-Proto' => 'https'])->assertContent('http');
});

it('honours X-Forwarded-Proto when app.trusted_proxies is set', function (string $proxies) {
    config(['app.trusted_proxies' => $proxies]);
    (new AppServiceProvider(app()))->boot();

    $this->get('/_scheme', ['X-Forwarded-Proto' => 'https'])->assertContent('https');
})->with(['any proxy' => '*', 'listed proxy' => '10.0.0.1, 127.0.0.1']);
