<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VideoApiController extends Controller
{
    /**
     * Display a listing of active video guides.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Video::query();

        if (!$request->has('all')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 1);
            });
        }

        $videos = $query->orderBy('id', 'asc')->get();

        $formatted = $videos->map(function ($video) {
            return $this->formatVideoItem($video);
        })->filter()->values();

        return response()->json([
            'success' => true,
            'count' => $formatted->count(),
            'message' => 'Video guides retrieved successfully.',
            'data' => $formatted,
        ]);
    }

    /**
     * Display the specified video guide by ID.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $video = Video::find($id);

        if (!$video) {
            return response()->json([
                'success' => false,
                'message' => 'Video guide not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatVideoItem($video),
        ]);
    }

    /**
     * Format a video item with YouTube ID, parsed button text lines, and URLs.
     *
     * @param Video $video
     * @return array
     */
    protected function formatVideoItem($video): array
    {
        $link = trim($video->video_link ?? '');
        $youtubeId = $this->extractYouTubeId($link);
        $title = trim($video->title ?? '');

        // Split title into two lines for button UI
        $line1 = $title;
        $line2 = '';

        if (str_contains($title, ' - ')) {
            $parts = explode(' - ', $title, 2);
            $line1 = trim($parts[0]);
            $line2 = trim($parts[1]);
        } elseif (preg_match('/\s{2,}/', $title)) {
            $parts = preg_split('/\s{2,}/', $title, 2);
            $line1 = trim($parts[0]);
            $line2 = trim($parts[1]);
        } elseif (preg_match('/^(.*?\bhow to\b(?:[^\w]+)?)(.*)$/i', $title, $m)) {
            $line1 = trim(rtrim($m[1], '- :'));
            $line2 = trim($m[2]);
        }

        return [
            'id' => (int) $video->id,
            'title' => $title,
            'line1' => $line1,
            'line2' => $line2,
            'video_link' => $link,
            'youtube_id' => $youtubeId,
            'embed_url' => $youtubeId ? "https://www.youtube-nocookie.com/embed/{$youtubeId}" : '',
            'status' => (int) $video->status,
            'updated_at' => $video->updated_at,
        ];
    }

    /**
     * Extract 11-character YouTube video ID from various URL formats.
     *
     * @param string $url
     * @return string|null
     */
    protected function extractYouTubeId(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $match)) {
            return $match[1];
        }

        // If direct 11-char ID is passed
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
            return $url;
        }

        return null;
    }
}
