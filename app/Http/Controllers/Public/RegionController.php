<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Regions\RegionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The address dropdowns of built forms load one level at a time from here: GET /wilayah for the provinces,
 * /wilayah/32 for the cities of a province, and so on. Small, cached, and the same for everyone.
 */
class RegionController extends Controller
{
    public function children(Request $request, ?string $code = null): JsonResponse
    {
        $etag = '"r'.md5(RegionDirectory::version().'|'.$code).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag(trim($etag, '"'))->setPublic()->setMaxAge(86400);
        }

        return response()->json(['items' => RegionDirectory::children($code)])
            ->setEtag(trim($etag, '"'))->setPublic()->setMaxAge(86400);
    }
}
