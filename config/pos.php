<?php

/*
| Store-level POS settings. v1 reads them from the environment; a settings screen for the
| owner is on the roadmap. Whatever is in effect at checkout is snapshotted onto each sale,
| so changing these never rewrites past receipts.
*/

return [
    // Printed on receipts.
    'store' => [
        'name' => env('POS_STORE_NAME', 'Toko Sumber Berkah'),
        'address' => env('POS_STORE_ADDRESS', 'Jl. Merdeka No. 45, Bandung'),
        'phone' => env('POS_STORE_PHONE', '022-1234567'),
        'npwp' => env('POS_STORE_NPWP'),
        'receipt_footer' => env('POS_RECEIPT_FOOTER', 'Terima kasih, selamat belanja kembali!'),
    ],

    'tax' => [
        // Set false for a non-PKP business (no PPN on receipts).
        'enabled' => (bool) env('POS_TAX_ENABLED', true),

        // PPN rate in percent. Since 2025 the rate is 12%, charged on a DPP of 11/12 of the
        // selling price for ordinary goods, which works out to an effective 11%.
        'rate' => (string) env('POS_TAX_RATE', '12'),
        'dpp_numerator' => (int) env('POS_TAX_DPP_NUMERATOR', 11),
        'dpp_denominator' => (int) env('POS_TAX_DPP_DENOMINATOR', 12),

        // Shelf prices already include PPN (normal for Indonesian retail).
        'prices_include_tax' => (bool) env('POS_PRICES_INCLUDE_TAX', true),
    ],

    // Seed 30 days of sales history (DemoSalesSeeder) outside the local environment too,
    // for a public demo deploy. Local always gets it.
    'demo_sales_history' => (bool) env('POS_DEMO_SALES_HISTORY', false),
];
