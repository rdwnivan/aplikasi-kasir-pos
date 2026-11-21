<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DemoUserSeeder::class,
        ]);

        if (! app()->environment('local')) {
            $this->call(CatalogSeeder::class);

            return;
        }

        // Local demo: open the shop a month ago so the dashboard and reports have history,
        // and the stock card reads in date order (opening stock first, then the sales).
        $now = CarbonImmutable::now();
        Carbon::setTestNow($now->subDays(31)->setTime(7, 0));

        try {
            $this->call(CatalogSeeder::class);
        } finally {
            Carbon::setTestNow();
        }

        $this->callWith(DemoSalesSeeder::class, ['until' => $now]);
    }
}
