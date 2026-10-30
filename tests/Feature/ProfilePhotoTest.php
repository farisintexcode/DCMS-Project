<?php

use App\Actions\Account\SaveProfilePhoto;
use App\Actions\Auth\ProfilePhoto;
use App\Enums\AccountStatus;
use App\Livewire\Account\Settings;
use App\Models\Document;
use App\Models\Staff;
use App\Models\User;
use App\Services\Imports\MalwareScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.disks.s3.bucket' => 'private-photo-tests']);
    Storage::fake('s3');
});

test('both portals save a private cropped photo and display it consistently', function (string $portal): void {
    $user = $portal === 'Rider' ? User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create() : User::factory()->create();
    $user->assignRole($portal);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $this->actingAs($user);
    Livewire::test(Settings::class)->set('photo', UploadedFile::fake()->image('photo.png', 800, 600))->set('photoZoom', 2)->call('savePhoto')->assertHasNoErrors()->assertRedirect(route('account.settings'));
    $document = $user->fresh()->profilePhoto;
    expect($document->owner_id)->toBe($user->id);
    expect($document->scan_status)->toBe('clean');
    $bytes = app(ProfilePhoto::class)->bytes($document);
    expect(getimagesizefromstring($bytes)[0])->toBe(512);
    expect(getimagesizefromstring($bytes)[1])->toBe(512);
    expect(Storage::disk('s3')->get($document->object_key))->not->toBe($bytes);
    $this->get(route('account.profile-photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($bytes);
    $this->get(route('account.settings'))->assertOk()->assertSee('Your profile picture')->assertSee('Change password');
    $this->get(route($portal === 'Admin' ? 'admin.home' : 'rider.home'))->assertOk()->assertSee('Your profile picture');
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile_photo_updated']);
})->with(['Admin', 'Rider']);

test('replacement and removal cannot resurrect old pictures or change another account', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $other = User::factory()->create();
    $other->assignRole('Admin');
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->times(3)->andReturnNull();
    $action = app(ProfilePhoto::class);
    $first = $action->save($user, UploadedFile::fake()->image('first.jpg'));
    $second = $action->save($user, UploadedFile::fake()->image('next.webp'));
    $foreign = $action->save($other, UploadedFile::fake()->image('other.png'));
    expect($user->fresh()->profilePhoto->id)->toBe($second->id);
    $this->actingAs($user)->get(route('account.profile-photo', ['v' => $foreign->public_id]))->assertContent($action->bytes($second));
    Livewire::actingAs($user)->test(Settings::class)->call('removePhoto')->assertRedirect(route('account.settings'));
    expect($user->fresh()->profilePhoto)->toBeNull();
    expect($first->fresh()->trashed())->toBeTrue();
    expect($other->fresh()->profilePhoto->id)->toBe($foreign->id);
    $this->get(route('account.profile-photo'))->assertNotFound();
});

test('invalid uploads and crop coordinates are rejected before scanning', function (string $invalid): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $this->mock(MalwareScanner::class)->shouldNotReceive('scan');
    $photo = match ($invalid) {
        'type' => UploadedFile::fake()->create('fake.txt', 1, 'text/plain'),
        'size' => UploadedFile::fake()->image('large.jpg')->size(5121),
        'dimensions' => UploadedFile::fake()->image('wide.jpg', 4097, 10),
        default => UploadedFile::fake()->image('valid.jpg'),
    };
    Livewire::actingAs($user)->test(Settings::class)->set('photo', $photo)->set('photoZoom', $invalid === 'crop' ? 4 : 1)->call('savePhoto')->assertHasErrors($invalid === 'crop' ? 'photoZoom' : 'photo');
    expect(Document::query()->where('document_type_code', 'avatar')->count())->toBe(0);
})->with(['type', 'size', 'dimensions', 'crop']);

test('missing storage or failed security checks preserve the existing photo', function (string $failure): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    if ($failure === 'storage') {
        config(['filesystems.disks.s3.bucket' => '']);
        $this->mock(MalwareScanner::class)->shouldNotReceive('scan');
    } else {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andThrow(new RuntimeException($failure));
    }
    Livewire::actingAs($user)->test(Settings::class)->set('photo', UploadedFile::fake()->image('photo.jpg'))->call('savePhoto')->assertHasErrors('photo');
    expect(Document::query()->where('document_type_code', 'avatar')->count())->toBe(0);
})->with(['storage', 'scanner_unavailable', 'malware_detected']);

test('unprivileged accounts cannot upload remove or access photos', function (): void {
    $user = User::factory()->create();
    Livewire::actingAs($user)->test(Settings::class)->call('savePhoto')->assertForbidden();
    Livewire::actingAs($user)->test(Settings::class)->call('removePhoto')->assertForbidden();
    $this->actingAs($user)->get(route('account.profile-photo'))->assertForbidden();
});

