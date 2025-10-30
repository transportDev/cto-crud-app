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

        <div class="error-bg"></div>


        <div class="error-card">



            <h1 class="error-code">403</h1>


            <h2 class="error-title">Akses Ditolak</h2>

            <p class="error-description">
                Maaf, Anda tidak memiliki izin untuk mengakses halaman ini.
                Silakan hubungi administrator jika Anda yakin ini adalah kesalahan.
            </p>


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