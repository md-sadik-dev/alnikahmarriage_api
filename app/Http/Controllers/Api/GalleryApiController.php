<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GalleryApiController extends Controller
{
    /**
     * Display a listing of gallery/success story images from database.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Gallery::where(function ($q) {
            $q->whereNull('status')->orWhere('status', 1);
        });

        // Filter by home visibility if requested (?home=1)
        if ($request->has('home')) {
            $query->where('home_visibility', $request->input('home'));
        }

        // Ordering (latest first by default)
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortOrder);

        // Fetch all or paginate
        $limit = $request->input('limit');
        if ($limit) {
            $items = $query->paginate((int) $limit);
            $items->getCollection()->transform(function ($item, $index) {
                return $this->formatGalleryItem($item, $index);
            });
            return response()->json([
                'success' => true,
                'message' => 'Gallery fetched successfully.',
                'data' => $items,
            ]);
        }

        $galleries = $query->get()->map(function ($item, $index) {
            return $this->formatGalleryItem($item, $index);
        });

        return response()->json([
            'success' => true,
            'count' => $galleries->count(),
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

        return response()->json([
            'success' => true,
            'data' => $this->formatGalleryItem($item, 0),
        ]);
    }

    /**
     * Format gallery item with full URLs and accessible metadata.
     *
     * @param Gallery $item
     * @param int $index
     * @return array
     */
    protected function formatGalleryItem($item, $index = 0): array
    {
        $imageName = $item->image;
        $imageUrl = '';

        // Check if image file exists in public directories
        if (!empty($imageName) && file_exists(public_path('uploads/' . $imageName))) {
            $imageUrl = url('uploads/' . $imageName);
        } elseif (!empty($imageName) && file_exists(public_path('images/' . $imageName))) {
            $imageUrl = url('images/' . $imageName);
        } else {
            // Fallback to webp success stories assets in public/images
            $fallbackIndex = ($index % 14) + 1;
            $webpName = 'success-stories-' . $fallbackIndex . '.webp';
            if (file_exists(public_path('images/' . $webpName))) {
                $imageUrl = url('images/' . $webpName);
            } else {
                $imageUrl = url('images/' . ($imageName ?: 'hero-1.webp'));
            }
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