test('cancel discards unsaved selection without changing persistent data', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    Livewire::actingAs($user)->test(Settings::class)->set('photo', UploadedFile::fake()->image('photo.jpg'))->set('photoZoom', 2)->call('cancelPhoto')->assertSet('photo', null)->assertSet('photoZoom', 1);
    expect(Document::query()->where('document_type_code', 'avatar')->count())->toBe(0);
});

test('saved crop matches the selected region and corrupt objects are never delivered', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $image = imagecreatetruecolor(200, 100);
    imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagepng($image);
    $source = ob_get_clean();
    imagedestroy($image);
    $document = app(ProfilePhoto::class)->save($user, UploadedFile::fake()->createWithContent('regions.png', $source), 2, 100, 50);
    $output = imagecreatefromstring(app(ProfilePhoto::class)->bytes($document));
    $pixel = imagecolorsforindex($output, imagecolorat($output, 256, 256));
    imagedestroy($output);
    expect($pixel['blue'])->toBeGreaterThan(240);
    expect($pixel['red'])->toBeLessThan(15);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->get(route('account.profile-photo'))->assertOk();
    Storage::disk('s3')->put($document->object_key, 'tampered');
    $this->get(route('account.profile-photo'))->assertStatus(503);
});

function photoRider(): User
{
    $user = User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create();
    $user->givePermissionTo('rider.access');

    return $user;
}

function photoAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');

    return $user;
}

test('admin and rider save a private scanned profile picture and see it across their workspace', function (string $portal): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $rider = $portal === 'admin' ? photoAdmin() : photoRider();
    Livewire::actingAs($rider)->test(Settings::class)->set('photo', UploadedFile::fake()->image('portrait.png', 80, 120))->call('savePhoto')->assertHasNoErrors()->assertRedirect(route('account.settings'));
    $document = $rider->fresh()->profilePhoto;
    expect($document)->not->toBeNull()->and($document->owner_type)->toBe('user')->and($document->owner_id)->toBe($rider->id)->and($document->scan_status)->toBe('clean');
    Storage::disk('s3')->assertExists($document->object_key);
    $bytes = Crypt::decryptString(Storage::disk('s3')->get($document->object_key));
    expect(getimagesizefromstring($bytes)[0])->toBe(512)->and(getimagesizefromstring($bytes)[1])->toBe(512);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $rider->id, 'action' => 'profile_photo_updated']);
    $this->actingAs($rider)->get(route('account.profile-photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($bytes);
    $this->get(route('account.settings'))->assertOk()->assertSee('Your profile picture')->assertSee('Save Photo')->assertSee('Edit profile photo')->assertSee('Change password');
    $this->get(route($portal.'.home'))->assertOk()->assertSee('Your profile picture');
    $this->post(route('logout'))->assertRedirect();
    $this->post(route($portal === 'admin' ? 'login.store' : 'rider.login.store'), ['email' => $rider->email, 'password' => 'password'])->assertRedirect();
    $this->get(route('account.settings'))->assertOk()->assertSee('Your profile picture');
})->with(['admin', 'rider']);

test('a newer picture replaces the display and another rider cannot read it', function (): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->twice()->andReturnNull();
    $rider = photoRider();
    $first = app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('first.jpg'));
    $second = app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('second.jpg'));
    $foreign = photoRider();
    $this->actingAs($foreign)->get(route('account.profile-photo', ['v' => $second->public_id]))->assertNotFound();
    expect($rider->fresh()->profilePhoto->id)->toBe($second->id);
    Storage::disk('s3')->assertExists($first->object_key);
});

test('invalid or oversized photos are rejected without saving documents', function (string $kind): void {
    $photo = match ($kind) {
        'text' => UploadedFile::fake()->createWithContent('fake.jpg', 'Not an image.'),
        'size' => UploadedFile::fake()->image('large.jpg')->size(5121),
        'dimensions' => UploadedFile::fake()->image('wide.png', 4097, 1),
    };
    Livewire::actingAs(photoRider())->test(Settings::class)->set('photo', $photo)->call('savePhoto')->assertHasErrors(['photo']);
    expect(Document::query()->where('document_type_code', 'avatar')->count())->toBe(0);
})->with(['text', 'size', 'dimensions']);

test('scanner failure preserves the previous profile picture', function (string $failure): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $scanner = $this->mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturnNull();
    $rider = photoRider();
    $previous = app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('original.jpg'));
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException($failure));
    Livewire::actingAs($rider)->test(Settings::class)->set('photo', UploadedFile::fake()->image('replacement.png'))->call('savePhoto')->assertHasErrors(['photo']);
    expect($rider->fresh()->profilePhoto->id)->toBe($previous->id)->and(Document::query()->where('document_type_code', 'avatar')->count())->toBe(1);
})->with(['malware_detected', 'scanner_unavailable']);

