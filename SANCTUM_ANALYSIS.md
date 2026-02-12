# Laravel Sanctum Implementation Analysis

## Executive Summary

This document analyzes the pbx3api implementation of Laravel Sanctum compared against official best practices, **including the Vue.js SPA frontend** (`pbx3spa`) that consumes the API.

### API (pbx3api) Summary
The implementation has several **critical issues** that deviate from Sanctum's intended usage, particularly around token ability checking and middleware usage. **Overall Score: 60%**

### Frontend (pbx3spa) Summary
The Vue.js SPA follows **best practices** for token-based authentication. Token storage, Bearer token usage, 401 handling, and logout are all correctly implemented. **Overall Score: 90%** - No critical issues found.

---

## ✅ What's Done Correctly

### 1. **Model Setup**
- ✅ `User` model correctly uses `HasApiTokens` trait
- ✅ Properly configured in `config/auth.php`

### 2. **Token Creation**
- ✅ Tokens are created using `createToken()` method
- ✅ Abilities are assigned during token creation (`['admin:isAdmin']`)
- ✅ Returns `plainTextToken` correctly

### 3. **Route Protection**
- ✅ Routes are protected with `auth:sanctum` middleware
- ✅ Login endpoint is correctly unprotected

### 4. **Configuration**
- ✅ Sanctum config file exists and is properly structured
- ✅ Migration for `personal_access_tokens` table is correct

---

## ❌ Critical Issues & Deviations from Best Practices

### 1. **Manual Ability Checking Instead of Middleware** ⚠️ CRITICAL

**Issue:** The code bypasses Sanctum's built-in ability checking middleware and manually queries the database.

**Current Implementation:**
```php
// routes/api.php lines 57, 77
if (get_token_abilities()) {
    Route::post('register', [AuthController::class, 'register']);
    // ...
}

// app/Helpers/Helper.php lines 15-34
function get_token_abilities() {
    $token = request()->bearerToken();
    $bits = preg_split("/\|/",$token);
    $hashtoken = hash('sha256', $bits[1]);
    $abilities = DB::table('personal_access_tokens')->where('token', $hashtoken)->value('abilities');
    if (preg_match("/isAdmin/",$abilities)) {
        return true;
    }
    return false;
}
```

**Problems:**
- ❌ **Bypasses Sanctum's authentication**: Manually parsing tokens instead of using authenticated user
- ❌ **Inefficient**: Database query on every route definition evaluation (routes are evaluated on every request)
- ❌ **Security risk**: Manual token parsing could be error-prone
- ❌ **Not using middleware**: Sanctum's `CheckAbilities` middleware is registered but never used
- ❌ **Route-level conditionals**: Using `if` statements in route files is an anti-pattern

**Best Practice:**
```php
// Should use Sanctum middleware
Route::middleware(['auth:sanctum', 'abilities:admin:isAdmin'])->group(function() {
    Route::post('register', [AuthController::class, 'register']);
    // ...
});
```

### 2. **Middleware Registered But Not Used** ⚠️ MAJOR

**Issue:** `CheckAbilities` and `CheckForAnyAbility` middleware are registered in `bootstrap/app.php` but never utilized.

**Current Implementation:**
```php
// bootstrap/app.php lines 25-28
$middleware->alias([
    'abilities' => CheckAbilities::class,
    'ability' => CheckForAnyAbility::class,
]);
```

**Problem:**
- ❌ Middleware is registered but routes use `if (get_token_abilities())` instead
- ❌ Wasted configuration that suggests incomplete implementation

### 3. **Token Deletion on Login** ⚠️ MAJOR

**Issue:** All user tokens are deleted on every login.

**Current Implementation:**
```php
// AuthController.php line 83
$user->tokens()->delete();
```

**Problems:**
- ❌ **Poor UX**: Invalidates all existing sessions/tokens (mobile apps, other devices)
- ❌ **Not standard practice**: Users expect to maintain multiple active sessions
- ❌ **Security concern**: If this is intended for security, it should be optional/configurable

**Best Practice:**
- Only delete tokens on explicit logout
- Optionally limit concurrent tokens per user
- Consider token expiration instead

### 4. **No Token Expiration** ⚠️ SECURITY RISK

**Issue:** Token expiration is set to `null` (never expires).

**Current Implementation:**
```php
// config/sanctum.php line 49
'expiration' => null,
```

**Problems:**
- ❌ **Security risk**: Compromised tokens remain valid indefinitely
- ❌ **No rotation**: Old tokens never expire
- ❌ **Compliance**: May violate security policies requiring token rotation

**Best Practice:**
```php
'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 30), // 30 days
```

### 5. **Stateful Middleware for API-Only Application** ⚠️ MINOR

**Issue:** `EnsureFrontendRequestsAreStateful` middleware is included but this appears to be an API-only application.

**Current Implementation:**
```php
// app/Http/Kernel.php line 16
\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
```

**Problem:**
- ⚠️ This middleware is for SPA authentication with cookies, not token-based APIs
- ⚠️ May cause unnecessary overhead for pure API usage

**Best Practice:**
- Remove if not using SPA cookie-based authentication
- Keep only if frontend SPA uses Sanctum cookies

### 6. **Inconsistent Ability Format** ⚠️ MINOR

