<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Maintenance\MaintenanceType;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\PeriodicMaintenance\AllPeriodicMaintenanceCollection;
use App\Models\Anagraphic;
use App\Models\AnagraphicAddress;
use App\Models\CalendarEvent;
use App\Utils\PaginateCollection;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;


class PeriodicMaintenanceController extends Controller implements HasMiddleware
{

    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
        ];
    }

    public function index(Request $request)
    {
        $filters = $request->filter ?? [];

        $now = now();
        $nextMonth = $now->copy()->addMonth();

        $maintenanceTypeFilter = $filters['maintenanceType'] ?? null;
        $endedFilter = $filters['endedMaintenance'] ?? null;
        $clientGuid = auth()->user() ?->anagraphic_guid ?? null;

        $getDataFrom = isset($filters['dataFrom']) ? Carbon::parse($filters['dataFrom'])->startOfDay() : null;

        $startAtFilter = isset($filters['startAt']) ? Carbon::parse($filters['startAt'])->startOfDay() : null;
        $endAtFilter = isset($filters['endAt']) ? Carbon::parse($filters['endAt'])->endOfDay() : null;
        $aganteCode = isset($filters['agenteCode']) ? $filters['agenteCode'] : null;

        $aganteProductCode = [];

        if($aganteCode) {
            $aganteProductCode = DB::table('anagraphic_product_codes')->where('codice_agente', $aganteCode)->pluck('barcode')->toArray();
        }

        // Step 1: Filtered events
        $events = CalendarEvent::query()
            ->whereNot('maintenance_type', MaintenanceType::INSTALLATION->value)
            ->whereNot('maintenance_type', MaintenanceType::CONTROL->value)
            ->when($clientGuid, fn($q) => $q->where('client_guid', $clientGuid))
            ->when($maintenanceTypeFilter, fn($q) => $q->where('maintenance_type', $maintenanceTypeFilter))
            ->when($startAtFilter && $endAtFilter, fn($q) => $q->whereBetween('start_at', [$startAtFilter, $endAtFilter]))
            ->when($startAtFilter && !$endAtFilter, fn($q) => $q->where('start_at', '>=', $startAtFilter))
            ->when(!$startAtFilter && $endAtFilter, fn($q) => $q->where('start_at', '<=', $endAtFilter))
            ->when(!is_null($endedFilter), function ($q) use ($now, $nextMonth, $endedFilter) {
                if ($endedFilter == 1) {
                    $q->where('start_at', '<', $now)->where('is_done', 0);
                } elseif ($endedFilter == 0) {
                    $q->whereBetween('start_at', [$now, $nextMonth]);
                }
            })
            ->when($getDataFrom, fn($q) => $q->where('created_at', '>=', $getDataFrom))
            ->when($aganteCode, fn($q) => $q->whereIn('product_barcode', $aganteProductCode))
            ->orderBy('start_at')
            ->where('is_done', 0)
            ->get();

            $formattedData = collect($events);

        if ($events->isEmpty()) {
                return ApiResponse::success(
                new AllPeriodicMaintenanceCollection(
                    PaginateCollection::paginate($formattedData, $request->pageSize ?? 100000)
                )
            );
        }



        // Step 2: Preload all necessary related data
        $barcodes = $events->pluck('product_barcode')->filter()->unique();
        $clientGuids = $events->pluck('client_guid')->filter()->unique();



        $installations = CalendarEvent::query()
            ->where('maintenance_type', MaintenanceType::INSTALLATION->value)
            ->whereIn('product_barcode', $barcodes)
            ->latest('start_at')
            ->get()
            ->keyBy('product_barcode');

        $histories = CalendarEvent::query()
            ->whereIn('product_barcode', $barcodes)
            ->whereNot('maintenance_type', MaintenanceType::CONTROL->value)
            ->orderBy('start_at')
            ->get()
            ->groupBy('product_barcode');

        $clients = Anagraphic::whereIn('guid', $clientGuids)->get()->keyBy('guid');
        //$addresses = AnagraphicAddress::whereIn('anagraphic_guid', $clientGuids)->get()->keyBy('anagraphic_guid');
        $codiceAgentes = DB::table('anagraphic_product_codes')->select('codice_agente', 'barcode', 'address', 'location', 'note')->whereIn('barcode', $barcodes)->get()->keyBy('barcode', 'address', 'location');

        // Step 3: Format data
        $formattedData = $events->map(function ($event) use ($now, $nextMonth, $clients, $installations, $histories, $codiceAgentes) {
            $barcode = $event->product_barcode;
            $startDate = Carbon::parse($event->start_at);

            $statusColor = match (true) {
                $startDate->lt($now) => 0,
                $startDate->between($now, $nextMonth) => 1,
                default => 2,
            };

            $client = $clients->get($event->client_guid ?: null);
            //$address = $addresses->get($event->client_guid ?: null);
            $installation = $installations->get($barcode);
            $history = $histories->get($barcode)?->map(fn($item) => [
                'maintenanceType' => $item->maintenance_type,
                'maintenanceDate' => Carbon::parse($item->start_at)->format('d/m/Y'),
            ])->values()->toArray() ?? [];

            $address = $codiceAgentes->get($barcode)?->address . " " . $codiceAgentes->get($barcode)?->location;
            $note = $codiceAgentes->get($barcode)?->note ?? '';
            return [
                'maintenanceType' => $event->maintenance_type,
                'productBarcode' => $barcode,
                'productCode' => $barcode,
                'agenteCode' => $codiceAgentes->get($barcode)?->codice_agente ?? '',
                'productDescription' => trim($event->description) . ' - ' . $barcode,
                'maintenanceDate' => $startDate->format('d/m/Y'),
                'clientName' => $client?->regione_sociale ?? '',
                'clientGuid' => $client?->guid ?? '',
                'clientAddress' => trim($address),
                'note' => $note,
                'statusColor' => $statusColor,
                'installationDate' => $installation
                    ? Carbon::parse($installation->start_at)->format('d/m/Y')
                    : '',
                'maintenanceHistory' => $history,
            ];
        });

        // Step 4: Paginate and return
        return ApiResponse::success(
            new AllPeriodicMaintenanceCollection(
                PaginateCollection::paginate($formattedData, $request->pageSize ?? 100000)
            )
        );
    }

}
