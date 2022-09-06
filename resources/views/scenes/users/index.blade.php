<x-layouts.app>

    <x-forms.section
        :title="__('Browser Sessions')"
        :description="__('If necessary, you may log out of all of your other browser sessions across all of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive. If you feel your account has been compromised, you should also update your password.')"
    >
        <div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
            <div class="flex pb-4">
                <div class="flex items-center mr-3 text-teal-400">
                    <svg fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        viewBox="0 0 24 24" stroke="currentColor" class="w-8 h-8">
                        <path
                            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-sm text-primary">OS X - Chrome</div>
                    <div>
                        <div class="text-secondary text-xs">
                            <a href="https://tools.keycdn.com/geo?host=2a01:4c8:82f:624d:a432:48a3:5752:b566"
                                target="_blank"
                                rel="noreferrer noopener"
                                class="inline-flex text-teal-400"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                                    class="mr-0.5 w-3 h-3">
                                    <path fill-rule="evenodd"
                                        d="M4 10a8 8 0 1 1 16 0c0 3.756-1.824 5.226-3.524 6.596-1.115.898-2.177 1.754-2.636 3.184l-.51 1.54a1 1 0 0 1-1 .68h-.56a1 1 0 0 1-1-.68l-.51-1.54c-.478-1.426-1.555-2.28-2.683-3.176C5.849 15.232 4 13.764 4 10zm5 0a3 3 0 0 0 5.121 2.121A3 3 0 0 0 12 7a3 3 0 0 0-3 3z"></path>
                                </svg>
                                <span>2a01:4c8:82f:624d:a432:48a3:5752:b566</span>
                            </a> ,
                            &nbsp;
                            <span class="text-secondary font-semibold">
                            This browser
                        </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <x-slot:footer>
            <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                <button class="cursor-pointer button primary" type="button">
                    <span class="flex items-center justify-between">
                        Log Out Other Browser Sessions
                    </span>
                </button>
            </div>
        </x-slot:footer>

    </x-forms.section>
</x-layouts.app>
