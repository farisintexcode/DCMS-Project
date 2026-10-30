<?php

namespace App\Actions\Account;

use App\Actions\Auth\ProfilePhoto;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** M01 / UI05: preserve the account action entry point using the shared avatar implementation. */
class SaveProfilePhoto extends ProfilePhoto
{
    public function handle(User $actor, UploadedFile $photo, float $photoZoom = 1, float $photoX = 50, float $photoY = 50): Document
    {
        return $this->save($actor, $photo, $photoZoom, $photoX, $photoY);
    }
}
