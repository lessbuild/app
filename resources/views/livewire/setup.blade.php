<div wire:poll.5s>
    <div>
        <div class="items-start mb-6">
            <h2 class="mt-4 text-2xl font-bold text-primary uppercase underline">
                Setup Information
            </h2>
            <div class="flex justify-between mt-2 text-sm font-semibold text-secondary">
                <span>Events</span>
                <span>Finished</span>
            </div>

            @foreach($this->processes as $key => $process)

                <div class="flex items-center mt-4">
                    <div @class([
                            'flex flex-shrink-0 justify-center items-center w-5 h-5 rounded border',
                            'bg-green-200 text-green-600 border-green-700' => $model->setup_stage >= ($key + 1),
                            'bg-primary text-primary border-primary' => $model->setup_stage < ($key + 1),
                    ])>
                        <svg @class([
							'w-3 h-3 text-secondary stroke-2',
							'animate-spin' => $model->setup_stage < ($key + 1)
						])>
                            <use xlink:href="/assets/images/icons.svg#{{ $model->setup_stage >= ($key + 1) ? 'check' : 'refresh' }}"></use>
                        </svg>
                    </div>
                    <div class="flex justify-between ml-3 w-full text-sm font-semibold tracking-wider text-secondary">
                        <div class="flex flex-col">
                            <span @class([
                               'text-green-500' => $model->setup_stage >= ($key + 1),
                            ])>
                                {{ $process::$title }}
                            </span>
                            <span class="text-xs">
                                {{ $process::$description }}
                            </span>
                        </div>
                        <span class="text-secondary">
                            {{ $model->setup_stage >= ($key + 1) ? 'Completed' : 'Pending' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
