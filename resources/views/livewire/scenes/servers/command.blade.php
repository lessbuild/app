<div>
    @if ($open)
        <div class="relative z-10" role="dialog" aria-modal="true">
            <button type="button" wire:click="close" class="fixed inset-0 bg-secondary opacity-70" aria-label="{{ __('Close command dialog') }}"></button>
            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center">
                    <div class="relative w-full max-w-2xl rounded-lg border border-primary bg-primary text-left shadow-xl">
                        <form wire:submit.prevent="run">
                            <div class="px-6 py-5">
                                <h3 class="text-lg font-medium text-primary">{{ __('Run command on :server', ['server' => $model->name]) }}</h3>
                                <p class="mt-1 text-sm text-secondary">{{ __('The command runs as root and stops after the configured SSH timeout.') }}</p>
                                <input
                                    wire:model.defer="command"
                                    type="text"
                                    class="input secondary mt-4 w-full rounded font-mono"
                                    placeholder="{{ __('Example: uptime') }}"
                                    autocomplete="off"
                                    autofocus
                                >
                                @error('command') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror

                                @if ($output !== '')
                                    <pre class="mt-4 max-h-80 overflow-auto whitespace-pre-wrap rounded bg-gray-900 p-4 text-sm text-gray-100">{{ $output }}</pre>
                                @endif
                            </div>
                            <div class="flex flex-row-reverse gap-2 border-t border-primary bg-secondary px-6 py-3">
                                <button type="submit" class="button tertiary" wire:loading.attr="disabled" wire:target="run">
                                    <span wire:loading.remove wire:target="run">{{ __('Run command') }}</span>
                                    <span wire:loading wire:target="run">{{ __('Running…') }}</span>
                                </button>
                                <button type="button" wire:click="close" class="button primary">{{ __('Close') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
