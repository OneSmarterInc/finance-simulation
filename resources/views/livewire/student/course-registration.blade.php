<div class="min-h-screen bg-zinc-50">

    {{-- Header --}}
    <header class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-6">

            <div>
                <div class="text-lg font-semibold tracking-tight text-zinc-950">
                    FLEXEE
                </div>

                <div class="text-[11px] text-zinc-500">
                    Finance Simulation
                </div>
            </div>

            <div class="text-xs text-zinc-500">
                Student Registration
            </div>

        </div>
    </header>


    {{-- Main --}}
    <main class="flex min-h-[calc(100vh-4rem)] items-center justify-center px-6 py-10">

        <div class="w-full max-w-2xl">

            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm">

                {{-- Card Header --}}
                <div class="border-b border-zinc-200 px-8 py-7 text-center">

                    <div class="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-900 text-sm font-semibold text-white">
                        F
                    </div>

                    <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                        Course Registration
                    </div>

                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">
                        {{ $course->name }}
                    </h1>

                </div>


                {{-- Course Information --}}
                <div class="px-8 py-7">

                    <div class="grid gap-5 sm:grid-cols-2">

                        @if($course->scenario)
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wider text-zinc-400">
                                    Simulation
                                </div>

                                <div class="mt-1 text-sm font-medium text-zinc-800">
                                    {{ $course->scenario->name }}
                                </div>
                            </div>
                        @endif

                        @if($course->institution)
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wider text-zinc-400">
                                    Institution
                                </div>

                                <div class="mt-1 text-sm font-medium text-zinc-800">
                                    {{ $course->institution->name }}
                                </div>
                            </div>
                        @endif

                    </div>


                    {{-- Invitation Message --}}
                    <div class="mt-7 rounded-xl bg-zinc-50 px-5 py-4">

                        <p class="text-sm leading-6 text-zinc-600">
                            You have been invited to join this finance simulation.
                            Register your student account to participate in the course.
                        </p>

                    </div>


                    {{-- Registration Status --}}
                    <div class="mt-6 flex items-center justify-between">

                        <span class="text-sm text-zinc-500">
                            Registration Status
                        </span>

                        @if($this->registrationOpen)

                            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Registration Open
                            </span>

                        @else

                            <span class="inline-flex items-center gap-2 rounded-full bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                Registration Closed
                            </span>

                        @endif

                    </div>


                    {{-- Registration Form --}}
                    @if($this->registrationOpen)

                        <div
                            id="registration"
                            class="mt-8 border-t border-zinc-200 pt-8"
                        >

                            <div class="mb-6">

                                <h2 class="text-lg font-semibold text-zinc-950">
                                    Create Student Account
                                </h2>

                                <p class="mt-1 text-sm text-zinc-500">
                                    Enter your details below to register for this course.
                                </p>

                            </div>


                            <form
                                wire:submit="register"
                                class="space-y-5"
                            >

                                {{-- Name --}}
                                <div>

                                    <label
                                        for="name"
                                        class="block text-sm font-medium text-zinc-700"
                                    >
                                        Full Name
                                    </label>

                                    <input
                                        id="name"
                                        type="text"
                                        wire:model="name"
                                        autocomplete="name"
                                        required
                                        class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900"
                                    >

                                    @error('name')
                                        <p class="mt-2 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror

                                </div>


                                {{-- Email --}}
                                <div>

                                    <label
                                        for="email"
                                        class="block text-sm font-medium text-zinc-700"
                                    >
                                        Email Address
                                    </label>

                                    <input
                                        id="email"
                                        type="email"
                                        wire:model="email"
                                        autocomplete="email"
                                        required
                                        class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900"
                                    >

                                    @error('email')
                                        <p class="mt-2 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror

                                </div>


                                {{-- Password --}}
                                <div>

                                    <label
                                        for="password"
                                        class="block text-sm font-medium text-zinc-700"
                                    >
                                        Password
                                    </label>

                                    <input
                                        id="password"
                                        type="password"
                                        wire:model="password"
                                        autocomplete="new-password"
                                        required
                                        class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900"
                                    >

                                    @error('password')
                                        <p class="mt-2 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror

                                </div>


                                {{-- Confirm Password --}}
                                <div>

                                    <label
                                        for="password_confirmation"
                                        class="block text-sm font-medium text-zinc-700"
                                    >
                                        Confirm Password
                                    </label>

                                    <input
                                        id="password_confirmation"
                                        type="password"
                                        wire:model="password_confirmation"
                                        autocomplete="new-password"
                                        required
                                        class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900"
                                    >

                                    @error('password_confirmation')
                                        <p class="mt-2 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror

                                </div>


                                {{-- General Registration Error --}}
                                @error('registration')
                                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">

                                        <p class="text-sm text-red-700">
                                            {{ $message }}
                                        </p>

                                    </div>
                                @enderror


                                {{-- Submit --}}
                                <div class="pt-2">

                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="register"
                                        class="flex w-full items-center justify-center rounded-xl bg-zinc-900 px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    >

                                        <span
                                            wire:loading.remove
                                            wire:target="register"
                                        >
                                            Register & Continue
                                        </span>

                                        <span
                                            wire:loading
                                            wire:target="register"
                                        >
                                            Registering...
                                        </span>

                                    </button>

                                </div>

                            </form>

                        </div>

                    @else

                        <div class="mt-7 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-center">

                            <p class="text-sm text-red-700">
                                Registration for this course is currently closed.
                            </p>

                        </div>

                    @endif

                </div>

            </div>


            <p class="mt-6 text-center text-xs text-zinc-400">
                © {{ date('Y') }} FLEXEE Finance Simulation
            </p>

        </div>

    </main>

</div>