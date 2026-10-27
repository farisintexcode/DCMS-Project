@props(['rider' => false])
@php
$slides = [
    ['title'=>'Clarity for every collection.', 'description'=>'A focused workspace for customer records, field teams and the work that connects them.', 'icon'=>'users', 'card'=>'Your customers. In focus.', 'detail'=>'Organized records. Clear context.', 'left'=>'Customer records', 'right'=>'Collection management', 'leftIcon'=>'users', 'rightIcon'=>'layers'],
    ['title'=>'Built for field teams.', 'description'=>'Keep riders, staff and assignments connected with the context needed for every field visit.', 'icon'=>'pin', 'card'=>'Ready for the next visit.', 'detail'=>'Clear assignments. Connected field teams.', 'left'=>'Riders & staff', 'right'=>'Field assignments', 'leftIcon'=>'users', 'rightIcon'=>'pin'],
    ['title'=>'Stay on top of every task.', 'description'=>'Follow assignments, spot overdue work and keep progress visible from allocation to verification.', 'icon'=>'clipboard', 'card'=>'Every task. A clear next step.', 'detail'=>'Monitor progress and follow up with confidence.', 'left'=>'Task monitoring', 'right'=>'Progress & follow-up', 'leftIcon'=>'clipboard', 'rightIcon'=>'check'],
    ['title'=>'Secure and controlled access.', 'description'=>'Support accountable work with user roles, controlled access and independent verification.', 'icon'=>'shield', 'card'=>'The right access. The right team.', 'detail'=>'Clear roles. Accountable verification.', 'left'=>'Role-based access', 'right'=>'Verification controls', 'leftIcon'=>'lock', 'rightIcon'=>'shield'],
];
if ($rider) {
    $slides[0] = ['title'=>'On the road. Always in control.', 'description'=>'Get your assignments, update your progress and stay connected wherever you are.', 'icon'=>'bike', 'card'=>'Your assignments. On the go.', 'detail'=>'Clear tasks for every field visit.', 'left'=>'Task updates', 'right'=>'Stay connected', 'leftIcon'=>'clipboard', 'rightIcon'=>'activity'];
}
@endphp
<section class="welcome-carousel" role="region" aria-roledescription="carousel" aria-label="Explore Nexora"
 x-data="{ active: 0, total: 4, timer: null, until: 0, hovering: false, focused: false, stopped: false, touchX: 0, touchY: 0, reduced: window.matchMedia('(prefers-reduced-motion: reduce)'),
 init() { this.timer = setInterval(() => { if (!this.stopped && !this.reduced.matches && !this.hovering && !this.focused && !document.hidden && Date.now() >= this.until) this.active = (this.active + 1) % this.total }, 5000) },
 destroy() { clearInterval(this.timer) },
 defer() { this.until = Date.now() + 10000 },
 go(index) { this.active = (index + this.total) % this.total; this.defer() },
 swipe(event) { const dx = event.changedTouches[0].clientX - this.touchX; const dy = event.changedTouches[0].clientY - this.touchY; if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) this.go(this.active + (dx < 0 ? 1 : -1)); this.defer() }
 }"
 @mouseenter="hovering = true" @mouseleave="hovering = false; defer()" @focusin="focused = true" @focusout="if (!$el.contains($event.relatedTarget)) { focused = false; defer() }"
 @pointerdown="defer()" @keydown.arrow-right.prevent="go(active + 1)" @keydown.arrow-left.prevent="go(active - 1)"
 @touchstart.passive="touchX = $event.touches[0].clientX; touchY = $event.touches[0].clientY; defer()" @touchend.passive="swipe($event)">
 <p class="eyebrow">{{ $rider ? 'The Nexora rider workspace' : 'The Nexora collection workspace' }}</p>
 <div class="welcome-slide-stage" aria-live="off">
 @foreach($slides as $slide)
 <article class="welcome-slide" x-show="active === {{ $loop->index }}" @if(!$loop->first) x-cloak @endif x-transition:enter="welcome-fade-enter" x-transition:enter-start="welcome-fade-start" x-transition:enter-end="welcome-fade-end" x-transition:leave="welcome-fade-leave" x-transition:leave-start="welcome-fade-end" x-transition:leave-end="welcome-fade-start" :inert="active !== {{ $loop->index }}" :aria-hidden="active !== {{ $loop->index }}" role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of 4">
 <h2>{{ $slide['title'] }}</h2><span class="welcome-title-accent" aria-hidden="true"></span><p class="welcome-description">{{ $slide['description'] }}</p>
 <div class="auth-grid welcome-visual"><div class="welcome-feature-card"><span class="icon-tile"><x-icon :name="$slide['icon']" /></span><div><p class="font-semibold">{{ $slide['card'] }}</p><p class="mt-1 text-xs">{{ $slide['detail'] }}</p></div><x-icon name="check" class="ml-auto" /></div><div class="welcome-connector"></div><div class="welcome-mini-grid"><div><x-icon :name="$slide['leftIcon']" /><span>{{ $slide['left'] }}</span></div><div><x-icon :name="$slide['rightIcon']" /><span>{{ $slide['right'] }}</span></div></div></div>
 </article>
 @endforeach
 </div>
 <div class="welcome-controls"><div class="welcome-dots" aria-label="Choose a slide">@foreach($slides as $slide)<button type="button" class="welcome-dot" :class="{ 'is-active': active === {{ $loop->index }} }" :aria-current="active === {{ $loop->index }} ? 'true' : null" @click="go({{ $loop->index }})" aria-label="Show slide {{ $loop->iteration }}: {{ $slide['title'] }}"><span></span></button>@endforeach</div><div class="welcome-control-actions"><button class="welcome-arrow welcome-desktop-arrow" type="button" aria-label="Previous slide" @click="go(active - 1)"><x-icon name="chevron" class="rotate-180 size-4" /></button><button class="welcome-arrow" type="button" :aria-label="stopped ? 'Resume slideshow' : 'Pause slideshow'" @click="stopped = !stopped; defer()"><svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><path x-show="stopped" d="m8 5 11 7-11 7Z"/><path x-show="!stopped" d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg></button><button class="welcome-arrow welcome-desktop-arrow" type="button" aria-label="Next slide" @click="go(active + 1)"><x-icon name="chevron" class="size-4" /></button></div></div>
</section>
