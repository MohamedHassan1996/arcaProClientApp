<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Maintenance\MaintenanceType;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Anagraphic;
use App\Models\AnagraphicAddress;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;


class ProductMaintenanceHistoryController extends Controller implements HasMiddleware
{

    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
        ];
    }

    public function index(Request $request)
    {
        $barcode = $request->productBarcode;

        $auth = auth()->user();

        $clientProductBarcode = DB::table('anagraphic_product_codes')
        ->where('barcode', $barcode)
        ->where('anagraphic_guid', $auth?->anagraphic_guid)
        ->first();



        if (!$clientProductBarcode) {
            // Extract the clean part of the barcode
            $cleanBarcode = preg_replace('/^.*?-([0-9]+-[0-9]+)(?:\.0)?$/', '$1', $barcode);

            // Search using the cleaned barcode
            $clientProductBarcode = DB::table('anagraphic_product_codes')
                ->where('barcode', 'LIKE', '%' . $cleanBarcode . '%')
                ->where('anagraphic_guid', $auth?->anagraphic_guid)
                ->first();

            // If found, overwrite $barcode with the actual DB value
            if ($clientProductBarcode) {
                $barcode = $clientProductBarcode->barcode;
            }
        }


        if (!$clientProductBarcode) {
            return ApiResponse::error('Product not found');
        }

        $calendarEvent = CalendarEvent::where('product_barcode', $barcode)->first();


        $installation = CalendarEvent::where('product_barcode', $barcode)
            ->where('maintenance_type', MaintenanceType::INSTALLATION->value)
            ->orderByDesc('start_at')
            ->first();

        $history = CalendarEvent::where('product_barcode', $barcode)->where('maintenance_type', '!=', MaintenanceType::CONTROL->value)
            ->orderBy('start_at')
            ->get()
            ->map(fn($item) => [
                'maintenanceType' => $item->maintenance_type,
                'maintenanceDate' => Carbon::parse($item->start_at)->format('d/m/Y')
            ])
            ->toArray();

        $client = Anagraphic::where('guid', $clientProductBarcode->anagraphic_guid)->first();
        //$address = AnagraphicAddress::where('anagraphic_guid', $clientProductBarcode->anagraphic_guid)->first();

        return [
            'productBarcode' => $clientProductBarcode->barcode,
            'productCodice' => $clientProductBarcode->codice,
            'productDescription' => trim($clientProductBarcode->description),
            'clientName' => $client?->regione_sociale ?? '',
            'clientAddress' => trim("{$clientProductBarcode->address} {$clientProductBarcode->location}"),
            'note' => $clientProductBarcode->note,
            'installationDate' => $installation ? Carbon::parse($installation->start_at)->format('d/m/Y') : '',
            'productBarcodeHistory' => $history,
        ];


    }



}
