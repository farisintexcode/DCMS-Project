<?php

use App\Actions\Auth\ProfilePhoto;
use App\Livewire\Account\Settings;
use App\Models\Document;
use App\Models\Staff;
use App\Models\User;
use App\Services\Imports\MalwareScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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
