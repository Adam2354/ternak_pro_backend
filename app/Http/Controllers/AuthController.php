<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\MAsset;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MDefaultAsset;
use App\Models\MDefaultJenisTugas;
use App\Models\MDefaultTujuanTernak;
use App\Models\User;
use App\Models\MJenisTugas;
use App\Models\MTujuanTernak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/register",
     *     summary="Registrasi pengguna baru",
     *     description="Endpoint ini digunakan untuk mendaftarkan pengguna baru dalam aplikasi.",
     *     tags={"Autentikasi"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "password", "role_id"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="johndoe@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123"),
     *             @OA\Property(property="no_telepon", type="string", example="08123456789"),
     *             @OA\Property(property="role_id", type="integer", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User successfully registered",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="uid", type="string", example="123e4567-e89b-12d3-a456-426614174000"),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *                     @OA\Property(property="role_id", type="integer", example="1")
     *                 ),
     *                 @OA\Property(property="token", type="string", example="your-generated-token-here")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="name", type="array", @OA\Items(type="string", example="The name field is required")),
     *                 @OA\Property(property="email", type="array", @OA\Items(type="string", example="The email field is required"))
     *             )
     *         )
     *     )
     * )
     */
    public function register(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'no_telepon' => 'nullable|string|max:20',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $user = User::create([
                    'uid' => Str::uuid()->toString(),
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'no_telepon' => $request->no_telepon,
                    'role_id' => $request->role_id ?? 3,
                ]);

                $defaultJenisTugas = MDefaultJenisTugas::where('is_aktif', true)->get();
                foreach ($defaultJenisTugas as $jenisTugas) {
                    MJenisTugas::create([
                        'user_id' => $user->uid,
                        'nama' => $jenisTugas->nama,
                        'icon_path' => $jenisTugas->icon_path,
                        'created_at' => now()->format('Y-m-d H:i:s'),
                        'updated_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                }

                $defaultAset = MDefaultAsset::where('is_aktif', true)->get();
                foreach ($defaultAset as $aset) {
                    MAsset::create([
                        'user_id' => $user->uid,
                        'nama' => $aset->nama,
                        'created_at' => now()->format('Y-m-d H:i:s'),
                        'updated_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                }

                $defaultTujuanTernak = MDefaultTujuanTernak::where('is_aktif', true)->get();
                foreach ($defaultTujuanTernak as $tujuanTernak) {
                    MTujuanTernak::create([
                        'user_id' => $user->uid,
                        'nama' => $tujuanTernak->nama,
                        'created_at' => now()->format('Y-m-d H:i:s'),
                        'updated_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                }

                $conversation = Conversation::create([
                    'user_id' => $user->uid,
                    'title' => 'Conversation '.$user->name
                ]);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Halo ' . $user->name . '👋, Kenalin Aku Siternak Asisten Ternak Pribadimu!'
                ]);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Bagaimana Aku Bisa Membantumu ☺️ ?'
                ]);

                $token = $user->createToken('auth_token')->plainTextToken;

                return [
                    'user' => $user,
                    'token' => $token,
                ];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => [
                'user' => $result['user'],
                'token' => $result['token'],
            ],
        ], 201);
    }

    public function registerWeb(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'no_telepon' => 'nullable|string|max:20',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();  // Redirect back with errors and input data
        }

        try {
            DB::transaction(function () use ($request) {
                $user = User::create([
                    'uid' => Str::uuid()->toString(),
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'no_telepon' => $request->no_telepon,
                    'role_id' => $request->role_id ?? 3,
                ]);

                $defaultJenisTugas = MDefaultJenisTugas::where('is_aktif', true)->get();
                foreach ($defaultJenisTugas as $jenisTugas) {
                    MJenisTugas::create([
                        'user_id' => $user->uid,
                        'nama' => $jenisTugas->nama,
                        'icon_path' => $jenisTugas->icon_path,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $defaultAset = MDefaultAsset::where('is_aktif', true)->get();
                foreach ($defaultAset as $aset) {
                    MAsset::create([
                        'user_id' => $user->uid,
                        'nama' => $aset->nama,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $defaultTujuanTernak = MDefaultTujuanTernak::where('is_aktif', true)->get();
                foreach ($defaultTujuanTernak as $tujuanTernak) {
                    MTujuanTernak::create([
                        'user_id' => $user->uid,
                        'nama' => $tujuanTernak->nama,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $conversation = Conversation::create([
                    'user_id' => $user->uid,
                    'title' => 'Conversation '.$user->name
                ]);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Halo ' . $user->name . '👋, Kenalin Aku Siternak Asisten Ternak Pribadimu!'
                ]);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Bagaimana Aku Bisa Membantumu ☺️ ?'
                ]);
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['register' => 'Registration failed: ' . $e->getMessage()])->withInput();
        }

        // Redirect to login page after registration
        return redirect()->route('login')->with('status', 'Akun berhasil dibuat, silakan login.');
    }


    /**
     * @OA\Post(
     *     path="/api/v1/login",
     *     summary="Login pengguna",
     *     description="Endpoint ini digunakan untuk login ke aplikasi dengan email dan password.",
     *     tags={"Autentikasi"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="johndoe@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login berhasil",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="uid", type="string", example="123e4567-e89b-12d3-a456-426614174000"),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *                     @OA\Property(property="role_id", type="integer", example="1")
     *                 ),
     *                 @OA\Property(property="token", type="string", example="your-generated-token-here")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid credentials")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="email", type="array", @OA\Items(type="string", example="The email field is required")),
     *                 @OA\Property(property="password", type="array", @OA\Items(type="string", example="The password field is required"))
     *             )
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        // Validasi data request: terima `email` atau `email_or_phone`
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:email_or_phone|nullable|string|email',
            'email_or_phone' => 'required_without:email|nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data yang dimasukkan tidak sesuai',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Ambil input yang tersedia (email atau email_or_phone)
        $input = $request->input('email') ?? $request->input('email_or_phone');

        // Cek apakah input berupa email atau nomor telepon
        $user = null;
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $input)->first();
        } else {
            $user = User::where('no_telepon', $input)->first();
        }

        // Jika user tidak ditemukan atau password tidak cocok
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email/No Handphone atau Password salah',
            ], 401);
        }

        // Generate token dengan Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        // Kembalikan response login berhasil
        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'uid' => $user->uid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'no_telepon' => $user->no_telepon,
                    'role_id' => $user->role_id,
                ],
                'token' => $token,
            ],
        ], 200);
    }

    public function loginWeb(Request $request)
    {
        // Validasi data request: terima `email` atau `email_or_phone` untuk web login juga
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:email_or_phone|nullable|string|email',
            'email_or_phone' => 'required_without:email|nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data yang dimasukkan tidak sesuai',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Ambil input yang tersedia (email atau email_or_phone)
        $input = $request->input('email') ?? $request->input('email_or_phone');

        // Cek apakah input berupa email atau nomor telepon
        $user = null;
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $input)->first();
        } else {
            $user = User::where('no_telepon', $input)->first();
        }

        // Jika user tidak ditemukan atau password tidak cocok
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email/No Handphone atau Password salah',
            ], 401);
        }

        // Generate token dengan Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        // Data untuk dikirim ke frontend
        $userData = [
            'token' => $token,
            'user_id' => $user->uid,
            'email' => $user->email,
            'name' => $user->name,
            'role_id' => $user->role_id,
        ];

        // Menyimpan data login ke dalam session dan mengirimkan data untuk diproses di frontend
        session(['user_data' => $userData]);

        // Redirect ke halaman Flutter Web dan menggunakan JavaScript untuk menyimpan data ke localStorage
        return view('login_redirect', ['userData' => $userData]);
    }


}