<?php


use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AstAmiController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CosCloseController;
use App\Http\Controllers\CosOpenController;
use App\Http\Controllers\ClassOfServiceController;
use App\Http\Controllers\CustomAppController;
use App\Http\Controllers\DayTimerController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\GreetingController;
use App\Http\Controllers\HolidayTimerController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\IvrController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\SnapShotController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SysCommandController;
use App\Http\Controllers\SysglobalController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TrunkController;


Route::group(['prefix' => 'auth'], function () {
/**
 *  Only login needs no privileges
 */
    Route::post('login', [AuthController::class, 'login']);
/**
 * logout and whoami are available to all logged in users
 */
    Route::group(['middleware' => 'auth:sanctum'], function() {
        Route::get('logout', [AuthController::class, 'logout']);
        Route::get('whoami', [AuthController::class, 'user']);
    });

    Route::group(['middleware' => ['auth:sanctum', 'validate.cluster']], function() {
/**
 * Stuff which has to be logged in but does not need admin privileges
 */
    Route::put('astamis/DBput/srktwin/{key}/{value}', [AstAmiController::class, 'dbput']);
    Route::delete('astamis/DBdel/srktwin/{key}', [AstAmiController::class, 'dbdel']);
});

/**
 *  Only admins can create,delete and view users
 */
    if (get_token_abilities()) {
        Route::post('register', [AuthController::class, 'register']);
        Route::get('users', [AuthController::class, 'index']);
        Route::get('users/mail/{email}', [AuthController::class, 'userByEmail']);
        Route::get('users/name/{name}', [AuthController::class, 'userByName']);
        Route::get('users/endpoint/{endpoint}', [AuthController::class, 'userByEndpoint']);
        Route::delete('users/revoke/{id}', [AuthController::class, 'revoke']);
        Route::get('users/{id}', [AuthController::class, 'userById']);
        Route::delete('users/{id}', [AuthController::class, 'delete']);
    }
});

