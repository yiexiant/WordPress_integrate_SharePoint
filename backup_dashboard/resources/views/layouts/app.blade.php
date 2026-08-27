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
    
    <!-- FontAwesome 6 (Header Logo Only) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
        .btn-red {
            background-color: #dc2626;
            color: #ffffff;
            font-weight: 600;
            transition: all 0.15s ease-in-out;
        }
        .btn-red:hover {
            background-color: #b91c1c;
        }
        .btn-white {
            background-color: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-weight: 600;
            transition: all 0.15s ease-in-out;
        }
        .btn-white:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
    </style>
    @stack('styles')
</head>
<body class="h-full flex flex-col antialiased bg-slate-50 text-slate-800">
    <!-- Navbar (Clean White & Red) -->
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo & Brand (Retained Icon) -->
                <div class="flex items-center gap-3">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-xl bg-red-600 flex items-center justify-center shadow-md shadow-red-600/20 group-hover:scale-105 transition-transform">
                            <i class="fa-solid fa-cloud-arrow-up text-white text-base"></i>
                        </div>
                        <div>
                            <span class="text-base font-bold tracking-tight text-slate-900 flex items-center gap-2">
                                SharePoint Backup <span class="text-xs px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-semibold border border-red-200">v1.0</span>
                            </span>
                            <span class="text-xs text-slate-500 block -mt-0.5">Central Management Dashboard</span>
                        </div>
                    </a>
                </div>

                <!-- Navigation Links (Clean text without icons) -->
                <nav class="hidden md:flex items-center gap-1">
                    <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors {{ request()->routeIs('dashboard') ? 'bg-red-50 text-red-700 border border-red-200/60' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        KPI Dashboard
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
            <!-- Flash Alerts -->
            @if(session('success'))
                <div class="mb-6 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-emerald-900 flex items-start justify-between shadow-sm">
                    <div class="text-sm font-semibold leading-relaxed">{{ session('success') }}</div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold ml-4">
                        &times;
                    </button>
                </div>
            @endif

            @if(session('warning'))
                <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4 text-amber-900 flex items-start justify-between shadow-sm">
                    <div class="text-sm font-semibold leading-relaxed">{{ session('warning') }}</div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-amber-700 hover:text-amber-900 font-bold ml-4">
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
