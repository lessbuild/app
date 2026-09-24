@props(['name', 'class' => 'h-5 w-5'])

<x-signal.ui.icon :name="$name" :class="$class" {{ $attributes }} />