Route::group(['middleware' => 'auth:sanctum'], function() {
/**
 * Everything in this group requires admin privileges.
 * Sanctum check-abilities does not seem to work and I am out
 * of time so I hacked a helper to check the
 * PAT table for admin priveleges.  Easily removed if I ever figure out how to get Sanctum to
 * do this for me.
 */
    if (get_token_abilities()) {
/**
 * Agents
 */
        Route::get('agents', [AgentController::class, 'index']);
        Route::get('agents/{agent}', [AgentController::class, 'show']);
        Route::post('agents', [AgentController::class, 'save']);
        Route::put('agents/{agent}', [AgentController::class, 'update']);
        Route::delete('agents/{agent}', [AgentController::class, 'delete']);

/**
 *  Asterisk AMI
 */
        Route::get('astamis', [AstAmiController::class, 'index']);
        Route::get('astamis/CoreSettings', [AstAmiController::class, 'coresettings']);
        Route::get('astamis/CoreStatus', [AstAmiController::class, 'corestatus']);

        Route::get('astamis/ExtensionState/{id}{context?}', [AstAmiController::class, 'extensionstate']);
        Route::get('astamis/MailboxCount/{id}', [AstAmiController::class, 'mailboxcount']);
        Route::get('astamis/MailboxStatus/{id}', [AstAmiController::class, 'mailboxstatus']);
        Route::get('astamis/QueueStatus/{id}', [AstAmiController::class, 'queuestatus']);
        Route::get('astamis/QueueSummary/{id}', [AstAmiController::class, 'queuesummary']);
        Route::get('astamis/Reload', [AstAmiController::class, 'reload']);
//        Route::get('astamis/SIPshowpeer/{id}', [AstAmiController::class, 'sipshowpeer']);
        Route::post('astamis/originate', [AstAmiController::class, 'originate']);
        Route::get('astamis/DBget/{id}/{key}', [AstAmiController::class, 'dbget']);
        Route::put('astamis/DBput/{id}/{key}/{value}', [AstAmiController::class, 'dbput']);
        Route::delete('astamis/DBdel/{id}/{key}', [AstAmiController::class, 'dbdel']);
        Route::delete('astamis/Hangup/{id}/{key}', [AstAmiController::class, 'hangup']);
        Route::get('astamis/{action}/{id?}', [AstAmiController::class, 'getlist']);

/**
 * Backups
 */
        Route::get('backups', [BackupController::class, 'index']);
        Route::get('backups/new', [BackupController::class, 'new']);
        Route::get('backups/{backup}', [BackupController::class, 'download']);
        Route::post('backups', [BackupController::class, 'save']);
        Route::put('backups/{backup}', [BackupController::class, 'update']);
        Route::delete('backups/{backup}', [BackupController::class, 'delete']);

/**
 * Closed CoS
 */
        Route::get('coscloses', [CosCloseController::class, 'index']);
        Route::get('coscloses/{cosclose}', [CosCloseController::class, 'show']);
        Route::post('coscloses', [CosCloseController::class, 'save']);
        Route::put('coscloses/{cosclose}', [CosCloseController::class, 'update']);
        Route::delete('coscloses/{cosclose}', [CosCloseController::class, 'delete']);

    /**
     * Open CoS
     */
        Route::get('cosopens', [CosOpenController::class, 'index']);
        Route::get('cosopens/{cosopen}', [CosOpenController::class, 'show']);
        Route::post('cosopens', [CosOPenController::class, 'save']);
        Route::put('cosopens/{cosopen}', [CosOpenController::class, 'update']);
        Route::delete('cosopens/{cosopen}', [CosOpenController::class, 'delete']);

    /**
     * Class of Service
     */
        Route::get('cosrules', [ClassOfServiceController::class, 'index']);
        Route::get('cosrules/{classofservice}', [ClassOfServiceController::class, 'show']);
        Route::post('cosrules', [ClassOfServiceController::class, 'save']);
        Route::put('cosrules/{classofservice}', [ClassOfServiceController::class, 'update']);
        Route::delete('cosrules/{classofservice}', [ClassOfServiceController::class, 'delete']);
    /**
     * Custom Apps
     */
        Route::get('customapps', [CustomAppController::class, 'index']);
        Route::get('customapps/{cosopen}', [CustomAppController::class, 'show']);
        Route::post('customapps', [CustomAppController::class, 'save']);
        Route::put('customapps/{cosopen}', [CustomAppController::class, 'update']);
        Route::delete('customapps/{cosopen}', [CustomAppController::class, 'delete']);

    /**
     * Day Timers
     */
        Route::get('daytimers', [DayTimerController::class, 'index']);
        Route::get('daytimers/{daytimer}', [DayTimerController::class, 'show']);
        Route::post('daytimers', [DayTimerController::class, 'save']);
        Route::put('daytimers/{daytimer}', [DayTimerController::class, 'update']);
        Route::delete('daytimers/{daytimer}', [DayTimerController::class, 'delete']);

    /**
     * Destinations
     */
        Route::get('destinations', [DestinationController::class, 'index']);

    /**
     * Extensions
     */
        Route::get('extensions', [ExtensionController::class, 'index']);
        Route::get('extensions/{extension}', [ExtensionController::class, 'show']);
        Route::get('extensions/{extension}/runtime', [ExtensionController::class, 'showruntime']);
        Route::post('extensions/mailbox', [ExtensionController::class, 'mailbox']);
        Route::post('extensions/provisioned', [ExtensionController::class, 'provisioned']);
        Route::post('extensions/vxt', [ExtensionController::class, 'vxt']);
        Route::post('extensions/unprovisioned', [ExtensionController::class, 'unprovisioned']);
        Route::post('extensions/webrtc', [ExtensionController::class, 'webrtc']);
        Route::put('extensions/{extension}', [ExtensionController::class, 'update']);
        Route::put('extensions/{extension}/runtime', [ExtensionController::class, 'updateruntime']);
        Route::delete('extensions/{extension}', [ExtensionController::class, 'delete']);

    /**
     * Firewall
     */
        Route::get('firewalls/ipv4', [FirewallController::class, 'ipv4']);
        Route::get('firewalls/ipv6', [FirewallController::class, 'ipv6']);
        Route::post('firewalls/ipv4', [FirewallController::class, 'ipv4save']);
        Route::post('firewalls/ipv6', [FirewallController::class, 'ipv6save']);
        Route::put('firewalls/ipv4', [FirewallController::class, 'ipv4restart']);
        Route::put('firewalls/ipv6', [FirewallController::class, 'ipv6restart']);

    /**
     * Greetings
     */
        Route::get('greetings', [GreetingController::class, 'index']);
        Route::get('greetings/{greeting}', [GreetingController::class, 'download']);
        Route::post('greetings', [GreetingController::class, 'save']);
        Route::delete('greetings/{greeting}', [GreetingController::class, 'delete']);

    /**
     * Holiday Timers
     */
        Route::get('holidaytimers', [HolidayTimerController::class, 'index']);
        Route::get('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'show']);
        Route::post('holidaytimers', [HolidayTimerController::class, 'save']);
        Route::put('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'update']);
        Route::delete('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'delete']);

    /**
     * Inbound Routes
     */
        Route::get('inboundroutes', [InboundRouteController::class, 'index']);
        Route::get('inboundroutes/{inboundroute}', [InboundRouteController::class, 'show']);
        Route::post('inboundroutes', [InboundRouteController::class, 'save']);
        Route::put('inboundroutes/{inboundroute}', [InboundRouteController::class, 'update']);
        Route::delete('inboundroutes/{inboundroute}', [InboundRouteController::class, 'delete']);

    /**
     * IVR menus
     */
        Route::get('ivrs', [IvrController::class, 'index']);
        Route::get('ivrs/{ivr}', [IvrController::class, 'show']);
        Route::post('ivrs', [IvrController::class, 'save']);
        Route::put('ivrs/{ivr}', [IvrController::class, 'update']);
        Route::delete('ivrs/{ivr}', [IvrController::class, 'delete']);

    /**
     * CDR Log
     */
        Route::get('logs', [LogController::class, 'index']);
        Route::get('logs/cdrs{limit}', [LogController::class, 'showcdr']);

    /**
     * Queues
     */
        Route::get('queues', [QueueController::class, 'index']);
        Route::get('queues/{queue}', [QueueController::class, 'show']);
        Route::post('queues', [QueueController::class, 'save']);
        Route::put('queues/{queue}', [QueueController::class, 'update']);
        Route::delete('queues/{queue}', [QueueController::class, 'delete']);

    /**
     * Snapshots
     */
        Route::get('snapshots', [SnapShotController::class, 'index']);
        Route::get('snapshots/new', [SnapShotController::class, 'new']);
        Route::get('snapshots/{snapshot}', [SnapShotController::class, 'download']);
        Route::post('snapshots', [SnapShotController::class, 'save']);
        Route::put('snapshots/{snapshot}', [SnapShotController::class, 'update']);
        Route::delete('snapshots/{snapshot}', [SnapShotController::class, 'delete']);

    /**
     * Routes
     */
        Route::get('routes', [RouteController::class, 'index']);
        Route::get('routes/{route}', [RouteController::class, 'show']);
        Route::post('routes', [RouteController::class, 'save']);
        Route::put('routes/{route}', [RouteController::class, 'update']);
        Route::delete('routes/{route}', [RouteController::class, 'delete']);

    /**
     * System Commands
     */
        Route::get('syscommands', [SysCommandController::class, 'index']);
        Route::get('syscommands/commit', [SysCommandController::class, 'commit']);
        Route::get('syscommands/reboot', [SysCommandController::class, 'reboot']);
        Route::get('syscommands/pbxrunstate', [SysCommandController::class, 'pbxrunstate']);
        Route::get('syscommands/start', [SysCommandController::class, 'start']);
        Route::get('syscommands/stop', [SysCommandController::class, 'stop']);

    /**
     * System Globals
     */
        Route::get('sysglobals', [SysglobalController::class, 'index']);
        Route::put('sysglobals', [SysglobalController::class, 'update']);

    /**
     *  Tenants
     */
        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::post('tenants', [TenantController::class, 'save']);
        Route::put('tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('tenants/{tenant}', [TenantController::class, 'delete']);

    /**
     * Trunks
     */
        Route::get('trunks', [TrunkController::class, 'index']);
        Route::get('trunks/{trunk}', [TrunkController::class, 'show']);
        Route::post('trunks', [TrunkController::class, 'save']);
        Route::put('trunks/{trunk}', [TrunkController::class, 'update']);
        Route::delete('trunks/{trunk}', [TrunkController::class, 'delete']);
    }

});

Route::fallback(function(){
    return response()->json([
        'message' => 'Unauthorised/Page Not Found'], 404);
});