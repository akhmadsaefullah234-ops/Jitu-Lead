<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Regions\RegionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The address dropdowns of built forms load one level at a time from here: GET /wilayah for the provinces,
 * /wilayah/32 for the cities of a province, and so on; /wilayah/semua-kota for every city at once. Small, cached, and the same for everyone.
 */
class RegionController extends Controller
{
    public function children(Request $request, ?string $code = null): JsonResponse
    {
        return $this->cached($request, 'r'.md5(RegionDirectory::version().'|'.$code), fn () => RegionDirectory::children($code));
    }

    /** GET /wilayah/semua-kota: every city and regency with its province, for dropdowns that start at the city. */
    public function cities(Request $request): JsonResponse
    {
        return $this->cached($request, 'c'.md5(RegionDirectory::version()), fn () => RegionDirectory::allCities());
    }

    private function cached(Request $request, string $etag, callable $items): JsonResponse
    {
        if ($request->headers->get('If-None-Match') === '"'.$etag.'"') {
            return response()->json(null, 304)->setEtag($etag)->setPublic()->setMaxAge(86400);
        }

        return response()->json(['items' => $items()])->setEtag($etag)->setPublic()->setMaxAge(86400);
    }
}
