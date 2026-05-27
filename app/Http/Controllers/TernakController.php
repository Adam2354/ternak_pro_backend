<?php

namespace App\Http\Controllers;

use App\Models\MHewan;
use Illuminate\Http\Request;
use App\Models\TblTernak;
use App\Models\MTujuanTernak;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;


class TernakController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/ternak",
     *     summary="Mengambil semua data ternak",
     *     description="Endpoint ini digunakan untuk mengambil semua data ternak yang tersedia.",
     *     tags={"Ternak"},
     *     @OA\Response(
     *         response=200,
     *         description="Berhasil mengambil data ternak",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Ternak")
     *         )
     *     )
     * )
     */
    public function allTernak()
    {
        $items = TblTernak::all();

        $hewans = MHewan::all()->keyBy('id');

        $result = [];
        foreach ($items as $item) {
            $hewan = $hewans->get($item->hewan_id);
            $result[] = [
                'id_ternak' => (int) ($item->id ?? 0),
                'tag_id' => (string) ($item->tag_id ?? ''),
                'nama_ternak' => (string) ($item->nama_ternak ?? ''),
                'hewan_id' => (int) ($item->hewan_id ?? 0),
                'jenis_hewan' => (string) ($hewan->nama ?? ''),
                'icon_path' => (string) ($hewan->icon_path ?? ''),
                'berat' => (float) ($item->berat ?? 0),
                'usia' => (int) ($item->usia ?? 0),
                'kondisi_ternak' => (string) ($item->kondisi_ternak ?? ''),
                'jenis_kelamin' => (string) ($item->jenis_kelamin ?? ''),
                'tgl_mulai' => $item->tgl_mulai ? Carbon::parse($item->tgl_mulai)->format('Y-m-d') : '',
                'catatan' => (string) ($item->catatan ?? ''),
            ];
        }

        return response()->json($result);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/ternak/{user_id}",
     *     summary="Mengambil semua data ternak berdasarkan user_id",
     *     description="Endpoint ini digunakan untuk mengambil semua data ternak milik pengguna tertentu berdasarkan user_id.",
     *     tags={"Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Berhasil mengambil data ternak",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Ternak")
     *         )
     *     )
     * )
     */
    public function ternakUserAll($user_id)
    {
        $items = TblTernak::where('user_id', $user_id)
            ->select([
                'id',
                'tag_id',
                'nama_ternak',
                'hewan_id',
                'berat',
                'usia',
                'kondisi_ternak',
                'jenis_kelamin',
                'tgl_mulai',
                'catatan'
            ])
            ->get();

        $hewans = MHewan::all()->keyBy('id');

        $result = [];
        foreach ($items as $item) {
            $hewan = $hewans->get($item->hewan_id);

            $result[] = [
                'id_ternak' => (int) ($item->id ?? 0),
                'tag_id' => (string) ($item->tag_id ?? ''),
                'nama_ternak' => (string) ($item->nama_ternak ?? ''),
                'hewan_id' => (int) ($item->hewan_id ?? 0),
                'jenis_hewan' => (string) ($hewan->nama ?? ''),
                'icon_path' => (string) ($hewan->icon_path ?? ''),
                'berat' => (float) ($item->berat ?? 0),
                'usia' => (int) ($item->usia ?? 0),
                'kondisi_ternak' => (string) ($item->kondisi_ternak ?? ''),
                'jenis_kelamin' => (string) ($item->jenis_kelamin ?? ''),
                'tgl_mulai' => $item->tgl_mulai ? Carbon::parse($item->tgl_mulai)->format('Y-m-d') : '',
                'catatan' => (string) ($item->catatan ?? ''),
            ];
        }

        return response()->json($result);
    }

    /**
     * Ringkasan data ternak untuk user (counts per kondisi dan rata-rata usia)
     */
    public function summaryUser(Request $request, $user_id)
    {
        $scope = $request->query('scope', 'all'); // 'all' or 'today'

        $baseQuery = TblTernak::where('user_id', $user_id);
        if ($scope === 'today') {
            $today = Carbon::now()->format('Y-m-d');
            $baseQuery = $baseQuery->whereDate('created_at', $today);
        }

        $total = $baseQuery->count();

        $counts = $baseQuery->select('kondisi_ternak')
            ->get()
            ->groupBy(function ($item) {
                return trim(strtolower($item->kondisi_ternak ?? ''));
            })
            ->map(fn($g) => $g->count())
            ->toArray();

        $sakit = $counts['sakit'] ?? 0;
        $sehat = $counts['sehat'] ?? 0;

        $rataUsia = (float) round($baseQuery->avg('usia') ?? 0, 2);

        // detect "siap jual" by matching tujuan_ternak.nama keywords (user-defined)
        $tujuanQuery = MTujuanTernak::where('user_id', $user_id)->get();
        $keywords = ['jual', 'siap jual', 'penjualan', 'jual/ambil', 'dipanen'];
        $matchedTujuanIds = $tujuanQuery->filter(function ($t) use ($keywords) {
            $nama = strtolower($t->nama ?? '');
            foreach ($keywords as $k) {
                if (str_contains($nama, strtolower($k))) return true;
            }
            return false;
        })->pluck('id')->toArray();

        $siapJualQuery = TblTernak::where('user_id', $user_id);
        if ($scope === 'today') {
            $siapJualQuery = $siapJualQuery->whereDate('created_at', Carbon::now()->format('Y-m-d'));
        }
        if (!empty($matchedTujuanIds)) {
            $siapJualQuery = $siapJualQuery->whereIn('tujuan_ternak_id', $matchedTujuanIds);
        } else {
            // fallback: if no matching tujuan labels, consider those with usia > 0 and kondisi 'Sehat'
            $siapJualQuery = $siapJualQuery->where('usia', '>', 0)->where('kondisi_ternak', 'Sehat');
        }
        $siapJual = $siapJualQuery->count();

        return response()->json([
            'total' => $total,
            'sakit' => $sakit,
            'sehat' => $sehat,
            'rata_rata_usia' => $rataUsia,
            'siap_jual' => $siapJual,
            'scope' => $scope,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/ternak/{user_id}/{id}",
     *     summary="Mengambil data ternak berdasarkan user_id dan id",
     *     description="Endpoint ini digunakan untuk mengambil data ternak spesifik berdasarkan user_id dan id ternak.",
     *     tags={"Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID ternak",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Berhasil mengambil data ternak",
     *         @OA\JsonContent(ref="#/components/schemas/Ternak")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ternak tidak ditemukan",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ternak tidak ditemukan")
     *         )
     *     )
     * )
     */
    public function ternakUser($user_id, $id)
    {
        $ternak = TblTernak::where('user_id', $user_id)->where('id', $id)->first();

        if (!$ternak) {
            return response()->json(['message' => 'Ternak tidak ditemukan'], 404);
        }

        $hewan = MHewan::where('id', $ternak->hewan_id)->first();

        $nama_hewan = $hewan->nama ?? '';
        $icon_path = $hewan->icon_path ?? '';

        $ternakData = [
            'id_ternak' => (int) ($ternak->id ?? 0),
            'tag_id' => (string) ($ternak->tag_id ?? ''),
            'nama_ternak' => (string) ($ternak->nama_ternak ?? ''),
            'jenis_hewan' => (string) $nama_hewan,
            'icon_path' => (string) $icon_path,
            'berat' => (float) ($ternak->berat ?? 0),
            'usia' => (int) ($ternak->usia ?? 0),
            'kondisi_ternak' => (string) ($ternak->kondisi_ternak ?? ''),
            'jenis_kelamin' => (string) ($ternak->jenis_kelamin ?? ''),
            'tgl_mulai' => $ternak->tgl_mulai ? Carbon::parse($ternak->tgl_mulai)->format('Y-m-d') : '',
            'catatan' => (string) ($ternak->catatan ?? ''),
        ];

        return response()->json($ternakData);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/ternak",
     *     summary="Menyimpan ternak baru",
     *     description="Endpoint ini digunakan untuk membuat data ternak baru.",
     *     tags={"Ternak"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "tag_id", "nama_ternak", "tgl_mulai", "hewan_id", "ras_id", "tujuan_ternak_id", "usia", "kondisi_ternak", "jenis_kelamin", "berat"},
     *             @OA\Property(property="user_id", type="string", example="user123"),
     *             @OA\Property(property="tag_id", type="string", example="Sapi Ke1"),
     *             @OA\Property(property="nama_ternak", type="string", example="Sapi Perah"),
     *             @OA\Property(property="tgl_mulai", type="string", format="date", example="2025-08-20"),
     *             @OA\Property(property="hewan_id", type="integer", example=1),
     *             @OA\Property(property="ras_id", type="integer", example=1),
     *             @OA\Property(property="tujuan_ternak_id", type="integer", example=1),
     *             @OA\Property(property="usia", type="integer", example=24),
     *             @OA\Property(property="kondisi_ternak", type="string", example="Sehat"),
     *             @OA\Property(property="jenis_kelamin", type="string", example="Betina"),
     *             @OA\Property(property="berat", type="number", format="float", example=500.5),
     *             @OA\Property(property="catatan", type="string", example="Catatan tambahan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Ternak berhasil dibuat",
     *         @OA\JsonContent(ref="#/components/schemas/Ternak")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validasi gagal",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validasi gagal"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function storeTernak(Request $request)
    {
        // Validasi (unik tag_id hanya per user_id)
        $request->validate([
            'user_id'          => 'required|exists:users,uid',
            'nama_ternak'      => 'required|string',
            'tgl_mulai'        => 'required|date',
            'hewan_id'         => 'required|exists:m_hewans,id',
            'ras_id'           => 'required|exists:m_ras,id',
            'tujuan_ternak_id' => 'required|exists:m_tujuan_ternaks,id',
            'is_individual'    => 'required|boolean',
            'catatan'          => 'nullable|string',

            // jika individual = true, field wajib
            'tag_id'         => [
                Rule::requiredIf(fn () => filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN) === true),
                'string',
                'max:50',
                // unik per user_id (bukan global)
                Rule::unique((new TblTernak)->getTable(), 'tag_id')
                    ->where(fn ($q) => $q->where('user_id', $request->user_id)),
            ],
            'kondisi_ternak' => Rule::requiredIf(fn()=> filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN) === true),
            'jenis_kelamin'  => Rule::requiredIf(fn()=> filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN) === true),
            'berat'          => Rule::requiredIf(fn()=> filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN) === true) . '|numeric',

            // jika kelompok
            'jumlah_hewan'   => Rule::requiredIf(fn()=> filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN) === false) . '|integer|min:1',
        ]);

        $isIndividual = filter_var($request->is_individual, FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($request, $isIndividual) {

            // 1) PREFIX dari nama_ternak: huruf awal tiap kata, max 4, uppercase, fallback TNK
            $makePrefix = function (string $name): string {
                // ubah aksara non-latin → ascii (butuh Illuminate\Support\Str)
                $ascii = Str::ascii($name);
                $words = preg_split('/\s+/', trim($ascii)) ?: [];
                $letters = [];
                foreach ($words as $w) {
                    $w = preg_replace('/[^A-Za-z0-9]/', '', $w ?? '');
                    if ($w !== '') {
                        $letters[] = substr($w, 0, 1);
                    }
                }
                $abbr = strtoupper(substr(implode('', $letters), 0, 4));
                return $abbr !== '' ? $abbr : 'TNK';
            };

            // 2) Ambil nomor urut terakhir untuk user ini dari suffix numerik di tag_id
            $getLastSeqForUser = function (string $userId): int {
                // lock supaya aman dari race condition
                $tags = TblTernak::where('user_id', $userId)
                    ->lockForUpdate()
                    ->pluck('tag_id');

                $max = 0;
                foreach ($tags as $tag) {
                    // ekstrak semua digit → int
                    $num = (int) preg_replace('/\D+/', '', (string) $tag);
                    if ($num > $max) $max = $num;
                }
                return $max; // 0 jika belum ada
            };

            // 3) Format tag dengan PREFIX-000006
            $formatTag = function (string $prefix, int $seq): string {
                return sprintf('%s-%06d', $prefix, $seq);
            };

            if ($isIndividual) {
                // Individual: gunakan tag_id dari request (sudah divalidasi unik per user)
                $row = TblTernak::create([
                    'tag_id'           => $request->tag_id,
                    'user_id'          => $request->user_id,
                    'nama_ternak'      => $request->nama_ternak,
                    'tgl_mulai'        => $request->tgl_mulai,
                    'hewan_id'         => $request->hewan_id,
                    'ras_id'           => $request->ras_id,
                    'tujuan_ternak_id' => $request->tujuan_ternak_id,
                    'usia'             => $request->input('usia', 0),
                    'kondisi_ternak'   => $request->kondisi_ternak,
                    'jenis_kelamin'    => $request->jenis_kelamin,
                    'berat'            => (float) $request->berat,
                    'catatan'          => $request->catatan,
                ]);

                return response()->json($row, 201);
            }

            // Kelompok: auto-generate tag_id dengan prefix inisial nama_ternak + seq per user
            $jumlah = (int) $request->jumlah_hewan;
            $prefix = $makePrefix($request->nama_ternak);

            $lastSeq = $getLastSeqForUser($request->user_id);
            $rows = [];

            for ($i = 1; $i <= $jumlah; $i++) {
                $seq   = $lastSeq + $i;
                $tagId = $formatTag($prefix, $seq);

                $rows[] = [
                    'tag_id'           => $tagId,
                    'user_id'          => $request->user_id,
                    'nama_ternak'      => $request->nama_ternak,
                    'tgl_mulai'        => $request->tgl_mulai,
                    'hewan_id'         => $request->hewan_id,
                    'ras_id'           => $request->ras_id,
                    'tujuan_ternak_id' => $request->tujuan_ternak_id,
                    'usia'             => 0,          // default batch
                    'kondisi_ternak'   => "Sehat",
                    'jenis_kelamin'    => '-',        // default batch
                    'berat'            => 0.0,          // default batch
                    'catatan'          => $request->catatan,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }

            TblTernak::insert($rows);

            return response()->json([
                'status'     => 'ok',
                'inserted'   => $jumlah,
                'prefix'     => $prefix,
                'from_tag'   => $rows[0]['tag_id'],
                'to_tag'     => $rows[$jumlah-1]['tag_id'],
            ], 201);
        });
    }


    /**
     * @OA\Put(
     *     path="/api/v1/ternak/{user_id}/{id}",
     *     summary="Mengupdate data ternak",
     *     description="Endpoint ini digunakan untuk mengupdate data ternak berdasarkan user_id dan id.",
     *     tags={"Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID ternak",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="nama_ternak", type="string", example="Sapi Perah"),
     *             @OA\Property(property="tgl_mulai", type="string", format="date", example="2025-08-20"),
     *             @OA\Property(property="hewan_id", type="integer", example=1),
     *             @OA\Property(property="ras_id", type="integer", example=1),
     *             @OA\Property(property="tujuan_ternak_id", type="integer", example=1),
     *             @OA\Property(property="usia", type="integer", example=24),
     *             @OA\Property(property="kondisi_ternak", type="string", example="Sehat"),
     *             @OA\Property(property="jenis_kelamin", type="string", example="Betina"),
     *             @OA\Property(property="berat", type="number", format="float", example=500.5),
     *             @OA\Property(property="catatan", type="string", example="Catatan tambahan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ternak berhasil diupdate",
     *         @OA\JsonContent(ref="#/components/schemas/Ternak")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ternak tidak ditemukan",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ternak tidak ditemukan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validasi gagal",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validasi gagal"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function updateTernak(Request $request, $user_id, $id)
    {
        $ternak = TblTernak::where('user_id', $user_id)->where('id', $id)->first();

        if (!$ternak) {
            return response()->json(['message' => 'Ternak tidak ditemukan'], 404);
        }

        $request->validate([
            'nama_ternak' => 'nullable|string',
            'tgl_mulai' => 'nullable|date',
            'hewan_id' => 'nullable|exists:m_hewan,id',
            'ras_id' => 'nullable|exists:m_ras,id',
            'tujuan_ternak_id' => 'nullable|exists:m_tujuan_ternak,id',
            'usia' => 'nullable|integer',
            'kondisi_ternak' => 'nullable|string',
            'jenis_kelamin' => 'nullable|string',
            'berat' => 'nullable|numeric',
            'catatan' => 'nullable|string',
        ]);

        $ternak->update($request->all());

        return response()->json($ternak);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/ternak/{user_id}/{id}",
     *     summary="Menghapus data ternak",
     *     description="Endpoint ini digunakan untuk menghapus data ternak berdasarkan user_id dan id.",
     *     tags={"Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID ternak",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ternak berhasil dihapus",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ternak berhasil dihapus")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ternak tidak ditemukan",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ternak tidak ditemukan")
     *         )
     *     )
     * )
     */
    public function destroyTernak($user_id, $id)
    {
        $ternak = TblTernak::where('user_id', $user_id)->where('id', $id)->first();

        if (!$ternak) {
            return response()->json(['message' => 'Ternak tidak ditemukan'], 404);
        }

        $ternak->delete();

        return response()->json(['message' => 'Ternak berhasil dihapus']);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/tujuan/ternak/{user_id}",
     *     summary="Mengambil semua tujuan ternak berdasarkan user_id",
     *     description="Endpoint ini digunakan untuk mengambil semua data tujuan ternak milik pengguna tertentu berdasarkan user_id.",
     *     tags={"Tujuan Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Berhasil mengambil data tujuan ternak",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/TujuanTernak")
     *         )
     *     )
     * )
     */
    public function getTujuanTernak($user_id)
    {
        $tujuanTernak = MTujuanTernak::where('user_id', $user_id)->get();
        return response()->json($tujuanTernak);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/tujuan/ternak/{user_id}",
     *     summary="Menyimpan tujuan ternak baru",
     *     description="Endpoint ini digunakan untuk membuat data tujuan ternak baru berdasarkan user_id.",
     *     tags={"Tujuan Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"nama"},
     *             @OA\Property(property="nama", type="string", example="Peternakan Sapi")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tujuan ternak berhasil dibuat",
     *         @OA\JsonContent(ref="#/components/schemas/TujuanTernak")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validasi gagal",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validasi gagal"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function storeTujuanTernak(Request $request, $user_id)
    {
        $request->validate([
            'nama' => 'required|string',
        ]);

        $tujuanTernak = new MTujuanTernak();
        $tujuanTernak->user_id = $user_id;
        $tujuanTernak->nama = $request->nama;
        $tujuanTernak->save();

        return response()->json($tujuanTernak, 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/tujuan/ternak/{user_id}",
     *     summary="Mengupdate tujuan ternak",
     *     description="Endpoint ini digunakan untuk mengupdate data tujuan ternak berdasarkan user_id.",
     *     tags={"Tujuan Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="nama", type="string", example="Peternakan Sapi")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tujuan ternak berhasil diupdate",
     *         @OA\JsonContent(ref="#/components/schemas/TujuanTernak")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tujuan ternak tidak ditemukan",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Tujuan Ternak tidak ditemukan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validasi gagal",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validasi gagal"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function updateTujuanTernak(Request $request, $user_id)
    {
        $tujuanTernak = MTujuanTernak::where('user_id', $user_id)->first();

        if (!$tujuanTernak) {
            return response()->json(['message' => 'Tujuan Ternak tidak ditemukan'], 404);
        }

        $request->validate([
            'nama' => 'nullable|string',
        ]);

        $tujuanTernak->update($request->all());

        return response()->json($tujuanTernak);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/tujuan/ternak/{user_id}",
     *     summary="Menghapus tujuan ternak",
     *     description="Endpoint ini digunakan untuk menghapus data tujuan ternak berdasarkan user_id.",
     *     tags={"Tujuan Ternak"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID pengguna (uid)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tujuan ternak berhasil dihapus",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Tujuan Ternak berhasil dihapus")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tujuan ternak tidak ditemukan",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Tujuan Ternak tidak ditemukan")
     *         )
     *     )
     * )
     */
    public function destroyTujuanTernak($user_id)
    {
        $tujuanTernak = MTujuanTernak::where('user_id', $user_id)->first();

        if (!$tujuanTernak) {
            return response()->json(['message' => 'Tujuan Ternak tidak ditemukan'], 404);
        }

        $tujuanTernak->delete();

        return response()->json(['message' => 'Tujuan Ternak berhasil dihapus']);
    }
}