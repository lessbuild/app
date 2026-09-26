@props(['padding' => 'p-5 sm:p-6', 'as' => 'div', 'shadow' => true])
<x-signal.ui.card :as="$as" :padding="$padding" :shadow="$shadow" {{ $attributes }}>{{ $slot }}</x-signal.ui.card>
