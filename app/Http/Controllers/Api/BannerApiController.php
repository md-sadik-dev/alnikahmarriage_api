<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BannerApiController extends Controller
{
    /**
     * Cache key for active homepage banners.
     */
    const CACHE_KEY = 'active_homepage_banners';

    /**
     * Display a listing of homepage banners/hero slides from database.
     * Caches active banners for high performance.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Allow forced refresh via ?refresh=1
        if ($request->has('refresh') || $request->has('bust')) {
            Cache::forget(self::CACHE_KEY);
        }

        $query = Banner::query();

        // If status filter is not explicitly "all", return active only
        if (!$request->has('all')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 1);
            });

            $formatted = Cache::remember(self::CACHE_KEY, 300, function () use ($query) {
                $dbBanners = $query->orderBy('id', 'asc')->get();
                return $dbBanners->map(function ($banner, $index) {
                    return $this->formatBannerItem($banner, $index);
                })->filter()->values();
            });
        } else {
            $dbBanners = $query->orderBy('id', 'asc')->get();
            $formatted = $dbBanners->map(function ($banner, $index) {
                return $this->formatBannerItem($banner, $index);
            })->filter()->values();
        }

        return response()->json([
            'success' => true,
            'count' => is_countable($formatted) ? count($formatted) : $formatted->count(),
            'message' => 'Homepage banners retrieved successfully from database.',
            'data' => $formatted,
        ]);
    }

    /**
     * Clear cached banner data.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }


    /**
     * Display the specified banner by ID.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found.',
            ], 404);
        }

        $formatted = $this->formatBannerItem($banner, 0);
        if (!$formatted) {
            return response()->json([
                'success' => false,
                'message' => 'Banner image not found on server.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Format banner item with URLs and CTA configurations.
     * Only checks public/uploads/banner/. If no image file exists, returns null.
     *
     * @param Banner $banner
     * @param int $index
     * @return array|null
     */
    protected function formatBannerItem($banner, $index = 0): ?array
    {
        $imageName = $banner->image;
        $imageUrl = '';
        $mobileImageUrl = '';

        if (!empty($imageName) && file_exists(storage_path('app/public/banner/' . $imageName))) {
            $isArtisan = (request()->getPort() == 8000);
            $imageUrl = $isArtisan
                ? asset('storage/banner/' . rawurlencode($imageName))
                : url('alnikahmarriage_api/storage/app/public/banner/' . rawurlencode($imageName));
            $mobileImageUrl = $imageUrl;
        }

        // Agar banner image nahi hoga toh kuch show nahi karna hai
        if (empty($imageUrl)) {
            return null;
        }

        $eyebrow = !empty(trim($banner->eyebrow ?? '')) ? trim($banner->eyebrow) : '';
        $headline = !empty(trim($banner->title ?? '')) ? trim($banner->title) : '';
        $subtext = !empty(trim($banner->description ?? '')) ? trim($banner->description) : '';
        $btnText = !empty(trim($banner->btn_text ?? '')) ? trim($banner->btn_text) : '';
        $btnUrl = !empty(trim($banner->slug ?? '')) ? trim($banner->slug) : '';
        $fallbackLabel = !empty($headline) ? $headline : (!empty($eyebrow) ? $eyebrow : 'Al-Nikah Matrimonial Banner');

        return [
            'id' => $banner->id,
            'title' => $headline,
            'eyebrow' => $eyebrow,
            'headline' => $headline,
            'subtext' => $subtext,
            'image' => $banner->image,
            'image_url' => $imageUrl,
            'mobile_image_url' => $mobileImageUrl,
            'image_alt' => !empty($banner->image_alt) ? $banner->image_alt : $fallbackLabel,
            'image_title' => !empty($banner->image_title) ? $banner->image_title : $fallbackLabel,
            'slug' => $btnUrl,
            'cta_primary' => !empty($btnText) ? [
                'text' => $btnText,
                'url' => $btnUrl ?: 'bride-and-groom.html',
            ] : null,
            'cta_secondary' => !empty($btnText) ? [
                'text' => 'Get a Free Quote',
                'url' => 'contact-us.html',
            ] : null,
            'status' => (int) $banner->status,
            'created_at' => $banner->created_at,
            'updated_at' => $banner->updated_at,
        ];
    }
}
