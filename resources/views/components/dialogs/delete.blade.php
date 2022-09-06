<dialog class="bg-primary appearance-none" id="{{ $id }}" modal-mode="mini">
    <div class="fixed z-10 inset-0 overflow-y-auto">
        <div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">
            <div class="relative bg-primary rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg sm:w-full">
                <form action="{{ $route }}" method="post">
                    @method('delete')
                    @csrf
                    <div class="bg-primary px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-14 w-14 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-red-600">
                                    <use xlink:href="/assets/images/icons.svg#information-circle"></use>
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-primary" id="modal-title">
                                    {{ $title }}
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-secondary">
                                        {{ $description }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-tertiary px-4 py-3 sm:px-6 space-x-2 flex justify-end">
                        <button type="button" class="button primary" onclick="this.closest('dialog').close('cancel')">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit" class="button danger">
                            {{ __('Delete') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</dialog>
