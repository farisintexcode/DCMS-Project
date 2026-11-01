<?php

use App\Livewire\Rider\Profile;
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
});

function riderAccountUser(): User
{
    $staff = Staff::factory()->create(['is_rider' => true, 'first_name' => 'Aina', 'last_name' => 'Yusof', 'staff_no' => 'EMP-204', 'phone_ciphertext' => '+60123456789']);
    $user = User::factory()->for($staff)->create();
    $user->assignRole('Rider');

    return $user;
}

test('my profile displays only the current riders database identity and keeps employment read only', function (): void {
    $rider = riderAccountUser();
    $other = User::factory()->for(Staff::factory())->create();
    $this->actingAs($rider)->get(route('rider.profile', ['user' => $other->public_id]))->assertOk()->assertSee('My Profile')->assertSee('Aina Yusof')->assertSee('EMP-204')->assertSee('+60123456789')->assertSee($rider->email)->assertDontSee($other->email)->assertDontSee('wire:model="staff', false)->assertDontSee('Change password')->assertDontSee('Attendance history');
});

test('profile access requires an active eligible Rider', function (string $state): void {
    if ($state === 'guest') {
        $this->get(route('rider.profile'))->assertRedirect();

        return;
    }
    $rider = riderAccountUser();
    if ($state === 'permission') {
        $rider->syncRoles([]);
    } elseif ($state === 'staff') {
        $rider->staff->forceFill(['is_active' => false])->save();
    } else {
        $rider->forceFill(['status' => 'disabled'])->save();
    }
    $response = $this->actingAs($rider)->get(route('rider.profile'));
    if ($state === 'permission') {
        $response->assertForbidden();
    } else {
        $response->assertRedirect();
        $this->assertGuest();
    }
})->with(['guest', 'permission', 'staff', 'account']);

test('profile photo saves and removes through the existing shared private action', function (): void {
    $rider = riderAccountUser();
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    Livewire::actingAs($rider)->test(Profile::class)->set('photo', UploadedFile::fake()->image('portrait.png'))->call('savePhoto')->assertHasNoErrors()->assertRedirect(route('rider.profile'));
    expect($rider->fresh()->profilePhoto)->not->toBeNull();
    $this->get(route('rider.profile'))->assertOk()->assertSee('Your profile picture');
    Livewire::actingAs($rider)->test(Profile::class)->call('removePhoto')->assertRedirect(route('rider.profile'));
    expect($rider->fresh()->profilePhoto)->toBeNull();
});

test('Rider settings separates security and appearance from profile information', function (): void {
    $rider = riderAccountUser();
    $this->actingAs($rider)->get(route('account.settings'))->assertOk()->assertSee('Account Settings')->assertSee('Current Password')->assertSee('Password requirements')->assertSee('System Default')->assertDontSee('Edit profile photo')->assertDontSee('Personal information')->assertDontSee('EMP-204')->assertDontSee('Bahasa Melayu')->assertDontSee('Notification preferences');
});

test('Admin settings retains its profile editor and password layout', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->get(route('account.settings'))->assertOk()->assertSee('Edit profile photo')->assertSee('Change password')->assertSee('Your workspace identity')->assertDontSee('System Default');
});
