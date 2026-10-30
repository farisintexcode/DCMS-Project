@if(auth()->user()->canManageOwnProfilePhoto() && auth()->user()->profilePhoto)
    <img src="{{ route('account.profile-photo', ['v' => auth()->user()->profilePhoto->public_id]) }}" alt="Your profile picture" {{ $attributes->class(['h-full w-full rounded-full object-cover']) }} />
@else
    <span aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
@endif
