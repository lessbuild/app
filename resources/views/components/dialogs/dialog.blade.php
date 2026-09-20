@props([
    'id',
    'route',
    'title',
    'description',
])

<x-dialogs.delete
    :id="$id"
    :route="$route"
    :title="$title"
    :description="$description"
/>
