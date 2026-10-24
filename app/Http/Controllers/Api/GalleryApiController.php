<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class GalleryApiController extends Controller
{
    /**
     * Display a listing of gallery/success story images from database.
     * Caches responses for high performance.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = 'gallery_' . md5($request->fullUrl());

        if ($request->has('refresh') || $request->has('bust')) {
            Cache::forget($cacheKey);
        }

        $query = Gallery::where(function ($q) {
            $q->whereNull('status')->orWhere('status', 1);
        });

        // Filter by home visibility if requested (?home=1)
        if ($request->has('home')) {
            $query->where('home_visibility', $request->input('home'));
        }

        // Ordering (latest first by default)
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Fetch all or paginate
        $limit = $request->input('limit');
        if ($limit) {
            $items = $query->paginate((int) $limit);
            $formatted = $items->getCollection()->map(function ($item, $index) {
                return $this->formatGalleryItem($item, $index);
            })->filter()->values();
            $items->setCollection($formatted);

            return response()->json([
                'success' => true,
                'message' => 'Gallery fetched successfully.',
                'data' => $items,
            ]);
        }

        $galleries = Cache::remember($cacheKey, 300, function () use ($query) {
            return $query->get()->map(function ($item, $index) {
                return $this->formatGalleryItem($item, $index);
            })->filter()->values();
        });

        return response()->json([
            'success' => true,
            'count' => is_countable($galleries) ? count($galleries) : $galleries->count(),
            'message' => 'Gallery photos and success stories retrieved successfully.',
            'data' => $galleries,
        ]);
    }


    /**
     * Display the specified gallery item.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Gallery::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Gallery photo not found.',
            ], 404);
        }

        $formatted = $this->formatGalleryItem($item, 0);
        if (!$formatted) {
            return response()->json([
                'success' => false,
                'message' => 'Gallery photo image not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Format gallery item with full URLs and accessible metadata.
     * Only returns item if the uploaded file exists. No hardcoded/static fallbacks.
     *
     * @param Gallery $item
     * @param int $index
     * @return array|null
     */
    protected function formatGalleryItem($item, $index = 0): ?array
    {
        $imageName = $item->image;
        $imageUrl = '';

        if (!empty($imageName) && file_exists(storage_path('app/public/gallery/' . $imageName))) {
            $isArtisan = (request()->getPort() == 8000);
            $imageUrl = $isArtisan
                ? asset('storage/gallery/' . rawurlencode($imageName))
                : url('alnikahmarriage_api/storage/app/public/gallery/' . rawurlencode($imageName));
        }

        // Agar gallery image nahi hoga toh kuch show nahi karna hai
        if (empty($imageUrl)) {
            return null;
        }

        // Clean title from image filename
        $title = 'Success Story #' . $item->id;
        if (!empty($imageName)) {
            $cleaned = preg_replace('/^\d+[\-_]?/', '', pathinfo($imageName, PATHINFO_FILENAME));
            $cleaned = str_replace(['-', '_', '&'], [' ', ' ', ' & '], $cleaned);
            $cleaned = ucwords(trim($cleaned));
            if (!empty($cleaned) && !is_numeric($cleaned)) {
                $title = $cleaned;
            }
        }

        return [
            'id' => $item->id,
            'title' => $title,
            'image' => $item->image,
            'image_url' => $imageUrl,
            'home_visibility' => (int) $item->home_visibility,
            'status' => (int) $item->status,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
