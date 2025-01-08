<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Validator;

class AuthController extends Controller
{
    /**
    * Create user
    *
    * @param  [string] name
    * @param  [string] email
    * @param  [string] password
    * @param  [string] password_confirmation
    * @return [string] message
    */
    public function register(Request $request) {

        $request->validate([
            'name' => 'required|string',
            'email'=>'required|email|unique:users',
            'endpoint'=>'numeric',
        /**
         * N.B. 'email' => 'email:rfc,dns'
         *  see - https://laravel.com/docs/9.x/validation#rule-email
         */
            'password'=>'required|string'
        ]);
        
        $user = new User([
            'name'  => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => $request->role,
            'endpoint' => $request->endpoint,
        ]);

        if($user->save()){
            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->plainTextToken;

            return response()->json([
            'message' => 'Created new user ' . $request->email . "!",
            'accessToken'=> $token,
            ],201);
        }
        else{
            return response()->json(['error'=>'Incorrect details']);
        }
    }

    /**
     * Login user and create token
    *
    * @param  [string] email
    * @param  [string] password
    * @param  [boolean] remember_me
    */

    public function login(Request $request) {

        $request->validate([
        'email' => 'required|string|email',
        'password' => 'required|string',
        'remember_me' => 'boolean'
        ]);

    

        $credentials = request(['email','password']);
        if(!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Unauthorized'
            ],401);
        }

        $user = $request->user();
        $user->tokens()->delete();

        if ($request->user()->role == "isAdmin") {
            Log::info("login " . $request->user()->name . "as Admin");
            $tokenResult = $user->createToken('Personal Access Token',['admin:isAdmin']); 
        }
        else {
            Log::info("login " . $request->user()->name);
            $tokenResult = $user->createToken('Personal Access Token');
        }
        
        $token = $tokenResult->plainTextToken;

        return response()->json([
        'accessToken' =>$token,
        'token_type' => 'Bearer',
        ]);
    }

    /* 
     * @return Index of user(s)
     */
    public function index () {
   
        return user::orderBy('id','asc')->get();
    }

    /* 
     * @return user by id
     */
    public function userbyId ($id) {

        return user::where('id',$id)->get();
    }    

    /* 
     * @return user by email
     */
    public function userByEmail ($email) {

        return user::where('email',$email)->get();
    }
    
    /* 
     * @return user by name
     */
    public function userByName ($name) {

             return user::where('name',$name)->get();
    }   

    /* 
     * @return user by email
     */
    public function userByEndpoint ($endpoint) {

             return user::where('endpoint',$endpoint)->get();
         }   

    /**
     * Get the authenticated User
     *
     * @return [json] user object
     */
    public function user(Request $request) {

        return response()->json(auth('sanctum')->user());
    }

    /**
     * Logout user (Revoke the token)
     *
     * @return [string] message
     */
    public function logout(Request $request) {

        $request->user()->tokens()->delete();

        return response()->json([
        'message' => 'Successfully logged out'
        ]);

    }

    /**
     * delete user by id (and Revoke the token)
     *
     * @return [string] message
     */
    public function delete($id) {
        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'message' => "User $id not found"],404);  
        }
    /**
     * Delete any tokens
     */
        $user->tokens()->delete();
    /**
     * Delete the user
     */
        $user->delete();

        return response()->json([
            'message' => "Successfully deleted user $id"
        ]);

    } 
    
    /**
     * revoke user token by id
     *
     * @return [string] message
     */
    public function revoke($id) {

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'message' => "User $id not found"],404);  
        }
    /**
     * Delete any tokens
     */
        $user->tokens()->delete();

        return response()->json([
            'message' => "Successfully deleted tokens for user $id"
        ]);

    }    
}