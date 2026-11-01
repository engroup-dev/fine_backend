<?php

use App\Exceptions\BackupException;
use App\Exceptions\HeaderAccountPostingException;
use App\Exceptions\InsufficientComponentStockException;
use App\Exceptions\InsufficientTankStockException;
use App\Exceptions\InvalidOperatingUnitException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\InventoryAccountNotLinkedException;
use App\Exceptions\LedgerRuleException;
use App\Exceptions\MissingAccountException;
use App\Exceptions\OptimisticLockConflictException;
use App\Exceptions\SalesRuleException;
use App\Exceptions\SerializedQuantityException;
use App\Exceptions\UnbalancedJournalException;
use App\Http\Middleware\EnforceDesktopAppVersion;
use App\Http\Middleware\EnsurePasswordIsUpdated;
use App\Http\Middleware\RequireModulePermission;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ScopeOperatingUnit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnforceDesktopAppVersion::class,
        ]);

        $middleware->alias([
            'scope.unit' => ScopeOperatingUnit::class,
            'ensure.password.updated' => EnsurePasswordIsUpdated::class,
            'require.role' => RequireRole::class,
            'require.permission' => RequirePermission::class,
            'require.module' => RequireModulePermission::class,
            'enforce.desktop.version' => EnforceDesktopAppVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (OptimisticLockConflictException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'OPTIMISTIC_LOCK_CONFLICT',
            ], 409);
        });

        $exceptions->render(function (SerializedQuantityException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'SERIALIZED_QUANTITY_INVALID',
            ], 422);
        });

        $exceptions->render(function (InsufficientComponentStockException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INSUFFICIENT_COMPONENT_STOCK',
            ], 422);
        });

        $exceptions->render(function (MissingAccountException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'MISSING_ACCOUNT',
            ], 422);
        });

        $exceptions->render(function (InventoryAccountNotLinkedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVENTORY_ACCOUNT_NOT_LINKED',
                'event' => $e->event->value,
                'event_label' => $e->event->arabicLabel(),
                'inventory_item_id' => $e->item->id,
            ], 422);
        });

        $exceptions->render(function (HeaderAccountPostingException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'HEADER_ACCOUNT_POSTING',
                'account_code' => $e->account->account_code,
            ], 422);
        });

        $exceptions->render(function (UnbalancedJournalException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'UNBALANCED_JOURNAL',
            ], 422);
        });

        $exceptions->render(function (InvalidOperatingUnitException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_OPERATING_UNIT',
            ], 400);
        });

        $exceptions->render(function (InsufficientTankStockException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INSUFFICIENT_TANK_STOCK',
            ], 422);
        });

        $exceptions->render(function (LedgerRuleException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                ...$e->context,
            ], 422);
        });

        $exceptions->render(function (SalesRuleException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                ...$e->context,
            ], 422);
        });

        $exceptions->render(function (BackupException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                ...$e->context,
            ], 422);
        });

        $exceptions->render(function (InvalidStateTransitionException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        });
    })->create();
