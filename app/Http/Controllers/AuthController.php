<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ClusterAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Create user (admin only).
     *
     * Accepts abilities (admin|tenant|recordings), allowed_clusters, and sets
     * portable = false for admin users, true otherwise.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'endpoint' => 'nullable|numeric',
            'abilities' => 'nullable|array',
            'abilities.*' => ['string', Rule::in(array_keys(config('abilities.abilities', [])))],
            'allowed_clusters' => 'nullable|array',
            'allowed_clusters.*' => 'string',
            'password' => 'required|string',
        ]);

        $abilities = $this->normalizeAbilities($request->input('abilities', []));
        $isAdmin = in_array('admin', $abilities, true);
        $allowedClusters = ClusterAccess::resolveShortuids($request->input('allowed_clusters'));

        if (! $isAdmin && $allowedClusters === []) {
            return response()->json([
                'allowed_clusters' => ['Non-admin users require at least one allowed cluster.'],
            ], 422);
        }

        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'abilities' => $abilities,
            'allowed_clusters' => $isAdmin ? null : $allowedClusters,
            'portable' => ! $isAdmin,
            'endpoint' => $request->endpoint,
        ]);

        if ($user->save()) {
            $tokenResult = $user->createToken('Personal Access Token', $abilities);
            $token = $tokenResult->plainTextToken;

            return response()->json([
                'message' => 'Created new user '.$request->email.'!',
                'accessToken' => $token,
                'abilities' => $abilities,
                'allowed_clusters' => $user->allowed_clusters,
                'portable' => $user->portable,
            ], 201);
        }

        return response()->json(['error' => 'Incorrect details']);
    }

    /**
     * Login user and create token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember_me' => 'boolean',
        ]);

        $credentials = request(['email', 'password']);
        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $user = $request->user();
        $abilities = $this->normalizeAbilities($user->abilities);

        $tokenName = 'Personal Access Token - '.now()->toDateTimeString();
        Log::info('login '.$user->name.(in_array('admin', $abilities, true) ? ' as Admin' : ''));
        $tokenResult = $user->createToken($tokenName, $abilities);

        $token = $tokenResult->plainTextToken;

        return response()->json([
            'accessToken' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Index of users (admin).
     */
    public function index()
    {
        return User::orderBy('id', 'asc')->get();
    }

    /**
     * User by id (admin).
     */
    public function userById($id)
    {
        return User::where('id', $id)->get();
    }

    /**
     * User by email (admin).
     */
    public function userByEmail($email)
    {
        return User::where('email', $email)->get();
    }

    /**
     * User by name (admin).
     */
    public function userByName($name)
    {
        return User::where('name', $name)->get();
    }

    /**
     * User by endpoint (admin).
     */
    public function userByEndpoint($endpoint)
    {
        return User::where('endpoint', $endpoint)->get();
    }

    /**
     * Update user abilities / allowed_clusters / profile fields (admin).
     * On ability or scope change, all Sanctum tokens are revoked (force re-login).
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => "User $id not found"], 404);
        }

        $request->validate([
            'name' => 'sometimes|string',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'endpoint' => 'nullable|numeric',
            'abilities' => 'sometimes|array',
            'abilities.*' => ['string', Rule::in(array_keys(config('abilities.abilities', [])))],
            'allowed_clusters' => 'sometimes|nullable|array',
            'allowed_clusters.*' => 'string',
            'portable' => 'sometimes|boolean',
        ]);

        $prevAbilities = $this->normalizeAbilities($user->abilities);
        $prevClusters = ClusterAccess::resolveShortuids(
            is_array($user->allowed_clusters) ? $user->allowed_clusters : null
        );

        if ($request->has('name')) {
            $user->name = $request->input('name');
        }
        if ($request->has('email')) {
            $user->email = $request->input('email');
        }
        if ($request->exists('endpoint')) {
            $user->endpoint = $request->input('endpoint');
        }

        if ($request->has('abilities')) {
            $abilities = $this->normalizeAbilities($request->input('abilities', []));
            $user->abilities = $abilities;
            $isAdmin = in_array('admin', $abilities, true);
            $user->portable = $request->has('portable')
                ? (bool) $request->input('portable')
                : ! $isAdmin;
            if ($isAdmin) {
                $user->allowed_clusters = null;
            }
        } elseif ($request->has('portable')) {
            $user->portable = (bool) $request->input('portable');
        }

        if ($request->has('allowed_clusters') && ! $user->isAdminAbility()) {
            $resolved = ClusterAccess::resolveShortuids($request->input('allowed_clusters'));
            if ($resolved === []) {
                return response()->json([
                    'allowed_clusters' => ['Non-admin users require at least one allowed cluster.'],
                ], 422);
            }
            $user->allowed_clusters = $resolved;
        }

        $newAbilities = $this->normalizeAbilities($user->abilities);
        $newClusters = ClusterAccess::resolveShortuids(
            is_array($user->allowed_clusters) ? $user->allowed_clusters : null
        );

        sort($prevAbilities);
        sort($newAbilities);
        sort($prevClusters);
        sort($newClusters);

        $scopeChanged = $prevAbilities !== $newAbilities
            || $prevClusters !== $newClusters;

        $user->save();

        if ($scopeChanged) {
            $user->tokens()->delete();
        }

        return response()->json([
            ...$user->fresh()->toArray(),
            'tokens_revoked' => $scopeChanged,
        ]);
    }

    /**
     * Authenticated user changes their own password.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user('sanctum') ?? auth('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['current_password' => ['Current password is incorrect.']], 422);
        }

        $user->password = $request->input('password');
        $user->save();

        // Keep current token; revoke siblings so other sessions re-auth
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current?->id)->delete();

        return response()->json(['message' => 'Password updated']);
    }

    /**
     * Admin forces a password reset for another user and revokes all their tokens.
     */
    public function forcePassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => "User $id not found"], 404);
        }

        $user->password = $request->input('password');
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => "Password reset for user $id; tokens revoked",
        ]);
    }

    /**
     * Get the authenticated User (whoami).
     * Returns abilities (token), allowed_clusters, portable; optional cluster details.
     */
    public function user(Request $request)
    {
        $user = auth('sanctum')->user();
        $token = $user->currentAccessToken();

        $allowed = $user->isAdminAbility()
            ? null
            : $user->allowedClusterShortuids();

        $payload = [
            ...$user->toArray(),
            'abilities' => $token->abilities ?? [],
            'allowed_clusters' => $allowed,
        ];

        // Non-admin: always include cluster labels for SPA switcher; admin opt-in via query.
        if (! $user->isAdminAbility() || $request->boolean('with_clusters') || $request->boolean('cluster_details')) {
            $payload['clusters'] = $this->clusterDetailsForWhoami($user, $allowed);
        }

        return response()->json($payload);
    }

    /**
     * Logout user (Revoke the current token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Delete user by id (and revoke tokens).
     */
    public function delete($id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json([
                'message' => "User $id not found"], 404);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => "Successfully deleted user $id",
        ]);
    }

    /**
     * Revoke user tokens by id.
     */
    public function revoke($id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json([
                'message' => "User $id not found"], 404);
        }

        $user->tokens()->delete();

        return response()->json([
            'message' => "Successfully deleted tokens for user $id",
        ]);
    }

    /**
     * @param  list<string>|null  $allowedShortuids
     * @return list<array{shortuid: string, pkey: string|null, id: string|null}>
     */
    private function clusterDetailsForWhoami(User $user, ?array $allowedShortuids): array
    {
        try {
            $q = DB::table('cluster')->select(['id', 'shortuid', 'pkey']);
            if (! $user->isAdminAbility()) {
                if ($allowedShortuids === null || $allowedShortuids === []) {
                    return [];
                }
                $q->whereIn('shortuid', $allowedShortuids);
            }

            return $q->orderBy('pkey')->get()->map(fn ($row) => [
                'id' => $row->id !== null ? (string) $row->id : null,
                'shortuid' => (string) $row->shortuid,
                'pkey' => $row->pkey !== null ? (string) $row->pkey : null,
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return a flat array of ability strings for token creation.
     * Handles null, JSON string, or array; only string entries are included.
     */
    private function normalizeAbilities(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, fn ($a) => is_string($a)));
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values(array_filter($decoded, fn ($a) => is_string($a))) : [];
        }

        return [];
    }
}
