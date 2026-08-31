<x-layouts.core>

    <div class="z-40 max-w-4xl mx-auto">
        <div class="relative flex items-center justify-between">
            <div class="px-4">
                <a href="/" class="text-primary text-2xl font-bold block w-full py-5">
                    {{ config('app.name') }}
                </a>
            </div>
            <div class="flex w-full items-center justify-between px-4">
                <div>
                    <button id="navbarToggler" class="absolute right-4 top-1/2 block -translate-y-1/2 rounded-lg px-3 py-6 ring-primary focus:ring-2 lg:hidden">
                        <span class="relative my-[6px] block h-2 w-25 bg-secondary"></span>
                        <span class="relative my-[6px] block h-2 w-25 bg-secondary"></span>
                        <span class="relative my-[6px] block h-2 w-25 bg-secondary"></span>
                    </button>
                    <nav id="navbarCollapse" class="absolute right-4 top-full hidden w-full rounded-lg bg-secondary py-5 shadow-lg lg:static lg:block lg:w-full lg:max-w-full lg:bg-transparent lg:py-0 lg:px-4 lg:shadow-none xl:px-6">
                        <ul class="blcok lg:flex">
                            <li class="group relative">
                                <a href="#home" class="mx-8 flex py-2 text-base text-primary group-hover:text-primary lg:mr-0 lg:inline-flex lg:py-6 lg:px-0 lg:text-primary lg:group-hover:text-primary lg:group-hover:opacity-70 active">
                                    Home
                                </a>
                            </li>
                            <li class="group relative">
                                <a href="#about" class="mx-8 flex py-2 text-base text-primary group-hover:text-primary lg:mr-0 lg:ml-7 lg:inline-flex lg:py-6 lg:px-0 lg:text-primary lg:group-hover:text-primary lg:group-hover:opacity-70 xl:ml-12">
                                    About
                                </a>
                            </li>
                            <li class="group relative">
                                <a href="#pricing" class="mx-8 flex py-2 text-base text-primary group-hover:text-primary lg:mr-0 lg:ml-7 lg:inline-flex lg:py-6 lg:px-0 lg:text-primary lg:group-hover:text-primary lg:group-hover:opacity-70 xl:ml-12">
                                    Pricing
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <div class="justify-end flex">
                    <a href="{{ route('login') }}" class="button tertiary">
                        Sign In
                    </a>
                    @if (app(\App\Services\RegistrationAccess::class)->allowsNewUser())
                        <a href="{{ route('register') }}" class="ml-2 button tertiary">
                            Sign Up
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!--
     ! ------------------------------------------------------------
     ! Header
     ! ------------------------------------------------------------
     !-->
    <div class="max-w-7xl mx-auto pt-20">
        <div class="flex flex-wrap items-center">
            <div class="w-full px-4">
                <div class="max-w-3xl mx-auto text-center">
                    <h1 class="mb-8  text-3xl font-bold leading-snug text-primary sm:text-4xl sm:leading-snug md:text-[45px] md:leading-snug">
                        {{ __('Easily deploy your web applications') }}
                    </h1>
                    <p class="mb-10 text-base text-secondary sm:text-lg sm:leading-relaxed md:text-xl md:leading-relaxed">
                        {{ __('Deploying your web applications should not be such a pain. We make it much easier. Simply setup your server, add a website and a PHP repository and we do the rest.') }}
                    </p>
                    <div class="mt-4 text-center flex justify-center space-x-8">
                        <svg class="w-10 h-10 text-secondary fill-current stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#digital-ocean"></use>
                        </svg>
                        <svg class="w-10 h-10 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#github"></use>
                        </svg>
                        <svg class="w-10 h-10 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#git"></use>
                        </svg>
                        <svg class="w-10 h-10 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#wordpress"></use>
                        </svg>
                        <svg class="w-10 h-10 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#laravel"></use>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="w-full px-4">
                <div class="relative z-10 mx-auto">
                    <div class="mt-5">
                        <img src="https://i.imgur.com/qg053pp.png" alt="hero" class="w-2/3 mx-auto rounded-t-xl rounded-tr-xl">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!--
     ! ------------------------------------------------------------
     ! About us
     ! ------------------------------------------------------------
     !-->
    <section id="about" class="mt-20 relative bg-secondary">
        <div class="hidden lg:block bg-tertiary text-tertiary absolute h-80 w-full"></div>
        <div class="pt-10 relative max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap lg:gap-6 px-4">

                <!--
                 ! ------------------------------------------------------------
                 ! Provision servers
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary mt-4 rounded-[5px] py-10 px-[30px] border border-primary flex items-start">
                    <div class="shrink-0">
                        <svg class="w-11 h-11 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#digital-ocean"></use>
                        </svg>
                    </div>
                    <div class="pl-6">
                        <h4 class="font-semibold text-2xl text-primary">
                            {{ __('Easily provision servers') }}
                        </h4>
                        <p class="mt-4 text-secondary">
                            {{ __('Simply select what type of application you want to use, create a server and you are ready to deploy your great application.') }}
                        </p>
                    </div>
                </div>

                <!--
                 ! ------------------------------------------------------------
                 ! Add repositories
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary mt-4 rounded-[5px] py-10 px-[30px] border border-primary flex items-start">
                    <div class="shrink-0">
                        <svg class="w-11 h-11 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#github"></use>
                        </svg>
                    </div>
                    <div class="pl-6">
                        <h4 class="font-semibold text-2xl text-primary">
                            {{ __('Deploy from Repos') }}
                        </h4>
                        <p class="text-secondary mt-4">
                            {{ __('Deploy your application from your git repositories. Making it much easier to deploy your applications') }}
                        </p>
                    </div>
                </div>

                <!--
                 ! ------------------------------------------------------------
                 ! Laravel Compatible
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary mt-4 rounded-[5px] py-10 px-[30px] border border-primary flex items-start">
                    <div class="shrink-0">
                        <svg class="w-11 h-11 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#laravel"></use>
                        </svg>
                    </div>
                    <div class="pl-6">
                        <h4 class="font-semibold text-2xl text-primary">
                            {{ __('Laravel Compatible') }}
                        </h4>
                        <p class="text-secondary mt-4">
                            {{ __('We make deploying your laravel applications a breeze. We include Redis, Memcached, PHP, Mysql and Caddy') }}
                        </p>
                    </div>
                </div>

                <!--
                 ! ------------------------------------------------------------
                 ! Wordpress Compatible
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary mt-4 rounded-[5px] py-10 px-[30px] border border-primary flex items-start">
                    <div class="shrink-0">
                        <svg class="w-11 h-11 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#wordpress"></use>
                        </svg>
                    </div>
                    <div class="pl-6">
                        <h4 class="font-semibold text-2xl text-primary">
                            {{ __('Wordpress Compatible') }}
                        </h4>
                        <p class="text-secondary mt-4">
                            {{ __('We make deploying your wordpress applications a breeze. We include: PHP, Mysql and Caddy') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!--
     ! ------------------------------------------------------------
     ! Pricing
     ! ------------------------------------------------------------
     !-->
    <div id="pricing" class="bg-secondary py-10">
        <main class="max-w-7xl mx-auto w-full place-items-center px-4 pb-12">
            <div class="py-5 text-center lg:py-6">
                <p class="text-3xl font-bold uppercase text-primary">
                    {{ __('Our Pricing Plans') }}
                </p>
                <h3 class="mt-1 text-lg font-semibold text-secondary">
                    {{ __('Easy no hidden pricing') }}
                </h3>
            </div>
            <div class="mx-auto grid max-w-4xl grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-5 lg:gap-6">

                <!--
                 ! ------------------------------------------------------------
                 ! Basic plan
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary text-secondary border border-primary rounded p-4 text-center sm:p-5">
                    <div class="mt-8 flex justify-center">
                        <svg class="w-16 h-16 text-ternary stroke-2">
                            <use xlink:href="/assets/images/icons.svg#key-solid"></use>
                        </svg>
                    </div>
                    <div class="mt-5">
                        <h4 class="text-xl font-semibold text-primary">
                            {{ __('Basic') }}
                        </h4>
                        <p class="text-secondary">
                            {{ __('Great to get you started.') }}
                        </p>
                    </div>
                    <div class="mt-5">
                    <span class="text-4xl tracking-tight text-primary">
                        £5
                    </span>
                        / month
                    </div>
                    <div class="mt-8 space-y-4 text-left">
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Provision 2 servers') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add 2 websites') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add 2 repositories') }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-8">
                        <button class="button tertiary">
                            {{ __('Choose plan') }}
                        </button>
                    </div>
                </div>

                <!--
                 ! ------------------------------------------------------------
                 ! Pro plan
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary text-secondary relative border border-primary rounded p-4 text-center sm:p-5">
                    <div class="absolute top-0 right-0 p-3">
                        <div class="bg-blue-200 text-xs p-1 rounded-full text-blue-500">
                            {{ __('Recommended') }}
                        </div>
                    </div>
                    <div class="mt-8 flex justify-center">
                        <svg class="w-16 h-16 text-ternary stroke-2">
                            <use xlink:href="/assets/images/icons.svg#key-solid"></use>
                        </svg>
                    </div>
                    <div class="mt-5">
                        <h4 class="text-xl font-semibold text-primary">
                            {{ __('Pro') }}
                        </h4>
                        <p class="text-secondary">
                            {{ __('When you need a little more.') }}
                        </p>
                    </div>
                    <div class="mt-5">
                        <span class="text-4xl tracking-tight text-primary dark:text-accent-light">
                            £15
                        </span>
                        / month
                    </div>
                    <div class="mt-8 space-y-4 text-left">
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Provision 10 servers') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add 10 websites') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add 10 repositories') }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-8">
                        <button class="button tertiary">
                            {{ __('Choose Plan') }}
                        </button>
                    </div>
                </div>

                <!--
                 ! ------------------------------------------------------------
                 ! Enterprise Plan
                 ! ------------------------------------------------------------
                 !-->
                <div class="bg-primary text-secondary border border-primary rounded p-4 text-center sm:p-5">
                    <div class="mt-8 flex justify-center">
                        <svg class="w-16 h-16 text-ternary stroke-2">
                            <use xlink:href="/assets/images/icons.svg#key-solid"></use>
                        </svg>
                    </div>
                    <div class="mt-5">
                        <h4 class="text-xl font-semibold text-primary">
                            {{ __('Enterprise') }}
                        </h4>
                        <p class="text-secondary">
                            {{ __('The provision master') }}
                        </p>
                    </div>
                    <div class="mt-5">
                        <span class="text-4xl tracking-tight text-primary dark:text-accent-light">
                            £25
                        </span>
                        / month
                    </div>
                    <div class="mt-8 space-y-4 text-left">
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Provision unlimited servers') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add unlimited websites') }}
                            </span>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tertiary text-primary">
                                <svg class="w-4 h-4 text-white stroke-2">
                                    <use xlink:href="/assets/images/icons.svg#check"></use>
                                </svg>
                            </div>
                            <span class="font-medium">
                                {{ __('Add unlimited repositories') }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-8">
                        <button class="button tertiary">
                            Choose Plan
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>


    <footer class="bg-secondary border-t border-primary px-4 py-12">
        <div class="max-w-6xl mx-auto">
            <div class="flex flex-wrap -mx-4">

                <div class="w-full lg:w-1/3 px-4 mb-12 lg:mb-0 flex flex-col">
                    <span class="text-3xl font-bold mb-2 text-primary">
                        {{ config('app.name') }}
                    </span>
                    <span class="text-secondary mr-4">
                        {{ __('Easily deploy your applications, databases, workers and more.') }}
                    </span>
                </div>

                <div class="w-full lg:w-2/3 px-4">
                    <div class="flex flex-wrap -mx-4">

                        <div class="w-full flex-grow sm:w-auto px-4 mb-12 lg:mb-0">
                            <h3 class="mb-5 text-lg font-bold font-heading text-primary">
                                {{ __('Other producs') }}
                            </h3>
                            <ul>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline"
                                        href="https://somecv.com"
                                        target="_blank"
                                    >
                                        Somecv (Connect with professionals)
                                    </a>
                                </li>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline"
                                        href="https://gopayee.com"
                                        target="_blank"
                                    >
                                        Gopayee (Mange contacts, leads, invoices and more)
                                    </a>
                                </li>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline"
                                        href="https://onlysort.com"
                                        target="_blank"
                                    >
                                        Onlysort (Mange assets, warehouses and more.)
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <!--
                         ! ------------------------------------------------------------
                         ! General company information.
                         ! ------------------------------------------------------------
                         !-->
                        <div class="w-full flex-grow sm:w-auto px-4 mb-12 lg:mb-0">
                            <h3 class="mb-5 text-lg font-bold font-heading text-primary">
                                Company
                            </h3>
                            <ul>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline" href="http://gopayee.test/useful/about">
                                        About us
                                    </a>
                                </li>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline" href="http://gopayee.test/billing/pricing">
                                        Pricing
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <!--
                         ! ------------------------------------------------------------
                         ! Show the terms and privacy links
                         ! ------------------------------------------------------------
                         !-->
                        <div class="w-full flex-grow sm:w-auto px-4 mb-12 lg:mb-0">
                            <h3 class="mb-5 text-lg font-bold font-heading text-primary">
                                Legal
                            </h3>
                            <ul>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline" href="http://gopayee.test/legal/terms">
                                        Terms of service
                                    </a>
                                </li>
                                <li class="mb-3">
                                    <a class="text-sm text-secondary hover:underline" href="http://gopayee.test/legal/privacy">
                                        Privacy Policy
                                    </a>
                                </li>
                            </ul>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <div class="max-w-6xl mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 mt-8 lg:mt-10 border-t-2 border-primary pt-8 ">
                <div class="text-secondary text-sm space-y-4 md:space-y-1 text-center md:text-left">
                    <p>
                        &copy;
                        <script>document.write(new Date().getFullYear())</script>
                        {{ config('app.name') }}.
                        All rights reserved.
                    </p>
                    <p>
                        {{ __('By using our website your agree to our terms of service and privacy policy') }}
                    </p>
                </div>
            </div>
        </div>
    </footer>

</x-layouts.core>
