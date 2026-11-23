<div class="flex min-h-screen flex-col items-center justify-center bg-white px-6">
  <div class="flex flex-col items-center gap-10">
    <!-- Logo -->
    <img src="/logo.png" alt="Meingedeck" class="h-16 w-auto sm:h-20" />

    <!-- Welcome -->
    <div class="text-center">
      <h1 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Willkommen</h1>
      <p class="mt-2 text-sm text-gray-500">Ihr Zugang zur Plattform</p>
    </div>

    <!-- Login Button -->
    @auth
      <a href="{{ url('/dashboard') }}" class="rounded-lg bg-gray-900 px-8 py-3 text-sm font-semibold text-white transition-colors duration-200 hover:bg-gray-800">
        Zum Dashboard
      </a>
    @else
      <a href="{{ route('login') }}" class="rounded-lg bg-gray-900 px-8 py-3 text-sm font-semibold text-white transition-colors duration-200 hover:bg-gray-800">
        Anmelden
      </a>
    @endauth
  </div>

  <!-- Footer -->
  <p class="absolute bottom-6 text-xs text-gray-300">Meingedeck</p>
</div>
