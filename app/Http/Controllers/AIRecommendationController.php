<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AIRecommendationController extends Controller
{
    private $pythonServiceUrl;

    public function __construct()
    {
        $this->pythonServiceUrl = env(
            'PYTHON_AI_SERVICE_URL',
            'https://ternakpro-ai.onrender.com'
        );
    }

    /**
     * Public wrapper for testing recommendations without auth (development only).
     */
    public function getRecommendationPublic(Request $request)
    {
        return $this->getRecommendation($request);
    }

    /**
     * ==========================================
     * GET RECOMMENDATION VIKOR
     * ==========================================
     */
    public function getRecommendation(
        Request $request
    ) {

        // ======================================
        // VALIDATION
        // ======================================

        $validator = Validator::make(
            $request->all(),
            [

                'modal' =>
                    'required|numeric|min:100000',

                'lahan' =>
                    'required|numeric|min:1',

                'waktu' =>
                    'required|integer|min:1|max:5',

                'pengalaman' =>
                    'required|string|in:belum,belum_pernah,kurang_1_tahun,1_3_tahun,3_5_tahun,lebih_5_tahun'
            ]
        );

        // ======================================
        // VALIDATION FAILED
        // ======================================

        if ($validator->fails()) {

            return response()->json([

                'success' => false,

                'message' =>
                    'Validation failed',

                'errors' =>
                    $validator->errors()

            ], 422);
        }

        try {

            // ======================================
            // PREPARE DATA FOR FASTAPI
            // ======================================

            $inputData = [

                'modal' =>
                    $request->modal,

                'lahan' =>
                    $request->lahan,

                'waktu' =>
                    $request->waktu,

                'pengalaman' =>
                    $request->pengalaman
            ];

            Log::info('Sending VIKOR request', ['data' => $inputData]);

            // ======================================
            // CALL FASTAPI VIKOR (send explicit JSON)
            // ======================================

            $url = $this->pythonServiceUrl . '/api/recommend-vikor';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->withBody(json_encode($inputData), 'application/json')
                ->post($url);

            // ======================================
            // SUCCESS
            // ======================================

            if ($response->successful()) {

                // Ensure we have JSON; if not, log body for debugging
                $contentType = $response->header('Content-Type', '');

                if (stripos($contentType, 'application/json') === false) {
                    Log::warning('AI service returned non-json content', [
                        'content_type' => $contentType,
                        'body_preview' => substr($response->body(), 0, 1000)
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'AI recommendation service returned unexpected content'
                    ], 502);
                }

                $payload = $response->json();

                // Log a preview of the Python response for debugging
                try {
                    Log::info('VIKOR response preview', [
                        'preview' => substr(json_encode($payload), 0, 2000)
                    ]);
                } catch (\Exception $e) {
                    // ignore logging errors
                }

                // Preserve raw vikor fields into a debug key so we can inspect
                // original S/R/Q values returned by the Python service.
                $debugKeys = [
                    's', 'S', 'r', 'R', 'qi', 'qi_value', 'q_value', 'q_value_pure', 'q'
                ];

                // If the AI service wraps recommendations in a top-level key
                if (isset($payload['recommendations']) && is_array($payload['recommendations'])) {
                    $recs = $payload['recommendations'];

                    // attach vikor_debug for each rec (original S/R/Q fields)
                    foreach ($recs as $rk => $rv) {
                        if (is_array($rv)) {
                            $recs[$rk]['vikor_debug'] = array_intersect_key($rv, array_flip($debugKeys));
                        }
                    }

                    // enrich only recommendation items
                    $recs = $this->enrichRecommendationResponse($recs, (float) $request->modal);

                    // reorder feasibles first
                    $recs = $this->reorderByFeasibility($recs);

                    // re-assign ranking numbers for presentation
                    foreach ($recs as $k => $rec) {
                        $recs[$k]['ranking'] = $k + 1;
                    }

                    $payload['recommendations'] = $recs;

                    return response()->json([
                        'success' => true,
                        'message' => 'Recommendation success',
                        'data' => $payload
                    ]);
                }

                // If payload is directly an array of recommendations
                if (is_array($payload)) {
                    $recs = $payload;

                    foreach ($recs as $rk => $rv) {
                        if (is_array($rv)) {
                            $recs[$rk]['vikor_debug'] = array_intersect_key($rv, array_flip($debugKeys));
                        }
                    }

                    $recs = $this->enrichRecommendationResponse($recs, (float) $request->modal);
                    $recs = $this->reorderByFeasibility($recs);

                    foreach ($recs as $k => $rec) {
                        $recs[$k]['ranking'] = $k + 1;
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Recommendation success',
                        'data' => ['recommendations' => $recs]
                    ]);
                }
            }

            // ======================================
            // FASTAPI ERROR
            // ======================================

            Log::error(
                'FastAPI VIKOR Error',
                [

                    'status' =>
                        $response->status(),

                    'body' =>
                        $response->body()
                ]
            );

            return response()->json([

                'success' => false,

                'message' =>
                    'AI recommendation service unavailable'

            ], 503);

        } catch (\Exception $e) {

            // ======================================
            // SERVER ERROR
            // ======================================

            Log::error(
                'Recommendation Error',
                [

                    'message' =>
                        $e->getMessage(),

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );

            return response()->json([

                'success' => false,

                'message' =>
                    'Failed to get recommendation'

            ], 500);
        }
    }

    /**
     * ==========================================
     * HEALTH CHECK
     * ==========================================
     */
    public function healthCheck()
    {
        try {

            $response = Http::timeout(10)
                ->get(
                    $this->pythonServiceUrl .
                    '/api/health'
                );

            return response()->json([

                'laravel' =>
                    'healthy',

                'python_ai_service' =>
                    $response->successful()
                        ? 'healthy'
                        : 'unhealthy',

                'python_response' =>
                    $response->json()

            ]);

        } catch (\Exception $e) {

            return response()->json([

                'laravel' =>
                    'healthy',

                'python_ai_service' =>
                    'unhealthy',

                'error' =>
                    $e->getMessage()

            ], 503);
        }
    }

    private function enrichRecommendationResponse(
        array $payload,
        float $modal
    ): array {
        foreach ($payload as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if ($this->isRecommendationItem($value)) {
                $payload[$key] = $this->attachModalEstimate(
                    $value,
                    $modal
                );
                continue;
            }

            $payload[$key] = $this->enrichRecommendationResponse(
                $value,
                $modal
            );
        }

        return $payload;
    }

    private function isRecommendationItem(array $item): bool
    {
        $hasRankingField = array_key_exists('rank', $item)
            || array_key_exists('ranking', $item)
            || array_key_exists('score', $item)
            || array_key_exists('nilai', $item)
            || array_key_exists('qi', $item)
            || array_key_exists('s', $item)
            || array_key_exists('r', $item);

        return $hasRankingField && $this->resolveRecommendationLabel($item) !== null;
    }

    private function attachModalEstimate(
        array $item,
        float $modal
    ): array {
        $label = $this->resolveRecommendationLabel($item);
        $breakdown = $this->resolveCostAssumption($label);
        // determine modal minimum from payload if available
        $modalMinimum = $item['modal_minimum'] ?? $item['modal'] ?? $item['biaya_awal'] ?? 0;

        // if we don't know breakdown, return nulls so frontend hides details
        if ($breakdown === null) {
            $item['biaya_total_per_ekor'] = null;
            $item['estimasi_ekor'] = 0;
            $item['sisa_modal'] = null;
            $item['breakdown_biaya'] = null;
            $item['status'] = $modal < $modalMinimum ? 'Modal belum mencukupi' : null;

            return $item;
        }

        // Derive kandang per-ekor based on user's modal and the actual
        // `estimasi_ekor` computed from costs. We iterate to reach a
        // self-consistent estimate because `estimasi_ekor` depends on
        // `biayaTotalPerEkor` which itself depends on kandang per-ekor.

        $allocMin = (float) config('ai_recommendation.kandang_allocation_min_percent', 0.10);
        $allocMax = (float) config('ai_recommendation.kandang_allocation_max_percent', 0.15);
        $blend = (float) config('ai_recommendation.kandang_modal_blend', 0.60);
        $kandangMinMultiplier = (float) config('ai_recommendation.kandang_min_multiplier', 0.70);
        $kandangMaxMultiplier = (float) config('ai_recommendation.kandang_max_multiplier', 3.00);
        $defaultMinEkor = config('ai_recommendation.default_min_ekor', 10);
        $defaultKandangPerEkor = config('ai_recommendation.default_kandang_per_ekor', 50000);

        $blend = max(0.0, min(1.0, $blend));

        // Dynamic allocation between min..max based on sufficiency ratio.
        // ratio < 1  => closer to max allocation.
        // ratio >= 1 => closer to min allocation.
        $sufficiencyRatio = ($modalMinimum > 0) ? ($modal / $modalMinimum) : 1.0;
        $ratio01 = max(0.0, min(1.0, $sufficiencyRatio));
        $dynamicAlloc = $allocMax - (($allocMax - $allocMin) * $ratio01);

        $minEkorProvided = isset($item['min_ekor_minimum']) && is_numeric($item['min_ekor_minimum']) && (int)$item['min_ekor_minimum'] > 0
            ? (int)$item['min_ekor_minimum']
            : $defaultMinEkor;

        // base breakdown and base kandang
        $baseBreakdown = $breakdown;
        $baseKandang = isset($baseBreakdown['kandang']) ? (float) $baseBreakdown['kandang'] : $defaultKandangPerEkor;

        // initialize
        $kandangPerEkor = $baseKandang;
        $tempBreakdown = $baseBreakdown;
        $tempBreakdown['kandang'] = $kandangPerEkor;
        $biayaTotalPerEkor = array_sum($tempBreakdown);

        // iterate to converge kandang-per-ekor vs estimasi_ekor (max 6 iter)
        for ($iter = 0; $iter < 6; $iter++) {
            $estimasiEkor = $biayaTotalPerEkor > 0
                ? (int) floor(($modal * 0.85) / $biayaTotalPerEkor)
                : 0;

            $usedEkor = max(1, $estimasiEkor ?: $minEkorProvided);

            $kandangFromModalPerEkor = $modal > 0
                ? (float) round(($dynamicAlloc * $modal) / $usedEkor)
                : $baseKandang;

            // Blend baseline and modal-derived kandang to keep estimates stable
            // while still adapting to each user's modal.
            $newKandang = ($baseKandang * (1.0 - $blend)) + ($kandangFromModalPerEkor * $blend);

            $minKandang = $baseKandang * max(0.1, $kandangMinMultiplier);
            $maxKandang = $baseKandang * max($kandangMinMultiplier, $kandangMaxMultiplier);
            $newKandang = max($minKandang, min($maxKandang, $newKandang));

            if (abs($newKandang - $kandangPerEkor) < 1) {
                $kandangPerEkor = $newKandang;
                break;
            }

            $kandangPerEkor = $newKandang;
            $tempBreakdown['kandang'] = $kandangPerEkor;
            $biayaTotalPerEkor = array_sum($tempBreakdown);
        }

        // apply final kandang per-ekor back into breakdown
        $breakdown['kandang'] = $kandangPerEkor;
        $biayaTotalPerEkor = array_sum($breakdown);

        // Update item's breakdown fields to reflect adjusted kandang per-ekor so
        // the UI displays realistic components even when modal is insufficient.
        // Normalize per-ekor breakdown from the computed breakdown so all
        // displayed components stay in sync with final assumptions.
        $item['breakdown_per_ekor'] = [
            'bibit' => (int) round($breakdown['bibit'] ?? 0),
            'kandang' => (int) round($breakdown['kandang'] ?? 0),
            'pakan_awal' => (int) round($breakdown['pakan_awal'] ?? 0),
            'lainnya' => (int) round($breakdown['lainnya'] ?? 0),
        ];

        $item['biaya_per_ekor_estimasi'] = (int) round(array_sum($item['breakdown_per_ekor']));

        // Rule: if user's modal less than modal minimum -> estimasi 0 and hide breakdown/sisa
        if ($modal < $modalMinimum) {
            $item['biaya_total_per_ekor'] = null;
            $item['estimasi_ekor'] = 0;
            $item['sisa_modal'] = null;
            $item['breakdown_biaya'] = null;
            $item['breakdown_total_estimasi'] = [
                'bibit' => 0,
                'kandang' => 0,
                'pakan_awal' => 0,
                'lainnya' => 0,
            ];
            $item['biaya_kandang_estimasi'] = 0;
            $item['status'] = 'Modal belum mencukupi';

            return $item;
        }

        // If modal is sufficient, apply 85% operational reserve rule
        $estimasiEkor = $biayaTotalPerEkor > 0
            ? (int) floor(($modal * 0.85) / $biayaTotalPerEkor)
            : 0;

        // If estimation results in zero, hide breakdown and sisa to avoid misleading values
        if ($estimasiEkor <= 0) {
            $item['biaya_total_per_ekor'] = null;
            $item['estimasi_ekor'] = 0;
            $item['sisa_modal'] = null;
            $item['breakdown_biaya'] = null;
            $item['breakdown_total_estimasi'] = [
                'bibit' => 0,
                'kandang' => 0,
                'pakan_awal' => 0,
                'lainnya' => 0,
            ];
            $item['biaya_kandang_estimasi'] = 0;
            $item['status'] = 'Modal belum mencukupi';

            return $item;
        }

        $sisaModal = round(
            $modal - ($estimasiEkor * $biayaTotalPerEkor),
            2
        );

        $item['biaya_total_per_ekor'] = $biayaTotalPerEkor;
        $item['estimasi_ekor'] = $estimasiEkor;
        $item['sisa_modal'] = $sisaModal;
        $item['breakdown_biaya'] = $breakdown;
        $item['breakdown_total_estimasi'] = [
            'bibit' => (int) round(($item['breakdown_per_ekor']['bibit'] ?? 0) * $estimasiEkor),
            'kandang' => (int) round(($item['breakdown_per_ekor']['kandang'] ?? 0) * $estimasiEkor),
            'pakan_awal' => (int) round(($item['breakdown_per_ekor']['pakan_awal'] ?? 0) * $estimasiEkor),
            'lainnya' => (int) round(($item['breakdown_per_ekor']['lainnya'] ?? 0) * $estimasiEkor),
        ];
        $item['biaya_kandang_estimasi'] = $item['breakdown_total_estimasi']['kandang'];
        $item['status'] = 'Modal mencukupi';

        return $item;
    }

    private function resolveRecommendationLabel(array $item): ?string
    {
        $labelKeys = [
            'nama_ternak',
            'jenis_ternak',
            'ternak',
            'nama',
            'label',
            'komoditas',
            'title',
            'rekomendasi'
        ];

        foreach ($labelKeys as $labelKey) {
            if (isset($item[$labelKey]) && is_string($item[$labelKey])) {
                $label = trim($item[$labelKey]);

                if ($label !== '') {
                    return $label;
                }
            }
        }

        foreach (['hewan', 'ras', 'ternak_detail'] as $relationKey) {
            if (!isset($item[$relationKey]) || !is_array($item[$relationKey])) {
                continue;
            }

            $relatedItem = $item[$relationKey];

            if (isset($relatedItem['nama']) && is_string($relatedItem['nama'])) {
                $label = trim($relatedItem['nama']);

                if ($label !== '') {
                    return $label;
                }
            }

            if (isset($relatedItem['nama_ternak']) && is_string($relatedItem['nama_ternak'])) {
                $label = trim($relatedItem['nama_ternak']);

                if ($label !== '') {
                    return $label;
                }
            }
        }

        return null;
    }

    private function resolveCostAssumption(?string $label): ?array
    {
        if ($label === null) {
            return null;
        }

        $normalizedLabel = strtolower($label);

        $assumptions = config('ai_recommendation.assumptions', []);

        foreach ($assumptions as $keyword => $breakdown) {
            if (strpos($normalizedLabel, $keyword) !== false) {
                return $breakdown;
            }
        }

        return null;
    }

    /**
     * Reorder recommendations so that feasible alternatives (estimasi_ekor > 0)
     * appear before infeasible ones. Within each group, keep ordering by q_value
     * (lower is better).
     */
    private function reorderByFeasibility(array $recommendations): array
    {
        usort($recommendations, function ($a, $b) {
            $aFeasible = (isset($a['estimasi_ekor']) && (int) $a['estimasi_ekor'] > 0);
            $bFeasible = (isset($b['estimasi_ekor']) && (int) $b['estimasi_ekor'] > 0);

            if ($aFeasible !== $bFeasible) {
                return $aFeasible ? -1 : 1;
            }

            $aQ = isset($a['q_value']) ? floatval($a['q_value']) : 1.0;
            $bQ = isset($b['q_value']) ? floatval($b['q_value']) : 1.0;

            if ($aQ == $bQ) {
                return 0;
            }

            return $aQ < $bQ ? -1 : 1;
        });

        return $recommendations;
    }
}