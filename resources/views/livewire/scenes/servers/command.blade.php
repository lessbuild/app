<div>
    <div class="relative z-10">
        <div class="fixed inset-0 bg-secondary opacity-70 transition-opacity"></div>
        <div class="fixed inset-0 z-10 overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="relative transform overflow-hidden rounded-lg border border-primary bg-primary text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div class="bg-primary px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded bg-secondary border border-primary sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="w-6 h-6 text-primary">
                                    <use xlink:href="/assets/images/icons.svg#terminal"></use>
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg font-medium leading-6 text-gray-900" id="modal-title">
                                    {{ __('Run command on server') }}
                                </h3>
                                <div class="mt-2">
                                    <input
                                        type="text"
                                        class="input secondary rounded"
                                        placeholder="example: ls -a">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-secondary border-t border-primary grid gap-2 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <button class="button primary">Cancel</button>
                        <button class="button tertiary">Run command</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