test('missing private storage shows a validation error', function (): void {
    config(['filesystems.disks.s3.bucket' => null]);
    $rider = photoRider();
    Livewire::actingAs($rider)->test(Settings::class)->set('photo', UploadedFile::fake()->image('profile.jpg'))->call('savePhoto')->assertHasErrors(['photo']);
    expect($rider->fresh()->profilePhoto)->toBeNull();
});

test('a corrupted stored photo is never served', function (): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $rider = photoRider();
    $document = app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('profile.jpg'));
    Storage::disk('s3')->put($document->object_key, Crypt::encryptString('corrupted content'));
    $this->actingAs($rider)->get(route('account.profile-photo'))->assertStatus(503);
});

test('failed storage does not create a profile document', function (): void {
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('s3')->twice()->andReturn($disk);
    $rider = photoRider();
    expect(fn () => app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('profile.jpg')))->toThrow(ValidationException::class);
    expect($rider->fresh()->profilePhoto)->toBeNull();
});

test('profile photos require an active authorized account and authentication', function (): void {
    $this->get(route('account.profile-photo'))->assertRedirect(route('login'));
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('account.profile-photo'))->assertForbidden();
    Livewire::actingAs($user)->test(Settings::class)->call('savePhoto')->assertForbidden();
    $rider = photoRider();
    $rider->status = AccountStatus::Disabled;
    $rider->save();
    expect(fn () => app(SaveProfilePhoto::class)->handle($rider, UploadedFile::fake()->image('profile.jpg')))->toThrow(HttpException::class);
});

test('removing a photo restores initials permanently without exposing another accounts picture', function (string $portal): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->times(3)->andReturnNull();
    $user = $portal === 'admin' ? photoAdmin() : photoRider();
    $other = photoRider();
    $photos = app(SaveProfilePhoto::class);
    $photos->handle($user, UploadedFile::fake()->image('first.jpg'));
    $photos->handle($user, UploadedFile::fake()->image('second.jpg'));
    $foreign = $photos->handle($other, UploadedFile::fake()->image('other.jpg'));

    Livewire::actingAs($user)->test(Settings::class)->call('removePhoto')->assertRedirect(route('account.settings'));

    expect($user->fresh()->profilePhoto)->toBeNull()->and($other->fresh()->profilePhoto->id)->toBe($foreign->id);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile_photo_removed']);
    $this->get(route('account.profile-photo', ['v' => $foreign->public_id]))->assertNotFound();
    $this->get(route('account.settings'))->assertOk()->assertDontSee('alt="Your profile picture"', false)->assertSee('Change password');
})->with(['admin', 'rider']);

test('cancelling a photo clears the draft and leaves the current image intact', function (): void {
    $user = photoAdmin();
    Livewire::actingAs($user)->test(Settings::class)->set('photo', UploadedFile::fake()->image('draft.jpg'))->set('photoZoom', 2)->set('photoX', 25)->call('cancelPhoto')->assertSet('photo', null)->assertSet('photoZoom', 1)->assertSet('photoX', 50);
    expect($user->fresh()->profilePhoto)->toBeNull();
});

test('photos up to five megabytes are accepted and crop coordinates select the intended region', function (): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->twice()->andReturnNull();
    $user = photoAdmin();
    $photos = app(SaveProfilePhoto::class);
    $photos->handle($user, UploadedFile::fake()->image('large.jpg')->size(5120));
    $source = imagecreatetruecolor(120, 80);
    imagefilledrectangle($source, 0, 0, 59, 79, imagecolorallocate($source, 255, 0, 0));
    imagefilledrectangle($source, 60, 0, 119, 79, imagecolorallocate($source, 0, 0, 255));
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);
    $document = $photos->handle($user, UploadedFile::fake()->createWithContent('crop.png', $bytes), 2, 100, 50);
    $cropped = imagecreatefromstring(Crypt::decryptString(Storage::disk('s3')->get($document->object_key)));
    $color = imagecolorsforindex($cropped, imagecolorat($cropped, 256, 256));
    imagedestroy($cropped);
    expect($color['blue'])->toBeGreaterThan(240)->and($color['red'])->toBeLessThan(10);
});

test('crop parameters cannot exceed safe bounds', function (string $field, float $value): void {
    Livewire::actingAs(photoAdmin())->test(Settings::class)->set('photo', UploadedFile::fake()->image('profile.jpg'))->set($field, $value)->call('savePhoto')->assertHasErrors([$field]);
    expect(Document::query()->where('document_type_code', 'avatar')->count())->toBe(0);
})->with([['photoZoom', 0], ['photoZoom', 4], ['photoX', -1], ['photoY', 101]]);
