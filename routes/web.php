<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Livewire\Account\Settings;
use App\Livewire\Admin\Customers\Categories;
use App\Livewire\Admin\Customers\Editor;
use App\Livewire\Admin\Customers\Index;
use App\Livewire\Admin\Customers\Show;
use App\Livewire\Admin\Home as AdminHome;
use App\Livewire\Rider\Home as RiderHome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/rider/login', [SessionController::class, 'create'])->defaults('portal', 'rider')->name('rider.login');
    Route::post('/rider/login', [SessionController::class, 'store'])->defaults('portal', 'rider')->middleware('throttle:login')->name('rider.login.store');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:recovery')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:recovery')->name('password.update');
    Route::get('/activate/{token}', [PasswordController::class, 'edit'])->name('activation.edit');
    Route::post('/activate', [PasswordController::class, 'update'])->middleware('throttle:recovery')->name('activation.update');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/home', function (Request $request) {
        if ($request->user()->can('admin.access')) {
            return redirect()->route('admin.home');
        }
        abort_unless($request->user()->canUseRiderInterface(), 403);

        return redirect()->route('rider.home');
    })->name('home');
    Route::livewire('/admin', AdminHome::class)->middleware('can:admin.access')->name('admin.home');
    Route::middleware('can:admin.access')->prefix('admin/customers')->name('admin.customers.')->group(function (): void {
        Route::livewire('/', Index::class)->name('index');
        Route::livewire('/create', Editor::class)->name('create');
        Route::livewire('/categories', Categories::class)->name('categories');
        Route::livewire('/{customer}/edit', Editor::class)->name('edit');
        Route::livewire('/{customer}', Show::class)->name('show');
    });
    Route::livewire('/rider', RiderHome::class)->middleware('can:use-rider-interface')->name('rider.home');
    Route::livewire('/account', Settings::class)->name('account.settings');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
