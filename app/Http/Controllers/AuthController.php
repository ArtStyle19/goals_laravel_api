<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleEmailNotVerifiedException;
use App\Exceptions\InvalidGoogleTokenException;
use App\Models\User;
use App\Services\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $googleAuthService,
    ) {}
    /**
     * Register a new user.
     */
    #[OA\Post(
        path: '/register',
        operationId: 'register',
        summary: 'Registrar nuevo usuario',
        description: 'Crea una nueva cuenta de usuario y devuelve un token de autenticación Sanctum.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Juan Pérez'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'juan@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'password123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Usuario registrado exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Usuario registrado exitosamente'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        new OA\Property(property: 'token', type: 'string', example: '1|abc123def456...', description: 'Token Bearer de Sanctum'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Error de validación',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
            ),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login user and create token.
     */
    #[OA\Post(
        path: '/login',
        operationId: 'login',
        summary: 'Iniciar sesión',
        description: 'Autentica al usuario con email y contraseña. Devuelve un token Bearer de Sanctum.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'juan@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Inicio de sesión exitoso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Inicio de sesión exitoso'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        new OA\Property(property: 'token', type: 'string', example: '2|xyz789abc012...'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Credenciales incorrectas',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
            ),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($validated)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();

        // Revoke previous tokens (optional: single session)
        // $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout user (revoke current token).
     */
    #[OA\Post(
        path: '/logout',
        operationId: 'logout',
        summary: 'Cerrar sesión',
        description: 'Revoca el token actual del usuario autenticado.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesión cerrada exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Sesión cerrada exitosamente'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'No autenticado',
                content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Only delete if it's a real token (not TransientToken from actingAs in tests)
        if (method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Sesión cerrada exitosamente',
        ]);
    }

    /**
     * Get authenticated user.
     */
    #[OA\Get(
        path: '/me',
        operationId: 'me',
        summary: 'Obtener usuario autenticado',
        description: 'Devuelve la información del usuario autenticado.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Datos del usuario',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'No autenticado',
                content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')
            ),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Login or register via Google Sign-In.
     *
     * Receives the Google ID token from the Flutter app, verifies it
     * server-side using google/apiclient, and returns a Sanctum token.
     */
    #[OA\Post(
        path: '/auth/google',
        operationId: 'loginWithGoogle',
        summary: 'Iniciar sesión con Google',
        description: 'Autentica o registra al usuario usando un ID token de Google. Retorna un token Sanctum.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['id_token'],
                properties: [
                    new OA\Property(property: 'id_token', type: 'string', description: 'Google ID token (JWT)'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Inicio de sesión con Google exitoso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Inicio de sesión con Google exitoso'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        new OA\Property(property: 'token', type: 'string', example: '3|abc123...'),
                        new OA\Property(property: 'is_new_user', type: 'boolean', example: false),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token de Google inválido'),
            new OA\Response(response: 403, description: 'Email de Google no verificado'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function loginWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $payload = $this->googleAuthService->verifyIdToken($validated['id_token']);
            $user = $this->googleAuthService->findOrCreateUser($payload);
        } catch (InvalidGoogleTokenException) {
            return response()->json([
                'message' => 'Token de Google inválido.',
            ], 401);
        } catch (GoogleEmailNotVerifiedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        }

        $token = $user->createToken('google-auth')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión con Google exitoso',
            'user' => $user,
            'token' => $token,
            'is_new_user' => $user->wasRecentlyCreated,
        ]);
    }
}
