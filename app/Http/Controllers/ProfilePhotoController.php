<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProfilePhotoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canManageOwnProfilePhoto(), 403);
        $document = $user->profilePhoto;
        abort_unless($document, 404);
        try {
            if ($document->disk !== 's3' || $document->bucket !== config('filesystems.disks.s3.bucket') || $document->size_bytes > 2097152) {
                throw new \RuntimeException('avatar_storage_invalid');
            }
            $encrypted = Storage::disk('s3')->get($document->object_key);
            if (! is_string($encrypted) || strlen($encrypted) > 4202496) {
                throw new \RuntimeException('avatar_unavailable');
            }
            $bytes = Crypt::decryptString($encrypted);
            if (strlen($bytes) !== (int) $document->size_bytes || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
                throw new \RuntimeException('avatar_integrity_failed');
            }
        } catch (\Throwable) {
            abort(503, 'Your profile picture is temporarily unavailable.');
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'Content-Disposition' => 'inline; filename="profile.jpg"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