**Issue:** Ability string format inconsistency.

**Current Implementation:**D@s@n1j0
```php
// AuthController.php line 87
$tokenResult = $user->createToken('Personal Access Token',['admin:isAdmin']);

// Helper.php line 29
if (preg_match("/isAdmin/",$abilities)) {
```

**Problem:**
- ⚠️ Ability stored as `['admin:isAdmin']` but checked for `isAdmin` substring
- ⚠️ Should use consistent format or proper JSON parsing

**Best Practice:**
```php
// Store as array
$user->createToken('Personal Access Token', ['admin:isAdmin']);

// Check properly
$abilities = json_decode($token->abilities, true);
if (in_array('admin:isAdmin', $abilities)) {
    // ...
}
```

### 7. **Logging Sensitive Information** ⚠️ SECURITY RISK

**Issue:** Bearer tokens are logged in plain text.

**Current Implementation:**
```php
// Helper.php lines 23, 26, 28
Log::info("Bearer token is " . $token);
Log::info("hash token is " . $hashtoken);
Log::info("Abilities is " . $abilities);
```

**Problems:**
- ❌ **Security risk**: Tokens in logs can be compromised
- ❌ **Compliance**: May violate security policies
- ❌ **Debugging overhead**: Excessive logging in production

**Best Practice:**
- Never log tokens or sensitive data
- Use debug-level logging only in development
- Log only token IDs or hashes (not full tokens)

### 8. **Missing Authenticate Middleware** ⚠️ MINOR

**Issue:** `Kernel.php` references `\App\Http\Middleware\Authenticate::class` but file doesn't exist.

**Current Implementation:**
```php
// app/Http/Kernel.php line 23
'auth' => \App\Http\Middleware\Authenticate::class,
```

**Problem:**
- ⚠️ May fall back to Laravel's default, but custom middleware referenced doesn't exist
- ⚠️ Could cause issues if middleware is actually called

### 9. **ValidateClusterAccess Middleware Issue** ⚠️ BUG

**Issue:** Middleware references non-existent method.

**Current Implementation:**
```php
// ValidateClusterAccess.php line 16
$abilities = Helper::getTokenAbilities($request->bearerToken());
```

**Problem:**
- ❌ `Helper::getTokenAbilities()` doesn't exist (it's a global function `get_token_abilities()`)
- ❌ This middleware will fail if executed

---

## 🔒 Security Concerns

1. **Token Parsing**: Manual token parsing could introduce vulnerabilities
2. **No Expiration**: Tokens never expire, increasing risk of long-term compromise
3. **Token Logging**: Bearer tokens logged in plain text
4. **Ability Bypass**: Manual ability checking bypasses Sanctum's security features
5. **Route-Level Security**: Security checks in route definitions instead of middleware

---

## 🌐 Frontend SPA (pbx3spa) Analysis

### ✅ What's Done Correctly

The Vue.js SPA (`pbx3spa`) follows **best practices** for token-based authentication:

#### 1. **Token Storage** ✅
- ✅ Uses `sessionStorage` (not `localStorage`) - tokens cleared when tab closes
- ✅ Properly handles storage errors (private browsing mode)
- ✅ Stores both `baseUrl` and `token` for multi-instance support

**Implementation:**
```javascript
// src/stores/auth.js
const STORAGE_KEY_TOKEN = 'pbx3_token'
sessionStorage.setItem(STORAGE_KEY_TOKEN, this.token)
```

#### 2. **Bearer Token Usage** ✅
- ✅ Correctly sends `Authorization: Bearer <token>` header
- ✅ Only includes Authorization header when token exists
- ✅ Properly formats headers for all request types

**Implementation:**
```javascript
// src/api/client.js
const headers = {
  Accept: 'application/json',
  ...(token ? { Authorization: `Bearer ${token}` } : {})
}
```

#### 3. **401 Response Handling** ✅
- ✅ Automatically clears credentials on 401 (unauthorized)
- ✅ Redirects to login page on authentication failure
- ✅ Handles both regular requests and blob requests

**Implementation:**
```javascript
// src/api/client.js lines 54-56
if (res.status === 401) {
  useAuthStore().clearCredentials()
  window.location.replace('/login')
}
```

#### 4. **Logout Implementation** ✅
- ✅ Calls API logout endpoint to revoke token server-side
- ✅ Clears credentials even if logout API call fails (network resilience)
- ✅ Properly redirects to login page

**Implementation:**
```javascript
// src/layouts/AppLayout.vue lines 21-29
async function logout() {
  try {
    await getApiClient().get('auth/logout')
  } catch {
    // still clear and redirect
  }
  auth.clearCredentials()
  router.push('/login')
}
```

#### 5. **Route Protection** ✅
- ✅ Router guard checks authentication before allowing access
- ✅ Redirects unauthenticated users to login
- ✅ Allows access to login page without authentication

**Implementation:**
```javascript
// src/router/index.js lines 73-78
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.path !== '/login' && !auth.isLoggedIn) {
    return { path: '/login' }
  }
})
```

#### 6. **User State Management** ✅
- ✅ Fetches user info after login (`whoami` endpoint)
- ✅ Handles optional user fetch gracefully
- ✅ Stores user data for display ("Logged in as X")

