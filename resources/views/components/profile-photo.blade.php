@php($photo = auth()->user()->profilePhoto)
@if($photo && auth()->user()->canManageOwnProfilePhoto())
    <img src="{{ route('account.profile-photo', ['v' => $photo->public_id]) }}" alt="Your profile picture" {{ $attributes->class(['h-full w-full rounded-full object-cover']) }} />
@else
    <span aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
@endif
