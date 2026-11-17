<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /generations/{generation}/image: the owner's image, from our stored copy when there is
 * one, else from Pixelforge while its URL is valid (spec FR-012, US3 scenario 2).
 */
class GenerationImageController extends Controller
{
    public function __invoke(Request $request, Generation $generation): RedirectResponse|StreamedResponse
    {
        // 404, not 403: other users' generations don't exist as far as anyone else can tell.
        abort_unless($generation->user_id === $request->user()?->id, 404);

        $disk = Storage::disk('images');

        if ($generation->image_path !== null && $disk->exists($generation->image_path)) {
            // Object storage: a short-lived signed URL. Local disk: stream the file.
            return $disk->providesTemporaryUrls()
                ? redirect()->away($disk->temporaryUrl($generation->image_path, now()->addMinutes(5)))
                : $disk->response($generation->image_path);
        }

        if ($generation->image_url !== null && $generation->image_url_expires_at?->isFuture()) {
            return redirect()->away($generation->image_url);
        }

        abort(404);
    }
}
