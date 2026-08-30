<x-layouts.core>
    <div class="flex flex-wrap" x-data="{ menu: false }">

        <!--
         ! ------------------------------------------------------------
         ! Load the navigation
         ! ------------------------------------------------------------
         !-->
        <x-layouts.sidebar />

        <!--
         ! ------------------------------------------------------------
         ! Website main content
         ! ------------------------------------------------------------
         !-->
        <div class="w-full bg-secondary pl-0 lg:pl-64 min-h-screen">
            <div class="sticky top-0 z-40 bg-primary">
                <div class="w-full h-14 px-6 border-b border-primary flex items-center justify-between">
                    <div class="flex">
                        <div class="inline-block lg:hidden flex items-center mr-4">
                            <button class="button tertiary" @click="menu = !menu">
                                <svg class="w-4 h-4 text-secondary stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#menu"></use>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center relative">
                        <img src="https://ui-avatars.com/api/?name={{ auth()->user()->name }}&size=128&background=1e293b&color=fff"
                            class="h-6 h-6 rounded shadow-lg">

                        <form action="{{ route('logout') }}" method="post" class="ml-4">
                            @csrf
                            <button class="button tertiary">Logout</button>
                        </form>
                    </div>
                </div>
                <div class="absolute bg-primary border border-t-0 shadow-xl text-primary rounded-b-lg w-48 bottom-10 right-0 mr-6 hidden">
                    <a href="#" class="block px-4 py-2 hover:bg-gray-200">Account</a>
                    <a href="#" class="block px-4 py-2 hover:bg-gray-200">Settings</a>
                    <a href="#" class="block px-4 py-2 hover:bg-gray-200">Logout</a></div>
            </div>

            <div class="p-6 mb-20">
                <x-alerts.flash />
                {{ $slot }}
            </div>
        </div>

        <!--
         ! ------------------------------------------------------------
         ! Footer and links
         ! ------------------------------------------------------------
         !-->
        <div class="w-full bg-primary border-primary text-primary border-t px-8 py-6 lg:flex justify-between items-center text-sm">
            <p class="mb-2 lg:mb-0">
                © Copyright 2020
            </p>
            <div class="flex">
                <a href="#" class="mr-6 hover:text-gray-900">Terms of Service</a>
                <a href="#" class="mr-6 hover:text-gray-900">Privacy Policy</a>
                <a href="#" class="hover:text-gray-900">About Us</a></div>
        </div>
    </div>
</x-layouts.core>
