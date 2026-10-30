<?php

namespace App\Actions\Auth;

use App\Models\Document;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Imports\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** M01 / UI05 / Database.md users/documents: own scanned, private avatar versions. */
class ProfilePhoto
{
    public function __construct(private MalwareScanner $scanner, private AuditWriter $audit) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'], 'photoZoom' => ['required', 'numeric', 'min:1', 'max:3'], 'photoX' => ['required', 'numeric', 'min:0', 'max:100'], 'photoY' => ['required', 'numeric', 'min:0', 'max:100']];
    }

    public function save(User $actor, UploadedFile $photo, float $photoZoom = 1, float $photoX = 50, float $photoY = 50): Document
    {
        abort_unless($actor->fresh()?->canManageOwnProfilePhoto(), 403);
        Validator::make(compact('photo', 'photoZoom', 'photoX', 'photoY'), self::rules())->validate();
        $bucket = config('filesystems.disks.s3.bucket');
        if (! $bucket) {
            throw ValidationException::withMessages(['photo' => 'Photo uploads are temporarily unavailable. Please contact your administrator.']);
        }
        try {
            $this->scanner->scan($photo->getRealPath());
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['photo' => $error->getMessage() === 'malware_detected' ? 'This photo failed its security check. Choose another image.' : 'The photo security check is unavailable. Try again later.']);
        }
        $bytes = $this->raster($photo, $photoZoom, $photoX, $photoY);
        $object = 'avatars/'.Str::uuid7().'.encrypted';
        try {
            if (! Storage::disk('s3')->put($object, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
                throw new RuntimeException('avatar_storage_failed');
            }
            $document = DB::transaction(function () use ($actor, $bucket, $object, $bytes): Document {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($user->canManageOwnProfilePhoto(), 403);
                $document = new Document;
                $document->forceFill(['owner_type' => 'user', 'owner_id' => $user->id, 'uploaded_by' => $user->id, 'disk' => 's3', 'bucket' => $bucket, 'object_key' => $object, 'original_filename' => 'profile.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'document_type_code' => 'avatar', 'scan_status' => 'clean'])->save();
                $this->audit->record($user, 'profile_photo_updated', $document->public_id, ['changed_fields' => ['profile_photo']], module: 'M01', entityType: 'document');

                return $document;
            }, 3);
        } catch (\Throwable $error) {
            try {
                Storage::disk('s3')->delete($object);
            } catch (\Throwable) {
            }
            if ($error instanceof RuntimeException) {
                throw ValidationException::withMessages(['photo' => 'Your photo could not be saved. Please try again later.']);
            }
            throw $error;
        }
        $actor->unsetRelation('profilePhoto');

        return $document;
    }

    public function remove(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->canManageOwnProfilePhoto(), 403);
            $removed = Document::query()->where('owner_type', 'user')->where('owner_id', $user->id)->where('document_type_code', 'avatar')->delete();
            if ($removed > 0) {
                $this->audit->record($user, 'profile_photo_removed', metadata: ['changed_fields' => ['profile_photo']]);
            }
        }, 3);
        $actor->unsetRelation('profilePhoto');
    }

    public function bytes(Document $document): string
    {
        if ($document->owner_type !== 'user' || $document->document_type_code !== 'avatar' || $document->scan_status !== 'clean' || $document->disk !== 's3' || $document->bucket !== config('filesystems.disks.s3.bucket') || $document->size_bytes > 2097152) {
            throw new RuntimeException('avatar_storage_invalid');
        }
        $encrypted = Storage::disk('s3')->get($document->object_key);
        if (! is_string($encrypted) || strlen($encrypted) > 4202496) {
            throw new RuntimeException('avatar_unavailable');
        }
        $bytes = Crypt::decryptString($encrypted);
        if (strlen($bytes) !== (int) $document->size_bytes || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('avatar_integrity_failed');
        }

        return $bytes;
    }

    private function raster(UploadedFile $photo, float $zoom, float $positionX, float $positionY): string
    {
        $info = @getimagesize($photo->getRealPath());
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if (! $info || ($limit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 16 + $photo->getSize() * 4 > $limit * .8)) {
            throw ValidationException::withMessages(['photo' => 'This image is too large to process safely. Resize it and try again.']);
        }
        $source = @imagecreatefromstring(file_get_contents($photo->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'Choose a valid JPEG, PNG or WebP image.']);
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($photo->getRealPath(), null, true);
            $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            if ($orientation === 4) {
                imageflip($source, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                imagedestroy($source);
                if (! $rotated) {
                    throw ValidationException::withMessages(['photo' => 'This photo could not be processed.']);
                }
                $source = $rotated;
            }
        }
        $canvas = imagecreatetruecolor(512, 512);
        try {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            $side = max(1, (int) floor(min(imagesx($source), imagesy($source)) / $zoom));
            imagecopyresampled($canvas, $source, 0, 0, (int) round((imagesx($source) - $side) * $positionX / 100), (int) round((imagesy($source) - $side) * $positionY / 100), 512, 512, $side, $side);
            ob_start();
            imagejpeg($canvas, null, 85);
            $bytes = ob_get_clean();
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 2097152) {
                throw ValidationException::withMessages(['photo' => 'This photo could not be processed. Choose another image.']);
            }

            return $bytes;
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }
}
