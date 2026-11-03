<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#dc2626">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="JITU LEAD">
@auth
    <script>if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });</script>
@endauth
{{-- viewport-fit for the notch; interactive-widget so the page shrinks above the on-screen keyboard (Android
     Chrome), which keeps the Inbox compose box in sight while typing. --}}
<script>(function () { var m = document.querySelector('meta[name="viewport"]'); if (!m) return; if (m.content.indexOf('viewport-fit') < 0) m.content += ', viewport-fit=cover'; if (m.content.indexOf('interactive-widget') < 0) m.content += ', interactive-widget=resizes-content'; })();</script>
<meta name="apple-mobile-web-app-status-bar-style" content="default">