**Implementation:**
```javascript
// src/views/LoginView.vue lines 27-32
try {
  const user = await getApiClient().get('auth/whoami')
  auth.setUser(user)
} catch {
  // whoami optional
}
```

### ⚠️ Areas for Improvement (When API is Fixed)

#### 1. **Token Expiration Handling** ⚠️

**Current State:** No expiration handling (because API tokens don't expire)

**When API Adds Expiration:**
- Add token expiration check before API calls
- Refresh token if expired (if refresh tokens are implemented)
- Show "Session expired" message and redirect to login
- Consider checking token expiration on app mount

**Recommended Implementation (when API supports it):**
```javascript
// In auth store
checkTokenExpiration() {
  // If API returns expires_at, check it
  // If expired, clear credentials and redirect
}

// In API client
async function request(method, path, body) {
  // Check token expiration before request
  if (isTokenExpired()) {
    useAuthStore().clearCredentials()
    window.location.replace('/login')
    return
  }
  // ... rest of request
}
```

#### 2. **Ability-Based UI Rendering** ⚠️

**Current State:** Frontend doesn't check token abilities for UI purposes

**When API Uses Proper Ability Middleware:**
- Parse token abilities from `whoami` response (if API includes them)
- Hide/show admin-only UI elements based on abilities
- Show appropriate error messages for permission-denied actions

**Recommended Implementation:**
```javascript
// In auth store
getters: {
  hasAbility(state) {
    return (ability) => {
      return state.user?.abilities?.includes(ability) ?? false
    }
  },
  isAdmin(state) {
    return this.hasAbility('admin:isAdmin')
  }
}

// In components
<button v-if="auth.isAdmin" @click="deleteUser">Delete User</button>
```

#### 3. **Error Handling Enhancement** ⚠️

**Current State:** Basic error handling exists

**Improvements:**
- Distinguish between 401 (unauthorized) and 403 (forbidden) responses
- Show user-friendly messages for permission errors
- Handle network errors separately from auth errors

**Recommended Implementation:**
```javascript
if (res.status === 401) {
  // Unauthorized - token invalid/expired
  useAuthStore().clearCredentials()
  window.location.replace('/login')
} else if (res.status === 403) {
  // Forbidden - insufficient permissions
  throw new Error('You do not have permission to perform this action')
}
```

#### 4. **Token Refresh on App Mount** ⚠️

**Current State:** User info is fetched on mount if missing

**Enhancement:**
- Verify token is still valid on app mount
- Handle expired tokens gracefully
- Consider silent token refresh if supported

**Current Implementation (already good):**
```javascript
// src/layouts/AppLayout.vue lines 10-18
onMounted(async () => {
  if (auth.isLoggedIn && !auth.user) {
    try {
      const user = await getApiClient().get('auth/whoami')
      auth.setUser(user)
    } catch {
      // token may be expired; leave user null
    }
  }
})
```

### 📊 Frontend Compliance Score

| Category | Score | Notes |
|----------|-------|-------|
| **Token Storage** | ✅ 100% | Uses sessionStorage correctly |
| **Bearer Token Usage** | ✅ 100% | Properly formatted headers |
| **401 Handling** | ✅ 100% | Correctly clears and redirects |
| **Logout** | ✅ 100% | Calls API and clears state |
| **Route Protection** | ✅ 100% | Router guard implemented |
| **Error Handling** | ⚠️ 80% | Good, but could distinguish 401/403 |
| **Token Expiration** | ⚠️ N/A | Not applicable (API doesn't expire) |
| **Ability-Based UI** | ⚠️ 60% | Doesn't check abilities for UI |
| **Overall** | ✅ **90%** | **Excellent implementation** |

### 🎯 Frontend Recommendations Summary

**No Critical Issues Found** - The frontend implementation is solid and follows best practices.

**When API is Fixed:**
1. Add token expiration handling (if API implements expiration)
2. Add ability-based UI rendering (if API includes abilities in responses)
3. Enhance error handling to distinguish 401 vs 403
4. Consider adding token refresh mechanism (if API supports it)

**Current State:** The frontend is **well-implemented** and ready for production use. The only improvements needed are enhancements that depend on API-side fixes (token expiration, proper ability middleware).

---

## 📋 Recommendations

### High Priority

1. **Replace Manual Ability Checking with Middleware**
   ```php
   // Remove get_token_abilities() helper
   // Use Sanctum middleware instead:
   Route::middleware(['auth:sanctum', 'abilities:admin:isAdmin'])->group(function() {
       // admin routes
   });
   ```

2. **Fix Token Deletion on Login**
   ```php
   // Only delete on explicit logout
   // Consider: $user->tokens()->where('name', 'specific-token')->delete();
   ```

3. **Set Token Expiration**
   ```php
   // config/sanctum.php
   'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 30), // 30 days
   ```

4. **Remove Token Logging**
   ```php
   // Remove all Log::info() calls that log tokens
   // Use Log::debug() only in development if needed
   ```

### Medium Priority

5. **Fix ValidateClusterAccess Middleware**
   ```php
   // Use proper Sanctum ability checking
   if (!$request->user()->tokenCan('cluster:' . $request->cluster)) {
       return response()->json(['error' => 'Unauthorized'], 403);
   }
   ```

6. **Remove Unused Middleware Registration**
   - If not using SPA cookies, remove `EnsureFrontendRequestsAreStateful`
   - Keep `CheckAbilities` and `CheckForAnyAbility` but actually use them

7. **Create Missing Authenticate Middleware**
   - Either create the file or remove the reference from Kernel.php

### Low Priority

8. **Standardize Ability Format**
   - Use consistent ability naming
   - Parse JSON abilities properly

9. **Add Token Management**
   - Consider adding token listing/revocation endpoints
   - Add ability to see active tokens per user

10. **Include Abilities in whoami Response** (For Frontend)
    ```php
    // AuthController.php user() method
    $user = auth('sanctum')->user();
    return response()->json([
        ...$user->toArray(),
        'abilities' => $user->currentAccessToken()->abilities ?? []
    ]);
    ```
    This allows the frontend to conditionally render UI based on user abilities.

---

## 📚 Sanctum Best Practices Summary

Based on Laravel documentation and community best practices:

1. ✅ **Use Middleware for Abilities**: Always use `abilities:` or `ability:` middleware
2. ✅ **Set Token Expiration**: Configure reasonable expiration times
3. ✅ **Don't Log Tokens**: Never log sensitive authentication data
4. ✅ **Use tokenCan() Method**: Check abilities in controllers using `$user->tokenCan('ability')`
5. ✅ **Scope Permissions**: Grant minimum required abilities per token
6. ✅ **Token Lifecycle**: Manage token creation/deletion appropriately
7. ✅ **SPA vs API**: Use stateful middleware only for SPA cookie authentication

---

## 🎯 Migration Path

To migrate to best practices:

### API (pbx3api) Migration

1. **Phase 1: Fix Critical Security Issues**
   - Remove token logging
   - Set token expiration
   - Fix ValidateClusterAccess middleware

2. **Phase 2: Replace Manual Ability Checking**
   - Replace `if (get_token_abilities())` with middleware
   - Remove `get_token_abilities()` helper
   - Test all admin routes
   - Update `whoami` endpoint to include user abilities in response

3. **Phase 3: Improve Token Management**
   - Fix token deletion on login
   - Add proper token management endpoints
   - Document token lifecycle

4. **Phase 4: Cleanup**
   - Remove unused middleware if not needed
   - Create missing Authenticate middleware
   - Standardize ability format

### Frontend (pbx3spa) Migration

**Note:** The frontend is already well-implemented. These changes are only needed **after** API fixes:

1. **Phase 1: Token Expiration Handling** (After API adds expiration)
   - Add token expiration check utility
   - Check expiration before API calls
   - Handle expired tokens gracefully

2. **Phase 2: Ability-Based UI** (After API uses proper middleware)
   - Parse abilities from `whoami` response
   - Add ability checking getters to auth store
   - Conditionally render admin-only UI elements

3. **Phase 3: Enhanced Error Handling** (After API distinguishes 401/403)
   - Distinguish between 401 (unauthorized) and 403 (forbidden)
   - Show user-friendly permission error messages
   - Handle network errors separately

**Frontend Migration Priority:** **Low** - Frontend is production-ready. Changes are enhancements that depend on API improvements.

---

## 📊 Compliance Score

| Category | Score | Notes |
|----------|-------|-------|
| **Model Setup** | ✅ 100% | Correctly implemented |
| **Token Creation** | ✅ 90% | Good, but token deletion issue |
| **Route Protection** | ⚠️ 40% | Uses manual checking instead of middleware |
| **Ability Management** | ❌ 20% | Bypasses Sanctum's built-in system |
| **Security** | ⚠️ 50% | Multiple security concerns |
| **Configuration** | ✅ 80% | Good, but missing expiration |
| **Overall** | ⚠️ **60%** | **Needs significant improvements** |

---

## 🔗 References

- [Laravel Sanctum Documentation](https://laravel.com/docs/11.x/sanctum)
- [Sanctum Token Abilities Guide](https://laravel.com/docs/11.x/sanctum#token-abilities)
- [Sanctum Middleware Documentation](https://laravel.com/docs/11.x/sanctum#protecting-routes)

---

## 📝 Summary

### API (pbx3api) - Needs Improvement
- **Critical Issues:** Manual ability checking bypasses Sanctum middleware
- **Security Concerns:** Token logging, no expiration, manual token parsing
- **Action Required:** Replace manual checks with Sanctum middleware, add token expiration, remove token logging

### Frontend (pbx3spa) - Production Ready ✅
- **Status:** Well-implemented following best practices
- **Strengths:** Proper token storage, Bearer token usage, 401 handling, logout implementation
- **Action Required:** None immediately. Enhancements can be added after API fixes (token expiration handling, ability-based UI)

### Integration Notes
- Frontend correctly consumes the API as currently implemented
- Frontend will benefit from API improvements (ability-based UI, token expiration)
- No breaking changes needed in frontend for current API fixes

---

## 🔧 Stepwise Implementation Plan

This section provides a detailed, step-by-step plan to fix all identified issues. Execute steps in order, testing after each phase.

### ⚠️ Important: Phase Dependencies and Standalone Status

**CRITICAL:** Not all phases are standalone. Some phases must be completed together or the system will break.

**✅ ANSWER: Yes, completing entire phases is safe!** Each phase, when completed fully, leaves the system in a functional state.

| Phase | Standalone? | Safe to Stop? | Notes |
|-------|-------------|---------------|-------|
| **Phase 1** | ✅ YES | ✅ YES | All steps are independent and safe |
| **Phase 2** | ❌ NO | ⚠️ PARTIAL | Steps 2.2-2.4 must be completed together |
| **Phase 3** | ✅ YES | ✅ YES | All steps are independent |
| **Phase 4** | ✅ YES | ✅ YES | All steps are independent |
| **Phase 5** | ✅ YES | ✅ YES | Optional frontend enhancements |

**Key Dependency:** Phase 2 Steps 2.2, 2.3, and 2.4 are interdependent:
- If you replace routes with middleware (Step 2.2 or 2.3), you MUST also remove `get_token_abilities()` (Step 2.4) or routes will break
- You CANNOT partially replace routes - it's all-or-nothing for each route group
- However, you CAN replace auth routes (2.2) separately from main admin routes (2.3), as long as you remove the helper after BOTH are done

**Safe Stopping Points:**
- ✅ After Phase 1 (all steps) - System fully functional
- ✅ After Phase 2 Step 2.1 (test route only) - System fully functional
- ⚠️ After Phase 2 Step 2.2 ONLY if you also do Step 2.4 - System functional but inconsistent
- ❌ After Phase 2 Step 2.2 WITHOUT Step 2.4 - System will break (helper still needed)
- ✅ After Phase 2 (all steps) - System fully functional with new middleware
- ✅ After Phase 3 (all steps) - System fully functional
- ✅ After Phase 4 (all steps) - System fully functional

**🎯 Simple Rule: Complete entire phases, and you're safe!**

| Complete This Phase | System Status After |
|---------------------|---------------------|
| ✅ Phase 1 | Fully functional, security improved |
| ✅ Phase 2 (all steps) | Fully functional, using proper middleware |
| ✅ Phase 3 | Fully functional, token management improved |
| ✅ Phase 4 | Fully functional, cleaned up |
| ✅ Phase 5 | Enhanced (optional frontend improvements) |

**⚠️ Only Exception:** Within Phase 2, Steps 2.2, 2.3, and 2.4 must be done together. But if you complete the entire Phase 2 (all 4 steps), you're safe.

### Phase 1: Critical Security Fixes (Do First)

#### Step 1.1: Remove Token Logging
**Priority:** 🔴 CRITICAL  
**Risk:** Low (removal only)  
**Estimated Time:** 5 minutes

**Actions:**
1. Open `app/Helpers/Helper.php`
2. Remove or comment out these lines:
   ```php
   Log::info("In get_token_abilities");
   Log::info("Bearer token is " . $token);
   Log::info("hash token is " . $hashtoken);
   Log::info("Abilities is " . $abilities);
   Log::info("ability is true");
   ```
3. Keep only essential error logging if needed (use `Log::error()` for actual errors)

**Testing:**
- Login and verify no tokens appear in logs
- Check `storage/logs/laravel.log` after login

**Rollback:** Restore lines from git if needed

---

#### Step 1.2: Set Token Expiration
**Priority:** 🔴 CRITICAL  
**Risk:** Medium (may affect existing tokens)  
**Estimated Time:** 10 minutes

**Actions:**
1. Open `config/sanctum.php`
2. Change line 49 from:
   ```php
   'expiration' => null,
   ```
   To:
   ```php
   'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 30), // 30 days default
   ```
3. Add to `.env` file:
   ```
   SANCTUM_TOKEN_EXPIRATION=2592000
   ```
   (2592000 = 30 days in minutes)

**Testing:**
- Create a new token via login
- Verify token has `expires_at` set in `personal_access_tokens` table
- Test that token works before expiration
- Note: Existing tokens without expiration will still work until they're recreated

**Rollback:** Change back to `null` if issues occur

**Note:** Consider shorter expiration (7-14 days) for production

---

#### Step 1.3: Fix ValidateClusterAccess Middleware
**Priority:** 🟡 HIGH  
**Risk:** Medium (middleware may be in use)  
**Estimated Time:** 15 minutes

**Actions:**
1. Open `app/Http/Middleware/ValidateClusterAccess.php`
2. Replace the entire `handle()` method:
   ```php
   public function handle(Request $request, Closure $next)
   {
       if ($request->has('cluster')) {
           $user = $request->user('sanctum');
           
           if (!$user) {
               return response()->json([
                   'error' => 'Unauthorized',
                   'message' => 'Authentication required'
               ], 401);
           }
           
           // Check if user has cluster ability
           if (!$user->tokenCan('cluster:' . $request->cluster)) {
               return response()->json([
                   'error' => 'Unauthorized cluster access',
                   'message' => 'You do not have permission to access this cluster'
               ], 403);
           }
       }
       
       return $next($request);
   }
   ```
3. Remove the incorrect `Helper::getTokenAbilities()` call

**Testing:**
- Test routes that use `validate.cluster` middleware
- Verify 403 response for unauthorized cluster access
- Verify 401 response when not authenticated

**Rollback:** Restore from git

---

### Phase 2: Replace Manual Ability Checking (Core Refactoring)

⚠️ **IMPORTANT:** This phase has dependencies. Read carefully:
- Steps 2.2 and 2.3 can be done separately, but Step 2.4 MUST be done after BOTH 2.2 and 2.3 are complete
- If you only do Step 2.2, you MUST also do Step 2.4 immediately after, or routes will break
- The safest approach: Do Steps 2.1 (test), then 2.2 + 2.4 together, then 2.3 + verify 2.4 still works

#### Step 2.1: Create Test Route Group with Sanctum Middleware
**Priority:** 🟡 HIGH  
**Risk:** Low (testing only)  
**Estimated Time:** 20 minutes  
**Standalone:** ✅ YES - Safe to stop here

**Actions:**
1. Open `routes/api.php`
2. Create a test route group BEFORE the existing admin routes (around line 69):
   ```php
   // TEST: New Sanctum middleware approach
   Route::middleware(['auth:sanctum', 'abilities:admin:isAdmin'])->group(function() {
       Route::get('test/admin-only', function() {
           return response()->json(['message' => 'Admin access granted']);
       });
   });
   ```

**Testing:**
- Login as admin user → should return 200
- Login as non-admin user → should return 403
- No token → should return 401
- Verify this works before proceeding

**Rollback:** Remove test route

**Safe Stopping Point:** ✅ System fully functional with test route

---

#### Step 2.2: Replace Auth Routes with Middleware
**Priority:** 🟡 HIGH  
**Risk:** Medium (affects user management)  
**Estimated Time:** 30 minutes  
**Standalone:** ⚠️ NO - Must complete Step 2.4 immediately after

**⚠️ CRITICAL:** After this step, `get_token_abilities()` is still used by main admin routes (Step 2.3). You have two options:
- **Option A (Recommended):** Complete Steps 2.2, 2.3, then 2.4 together in one session
- **Option B (Incremental):** Do Step 2.2, then immediately do Step 2.4, then later do Step 2.3

**Actions:**
1. Open `routes/api.php`
2. Find the auth routes section (around line 54)
3. Replace:
   ```php
   if (get_token_abilities()) {
       Route::post('register', [AuthController::class, 'register']);
       // ... other routes
   }
   ```
   With:
   ```php
   Route::middleware(['auth:sanctum', 'abilities:admin:isAdmin'])->group(function() {
       Route::post('register', [AuthController::class, 'register']);
       Route::get('users', [AuthController::class, 'index']);
       Route::get('users/mail/{email}', [AuthController::class, 'userByEmail']);
       Route::get('users/name/{name}', [AuthController::class, 'userByName']);
       Route::get('users/endpoint/{endpoint}', [AuthController::class, 'userByEndpoint']);
       Route::delete('users/revoke/{id}', [AuthController::class, 'revoke']);
       Route::get('users/{id}', [AuthController::class, 'userById']);
       Route::delete('users/{id}', [AuthController::class, 'delete']);
   });
   ```

**Testing:**
- Test each route as admin → should work
- Test each route as non-admin → should return 403
- Test without token → should return 401
- Verify all user management endpoints work correctly

**Rollback:** Restore `if (get_token_abilities())` block

**⚠️ Next Step Required:** If stopping here, you MUST also complete Step 2.4, or main admin routes (Step 2.3) will break

---

#### Step 2.3: Replace Main Admin Routes with Middleware
**Priority:** 🟡 HIGH  
**Risk:** High (affects all admin functionality)  
**Estimated Time:** 45 minutes  
**Standalone:** ⚠️ NO - Must complete Step 2.4 immediately after

**⚠️ CRITICAL:** This step removes the last usage of `get_token_abilities()`. After this, Step 2.4 MUST be completed or the helper function will cause errors if called elsewhere.

**Actions:**
1. Open `routes/api.php`
2. Find the main admin routes section (around line 69)
3. Replace:
   ```php
   Route::group(['middleware' => 'auth:sanctum'], function() {
       if (get_token_abilities()) {
           // ... all admin routes
       }
   });
   ```
   With:
   ```php
   Route::middleware(['auth:sanctum', 'abilities:admin:isAdmin'])->group(function() {
       // ... all admin routes (remove the if statement)
   });
   ```

**Testing:**
- Test a sample of routes from each section (agents, extensions, trunks, etc.)
- Verify admin access works
- Verify non-admin gets 403
- Test critical operations (create, update, delete)

**Rollback:** Restore original structure

**⚠️ Next Step Required:** You MUST complete Step 2.4 immediately after this, or the system may have issues

**Note:** This is the biggest change. Consider doing this during low-traffic period.

---

#### Step 2.4: Remove get_token_abilities() Helper Function
**Priority:** 🟡 HIGH  
**Risk:** Low (after routes are fixed)  
**Estimated Time:** 5 minutes  
**Standalone:** ❌ NO - Can ONLY be done after Steps 2.2 AND 2.3 are complete

**⚠️ CRITICAL:** This step MUST be done after Steps 2.2 and 2.3. If you remove this function before replacing all route usages, the system will break.

**Actions:**
1. Verify Steps 2.2 and 2.3 are complete (all routes use middleware, not `get_token_abilities()`)
2. Open `app/Helpers/Helper.php`
3. Remove or comment out the entire `get_token_abilities()` function (lines 15-34)
4. Search codebase for any other references:
   ```bash
   grep -r "get_token_abilities" .
   ```
5. Remove any remaining references (should only be in comments/docs now)

**Testing:**
- Verify no routes use `get_token_abilities()` anymore
- Test login/logout flow
- Test admin routes work correctly
- Test non-admin gets 403 on admin routes
- Run full test suite if available

**Rollback:** Restore function from git

**Safe Stopping Point:** ✅ System fully functional with new middleware approach

---

### Phase 3: Fix Token Management

✅ **Standalone Phase:** All steps are independent and safe to implement separately.

#### Step 3.1: Fix Token Deletion on Login
**Priority:** 🟢 MEDIUM  
**Risk:** Medium (changes login behavior)  
**Estimated Time:** 15 minutes  
**Standalone:** ✅ YES - Safe to implement independently

**Actions:**
1. Open `app/Http/Controllers/AuthController.php`
2. Find the `login()` method (around line 65)
3. Remove or comment out line 83:
   ```php
   // $user->tokens()->delete(); // REMOVED: Don't delete all tokens on login
   ```
4. Optionally, add token name tracking:
   ```php
   $tokenName = 'Personal Access Token - ' . now()->toDateTimeString();
   $tokenResult = $user->createToken($tokenName, ['admin:isAdmin']);
   ```

**Testing:**
- Login from multiple devices/browsers
- Verify tokens from other sessions still work
- Verify new token works
- Test logout still revokes token correctly

**Rollback:** Restore `$user->tokens()->delete();` line

**Alternative:** If you want to limit concurrent tokens, use:
```php
// Limit to 5 most recent tokens
$user->tokens()->orderBy('created_at', 'desc')->skip(5)->delete();
```

---

#### Step 3.2: Update whoami Endpoint to Include Abilities
**Priority:** 🟢 MEDIUM  
**Risk:** Low (additive change)  
**Estimated Time:** 10 minutes  
**Standalone:** ✅ YES - Safe to implement independently

**Actions:**
1. Open `app/Http/Controllers/AuthController.php`
2. Find the `user()` method (around line 147)
3. Replace:
   ```php
   return response()->json(auth('sanctum')->user());
   ```
   With:
   ```php
   $user = auth('sanctum')->user();
   $token = $user->currentAccessToken();
   
   return response()->json([
       ...$user->toArray(),
       'abilities' => $token->abilities ?? []
   ]);
   ```

**Testing:**
- Call `GET /auth/whoami` with admin token → verify abilities array includes `admin:isAdmin`
- Call with non-admin token → verify abilities array is empty or doesn't include admin
- Verify frontend can parse the response

**Rollback:** Restore original return statement

**Note:** This enables frontend to conditionally render UI based on abilities.

---

### Phase 4: Cleanup and Optimization

✅ **Standalone Phase:** All steps are independent and safe to implement separately.

#### Step 4.1: Remove EnsureFrontendRequestsAreStateful (If Not Using SPA Cookies)
**Priority:** 🟢 LOW  
**Risk:** Low (only if not using SPA cookie auth)  
**Estimated Time:** 5 minutes  
**Standalone:** ✅ YES - Safe to implement independently

**Actions:**
1. Determine if you're using Sanctum's SPA cookie authentication
   - If frontend uses cookies for auth → KEEP
   - If frontend uses Bearer tokens only → REMOVE
2. If removing, open `app/Http/Kernel.php`
3. Remove line 16:
   ```php
   // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
   ```

**Testing:**
- Verify login still works
- Verify API calls still work
- Test from frontend

**Rollback:** Restore middleware line

---

#### Step 4.2: Create Missing Authenticate Middleware (If Needed)
**Priority:** 🟢 LOW  
**Risk:** Low  
**Estimated Time:** 10 minutes  
**Standalone:** ✅ YES - Safe to implement independently

**Actions:**
1. Check if `app/Http/Middleware/Authenticate.php` exists
2. If missing, create it:
   ```bash
   php artisan make:middleware Authenticate
   ```
3. Or remove reference from `Kernel.php` if not needed:
   ```php
   // 'auth' => \App\Http\Middleware\Authenticate::class,
   ```

**Testing:**
- Verify routes still work
- Check for any middleware errors

**Rollback:** Restore from git or remove file

---

#### Step 4.3: Standardize Ability Format
**Priority:** 🟢 LOW  
**Risk:** Low  
**Estimated Time:** 15 minutes  
**Standalone:** ✅ YES - Safe to implement independently (but should match ability strings used in routes)

**Actions:**
1. Review all ability strings in codebase
2. Standardize format (recommend: `admin:isAdmin` or just `admin`)
3. Update `AuthController.php` login method to use consistent format:
   ```php
   if ($request->user()->role == "isAdmin") {
       $tokenResult = $user->createToken('Personal Access Token', ['admin']);
   } else {
       $tokenResult = $user->createToken('Personal Access Token');
   }
   ```
4. Update route middleware to match:
   ```php
   Route::middleware(['auth:sanctum', 'abilities:admin'])->group(function() {
   ```

**Testing:**
- Verify admin tokens have correct ability
- Verify middleware checks work
- Test all admin routes

**Rollback:** Restore original ability strings

---

### Phase 5: Frontend Updates (After API Fixes)

✅ **Standalone Phase:** All steps are optional enhancements and safe to implement independently.

#### Step 5.1: Add Ability Checking to Auth Store
**Priority:** 🟢 LOW  
**Risk:** Low (additive)  
**Estimated Time:** 15 minutes  
**Standalone:** ✅ YES - Safe to implement independently (requires Step 3.2 to be useful)

**Actions:**
1. Open `pbx3spa/src/stores/auth.js`
2. Add getters for ability checking:
   ```javascript
   getters: {
     isLoggedIn(state) {
       return Boolean(state.token)
     },
     hasAbility(state) {
       return (ability) => {
         return state.user?.abilities?.includes(ability) ?? false
       }
     },
     isAdmin(state) {
       return state.user?.abilities?.includes('admin') ?? false
     }
   }
   ```

**Testing:**
- Login as admin → verify `auth.isAdmin` is true
- Login as non-admin → verify `auth.isAdmin` is false
- Test `auth.hasAbility('admin')` works

---

#### Step 5.2: Add Token Expiration Handling (When API Supports It)
**Priority:** 🟢 LOW  
**Risk:** Low  
**Estimated Time:** 30 minutes  
**Standalone:** ✅ YES - Safe to implement independently (requires Step 1.2 to be useful)

**Actions:**
1. Add expiration check utility to auth store
2. Check expiration before API calls
3. Handle expired tokens gracefully

**Note:** Only implement after API adds token expiration.

---

### Testing Checklist

After completing each phase, run these tests:

#### Authentication Tests
- [ ] Login with valid credentials → returns token
- [ ] Login with invalid credentials → returns 401
- [ ] Login as admin → token has admin ability
- [ ] Login as non-admin → token has no admin ability
- [ ] Logout → token is revoked
- [ ] whoami endpoint → returns user with abilities

#### Authorization Tests
- [ ] Admin routes accessible with admin token
- [ ] Admin routes return 403 with non-admin token
- [ ] Admin routes return 401 without token
- [ ] Non-admin routes accessible with any authenticated token
- [ ] Public routes (login) accessible without token

#### Token Management Tests
- [ ] Multiple tokens can exist for same user
- [ ] Token expiration is set correctly
- [ ] Expired tokens are rejected
- [ ] Token revocation works

#### Frontend Tests
- [ ] Login flow works
- [ ] Logout flow works
- [ ] 401 redirects to login
- [ ] Admin UI elements show/hide correctly
- [ ] Token persists in sessionStorage
- [ ] Token cleared on logout

---

### Rollback Plan

If issues occur:

1. **Immediate Rollback:** Restore from git commit before changes
2. **Partial Rollback:** Revert specific phase that caused issues
3. **Database Rollback:** If migration issues, rollback migrations
4. **Config Rollback:** Restore original config files

**Before Starting:** Create a git branch:
```bash
git checkout -b fix/sanctum-implementation
git commit -m "Backup before Sanctum fixes"
```

---

### Recommended Implementation Strategies

#### Strategy A: Incremental (Safest)
1. **Week 1:** Phase 1 (all steps) → Test → Deploy
2. **Week 2:** Phase 2 Steps 2.1, 2.2, 2.4 → Test → Deploy
3. **Week 3:** Phase 2 Step 2.3 → Test → Deploy
4. **Week 4:** Phase 3 → Test → Deploy
5. **Week 5:** Phase 4 → Test → Deploy

**Pros:** Low risk, can test each phase independently  
**Cons:** Takes longer, system has mixed old/new approaches temporarily

#### Strategy B: All-at-Once (Fastest)
1. Complete Phases 1-4 in one session
2. Test thoroughly
3. Deploy

**Pros:** Fast, consistent implementation  
**Cons:** Higher risk, requires longer testing window

#### Strategy C: Critical First (Balanced)
1. **Session 1:** Phase 1 (security fixes) → Test → Deploy
2. **Session 2:** Phase 2 (all steps together) → Test → Deploy
3. **Session 3:** Phases 3-4 → Test → Deploy

**Pros:** Balances speed and safety  
**Cons:** Medium risk on Phase 2

### Estimated Timeline

- **Phase 1 (Security Fixes):** 30 minutes ✅ Standalone
- **Phase 2 (Ability Checking):** 2 hours ⚠️ Must complete Steps 2.2-2.4 together
- **Phase 3 (Token Management):** 30 minutes ✅ Standalone
- **Phase 4 (Cleanup):** 30 minutes ✅ Standalone
- **Phase 5 (Frontend):** 1 hour (optional, after API fixes) ✅ Standalone
- **Testing:** 1-2 hours

**Total:** ~5-6 hours for API fixes, +1 hour for frontend enhancements

**Minimum Safe Implementation:** Phase 1 only (30 min) - System fully functional

---

### Success Criteria

✅ All critical security issues fixed  
✅ Manual ability checking replaced with Sanctum middleware  
✅ Token expiration configured  
✅ Token logging removed  
✅ All routes protected correctly  
✅ Frontend works with updated API  
✅ No breaking changes to existing functionality  

---

*Analysis Date: February 5, 2026*
*Sanctum Version: ^4.0*
*Laravel Version: ^11.31*
*Frontend: Vue.js 3 + Pinia + Vue Router*
