<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ProfilePhoto;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfilePhotoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ProfilePhoto $photos): Response
    {
        abort_unless($request->user()->canManageOwnProfilePhoto(), 403);
        $document = $request->user()->profilePhoto;
        abort_unless($document, 404);
        try {
            $bytes = $photos->bytes($document);
        } catch (\Throwable) {
            abort(503, 'Your profile picture is temporarily unavailable.');
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'Content-Disposition' => 'inline; filename="profile.jpg"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
