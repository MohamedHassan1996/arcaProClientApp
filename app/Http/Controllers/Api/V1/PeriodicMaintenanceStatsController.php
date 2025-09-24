<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Maintenance\MaintenanceType;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PeriodicMaintenanceStatsController extends Controller implements HasMiddleware
{

    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
        ];
    }

    public function index(Request $request)
    {
        $auth = auth()->user();
        $now = now(config('app.timezone'));
        $inThirtyDays = $now->copy()->addMonth();


        $totalCount = CalendarEvent::whereIn('maintenance_type', [
            MaintenanceType::MAINTANANCE->value,
            MaintenanceType::CONTROL->value,
        ])->where('client_guid', $auth->anagraphic_guid)->where('maintenance_type', MaintenanceType::MAINTANANCE->value)->count();

        $expiredMaintenanceCount = CalendarEvent::where('maintenance_type', MaintenanceType::MAINTANANCE->value)->where('is_done', 0)->where('start_at', '<', $now)->where('client_guid', $auth->anagraphic_guid)->count();

        //$expiredControlCount = CalendarEvent::where('maintenance_type', MaintenanceType::CONTROL->value)->where('is_done', 0)->where('start_at', '<', $now)->where('client_guid', $auth->anagraphic_guid)->count();
        $upcomingMaintenanceCount = CalendarEvent::where('maintenance_type', MaintenanceType::MAINTANANCE->value)
            ->where('is_done', 0)
            ->whereBetween('start_at', [$now, $inThirtyDays])
            ->count();


        /*$upcomingControlCount = CalendarEvent::select('product_barcode')
            ->where('maintenance_type', MaintenanceType::CONTROL->value)
            ->where('is_done', 0)
            ->whereBetween('start_at', [$now, $inThirtyDays])
            ->count();*/

        return ApiResponse::success([
            'totalMaintenanceCount' => $totalCount,
            'expiredMaintenanceCount' => $expiredMaintenanceCount,
            //'expiredControlCount' => $expiredControlCount,
            'upcomingMaintenanceCount' => $upcomingMaintenanceCount,
            //'upcomingControlCount' => $upcomingControlCount,
        ]);
    }
}
