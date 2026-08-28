<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 text-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Central Dashboard') - SharePoint WordPress Backup</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#fef2f2',
                            100: '#fee2e2',
                            200: '#fecaca',
                            300: '#fca5a5',
                            400: '#f87171',
                            500: '#ef4444',
                            600: '#dc2626',
                            700: '#b91c1c',
                            800: '#991b1b',
                            900: '#7f1d1d',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }
        .white-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
        }
        .white-card:hover {
            border-color: #cbd5e1;
        }
    </style>
    @stack('styles')
</head>
<body class="h-full flex flex-col antialiased bg-slate-50 text-slate-800">
    <!-- Navbar (Clean White & Red, No Icons) -->
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo & Brand (Clean White & Red Text, No Icons) -->
                <div class="flex items-center gap-3">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 group">
                        <div class="w-9 h-9 rounded-lg bg-red-600 flex items-center justify-center text-white font-extrabold text-sm tracking-wider shadow-sm">
                            SP
                        </div>
                        <div>
                            <span class="text-base font-bold tracking-tight text-slate-900 flex items-center gap-2">
                                SharePoint Backup <span class="text-xs px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-semibold border border-red-200">v1.0</span>
                            </span>
                            <span class="text-xs text-slate-500 block -mt-0.5">Central Management Dashboard</span>
                        </div>
                    </a>
                </div>

                <!-- Navigation Links (Clean Text, No Icons) -->
                <nav class="hidden md:flex items-center gap-1">
                    <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->routeIs('dashboard') ? 'bg-red-50 text-red-700 border border-red-200/60' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        Dashboard
                    </a>
                    <a href="{{ route('websites.index') }}" class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->routeIs('websites.*') ? 'bg-red-50 text-red-700 border border-red-200/60' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        Connected Websites
                    </a>
                </nav>

                <!-- Right Action Button (Clean Red) -->
                <div class="flex items-center gap-3">
                    <a href="{{ route('websites.create') }}" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                        Add Website
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Flash Alerts (Clean White & Red Styling, No Icons) -->
            @if(session('success'))
                <div class="mb-6 rounded-xl bg-white border border-slate-300 p-4 text-slate-900 flex items-start justify-between shadow-sm border-l-4 border-l-red-600">
                    <div class="text-sm font-semibold leading-relaxed">{{ session('success') }}</div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-700 font-bold ml-4">
                        &times;
                    </button>
                </div>
            @endif

            @if(session('warning'))
                <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-red-900 flex items-start justify-between shadow-sm">
                    <div class="text-sm font-semibold leading-relaxed">{{ session('warning') }}</div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900 font-bold ml-4">
                        &times;
                    </button>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-red-900 flex items-start justify-between shadow-sm">
                    <div class="text-sm font-semibold leading-relaxed">{{ session('error') }}</div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900 font-bold ml-4">
                        &times;
                    </button>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-red-900 shadow-sm">
                    <div class="font-bold mb-2 text-red-800 text-sm">
                        Please review the errors below:
                    </div>
                    <ul class="list-disc list-inside text-xs space-y-1 text-red-800">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-200 bg-white py-6 mt-auto text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <span>WordPress SharePoint Backup Central Command &copy; {{ date('Y') }}</span>
            <div class="flex items-center gap-4 text-slate-600 font-medium">
                <span>Microsoft Graph API</span>
                <span>•</span>
                <span>Laravel Framework</span>
                <span>•</span>
                <span>WordPress 6.0+</span>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
