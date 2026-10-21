<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BannerApiController extends Controller
{
    /**
     * Pre-defined rich content templates for hero slides
     */
    protected $defaultHeroSlides = [
        [
            'id' => 1,
            'eyebrow' => 'Honoring Nikah & Family Traditions',
            'headline' => 'Find Your Perfect Life Partner',
            'subtext' => 'Meet someone who shares your values, traditions, and dreams for a beautiful life together.',
            'image' => 'hero-1.webp',
            'mobile_image' => 'hero-1-mobile.webp',
            'cta_primary' => [
                'text' => 'Find Your Match',
                'url' => '/bride-and-groom',
            ],
            'cta_secondary' => [
                'text' => 'Get a Free Quote',
                'url' => '/contact-us',
            ],
        ],
        [
            'id' => 2,
            'eyebrow' => 'Sacred Bonds & Mutual Devotion',
            'headline' => 'Begin Your Beautiful Nikah Journey',
            'subtext' => 'Find a meaningful connection built on shared values and mutual respect.',
            'image' => 'hero-2.webp',
            'mobile_image' => 'hero-2.webp',
            'cta_primary' => [
                'text' => 'Bride & Groom',
                'url' => '/bride-and-groom',
            ],
            'cta_secondary' => [
                'text' => 'Get a Free Quote',
                'url' => '/contact-us',
            ],
        ],
        [
            'id' => 3,
            'eyebrow' => 'Confidential & Verified Matchmaking',
            'headline' => 'Where Traditions Meet Lifelong Companionship',
            'subtext' => 'Trusted, confidential, and family-first matchmaking for Muslim families seeking meaningful and lifelong companionship.',
            'image' => 'hero-3.webp',
            'mobile_image' => 'hero-3.webp',
            'cta_primary' => [
                'text' => 'Our Services',
                'url' => '/services',
            ],
            'cta_secondary' => [
                'text' => 'Get a Free Quote',
                'url' => '/contact-us',
            ],
        ],
    ];

    /**
     * Display a listing of homepage banners/hero slides.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Check if database banners exist
        $query = Banner::query();

        // If status filter is not explicitly "all", return active or all available
        if (!$request->has('all')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 1);
            });
        }

        $dbBanners = $query->orderBy('id', 'asc')->get();

        // If database has active banners, format them
        if ($dbBanners->isNotEmpty()) {
            $formatted = $dbBanners->map(function ($banner, $index) {
                return $this->formatBannerItem($banner, $index);
            });

            return response()->json([
                'success' => true,
                'count' => $formatted->count(),
                'message' => 'Homepage banners retrieved successfully from database.',
                'data' => $formatted,
            ]);
        }

        // Fallback to the rich hero banner slider configuration
        $defaultFormatted = collect($this->defaultHeroSlides)->map(function ($slide) {
            return [
                'id' => $slide['id'],
                'title' => $slide['headline'],
                'eyebrow' => $slide['eyebrow'],
                'headline' => $slide['headline'],
                'subtext' => $slide['subtext'],
                'image' => $slide['image'],
                'image_url' => url('images/' . $slide['image']),
                'mobile_image_url' => url('images/' . $slide['mobile_image']),
                'image_alt' => $slide['headline'],
                'cta_primary' => [
                    'text' => $slide['cta_primary']['text'],
                    'url' => url($slide['cta_primary']['url']),
                ],
                'cta_secondary' => [
                    'text' => $slide['cta_secondary']['text'],
                    'url' => url($slide['cta_secondary']['url']),
                ],
                'status' => 1,
            ];
        });

        return response()->json([
            'success' => true,
            'count' => $defaultFormatted->count(),
            'message' => 'Homepage hero banners retrieved successfully.',
            'data' => $defaultFormatted,
        ]);
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
            // Check default slides
            foreach ($this->defaultHeroSlides as $slide) {
                if ($slide['id'] == $id) {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'id' => $slide['id'],
                            'title' => $slide['headline'],
                            'eyebrow' => $slide['eyebrow'],
                            'headline' => $slide['headline'],
                            'subtext' => $slide['subtext'],
                            'image' => $slide['image'],
                            'image_url' => url('images/' . $slide['image']),
                            'mobile_image_url' => url('images/' . $slide['mobile_image']),
                            'image_alt' => $slide['headline'],
                            'cta_primary' => [
                                'text' => $slide['cta_primary']['text'],
                                'url' => url($slide['cta_primary']['url']),
                            ],
                            'cta_secondary' => [
                                'text' => $slide['cta_secondary']['text'],
                                'url' => url($slide['cta_secondary']['url']),
                            ],
                            'status' => 1,
                        ],
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Banner not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBannerItem($banner, 0),
        ]);
    }

    /**
     * Format banner item with URLs and CTA configurations.
     *
     * @param Banner $banner
     * @param int $index
     * @return array
     */
    protected function formatBannerItem($banner, $index = 0): array
    {
        $imageName = $banner->image;
        $imageUrl = '';
        $mobileImageUrl = '';

        if (!empty($imageName) && file_exists(public_path('uploads/' . $imageName))) {
            $imageUrl = url('uploads/' . $imageName);
            $mobileImageUrl = url('uploads/' . $imageName);
        } elseif (!empty($imageName) && file_exists(public_path('images/' . $imageName))) {
            $imageUrl = url('images/' . $imageName);
            $mobileImageUrl = url('images/' . $imageName);
        } else {
            // Fallback to high quality hero images
            $heroImages = ['hero-1.webp', 'hero-2.webp', 'hero-3.webp'];
            $chosen = $heroImages[$index % count($heroImages)];
            $imageUrl = url('images/' . $chosen);
            $mobileImageUrl = ($chosen === 'hero-1.webp') ? url('images/hero-1-mobile.webp') : url('images/' . $chosen);
        }

        $template = $this->defaultHeroSlides[$index % count($this->defaultHeroSlides)];

        return [
            'id' => $banner->id,
            'title' => $banner->title ?: $template['headline'],
            'eyebrow' => $template['eyebrow'],
            'headline' => $banner->title ?: $template['headline'],
            'subtext' => $template['subtext'],
            'image' => $banner->image,
            'image_url' => $imageUrl,
            'mobile_image_url' => $mobileImageUrl,
            'image_alt' => $banner->image_alt ?: $banner->title,
            'image_title' => $banner->image_title ?: $banner->title,
            'slug' => $banner->slug,
            'cta_primary' => [
                'text' => $template['cta_primary']['text'],
                'url' => url($template['cta_primary']['url']),
            ],
            'cta_secondary' => [
                'text' => $template['cta_secondary']['text'],
                'url' => url($template['cta_secondary']['url']),
            ],
            'status' => (int) $banner->status,
            'created_at' => $banner->created_at,
            'updated_at' => $banner->updated_at,
        ];
    }
}
