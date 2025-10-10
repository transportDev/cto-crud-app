<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Akses Ditolak</title>
    @vite(['resources/css/app.css', 'resources/css/errors.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700&display=swap" rel="stylesheet" />
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
</head>

<body>
    <div class="error-container">
        <!-- Animated background -->
        <div class="error-bg"></div>

        <!-- Error card -->
        <div class="error-card">
            <!-- Lock icon -->
            <!-- <div class="icon-wrapper">
                <svg class="lock-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
            </div> -->

            <!-- Error code -->
            <h1 class="error-code">403</h1>

            <!-- Error title -->
            <h2 class="error-title">Akses Ditolak</h2>

            <!-- Error description -->
            <p class="error-description">
                Maaf, Anda tidak memiliki izin untuk mengakses halaman ini.
                Silakan hubungi administrator jika Anda yakin ini adalah kesalahan.
            </p>

            <!-- Action buttons -->
            <div class="button-group">
                <a href="{{ url('/dashboard') }}" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    Kembali ke Beranda
                </a>

            </div>


        </div>
    </div>
</body>

</html>