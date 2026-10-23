<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MetaApiController extends Controller
{
    /**
     * Retrieve active sect/religion and caste options from verified existing tables.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function options(Request $request): JsonResponse
    {
        $religions = DB::table('religions')
            ->where('is_active', 1)
            ->orderBy('religion_name', 'asc')
            ->select('religion_id as id', 'religion_name as name')
            ->get();

        $castes = DB::table('caste')
            ->where('is_active', 1)
            ->orderBy('caste_name', 'asc')
            ->select('caste_id as id', 'caste_name as name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Metadata options retrieved successfully.',
            'data' => [
                'religions' => $religions,
                'maslaqs' => $religions,
                'sects' => $religions,
                'castes' => $castes,
            ],
        ], 200);
    }
}
