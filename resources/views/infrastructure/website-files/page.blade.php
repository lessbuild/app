{{-- The file browser on a page of its own (the website's Files tab loads just the browser). --}}
<x-signal.layouts.project :overview="$overview" :title="__('Files: :website', ['website' => $website->name])" :description="__('The website’s folder on its server. Read-only.')">
    @include('infrastructure.website-files._browser')
</x-signal.layouts.project>
