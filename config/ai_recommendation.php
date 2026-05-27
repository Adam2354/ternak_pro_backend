<?php

return [
    /* Per-species default breakdown per-ekor (used as base assumptions). */
    'assumptions' => [
        'ayam' => [
            'bibit' => 90000,
            'kandang' => 120000,
            'pakan_awal' => 60000,
            'lainnya' => 20000,
        ],
        'bebek' => [
            'bibit' => 100000,
            'kandang' => 130000,
            'pakan_awal' => 70000,
            'lainnya' => 30000,
        ],
        'kambing' => [
            'bibit' => 2500000,
            'kandang' => 1200000,
            'pakan_awal' => 800000,
            'lainnya' => 300000,
        ],
        'domba' => [
            'bibit' => 2800000,
            'kandang' => 1300000,
            'pakan_awal' => 1000000,
            'lainnya' => 400000,
        ],
        'sapi' => [
            'bibit' => 22000000,
            'kandang' => 5000000,
            'pakan_awal' => 3500000,
            'lainnya' => 1500000,
        ],
    ],

    // Dynamic kandang allocation range, based on modal sufficiency ratio.
    // Lower modal ratio -> closer to max, higher modal ratio -> closer to min.
    'kandang_allocation_min_percent' => 0.10,
    'kandang_allocation_max_percent' => 0.15,

    // Default minimum ekor used when item doesn't provide 'min_ekor_minimum'
    'default_min_ekor' => 10,

    // Conservative fallback kandang per-ekor when no modal available
    'default_kandang_per_ekor' => 50000,

    // Blend factor between baseline kandang and modal-derived kandang.
    // 0.0 => full baseline, 1.0 => full modal-derived.
    'kandang_modal_blend' => 0.60,

    // Safety clamp multiplier for kandang per-ekor against baseline.
    'kandang_min_multiplier' => 1.00,
    'kandang_max_multiplier' => 3.00,
];
