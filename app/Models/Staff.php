<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Database.md staff; Rider is an eligibility attribute, not a separate profile. */
class Staff extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'staff';

    protected $fillable = ['staff_no', 'first_name', 'last_name', 'email', 'gender_code', 'position_code', 'appointment_date', 'phone_ciphertext', 'home_phone_ciphertext', 'address_ciphertext'];

    protected $hidden = ['phone_ciphertext', 'home_phone_ciphertext', 'address_ciphertext'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_rider' => 'boolean', 'appointment_date' => 'immutable_date', 'phone_ciphertext' => 'encrypted', 'home_phone_ciphertext' => 'encrypted', 'address_ciphertext' => 'encrypted'];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
