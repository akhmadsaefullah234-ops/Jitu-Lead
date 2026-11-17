<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:48rem">
        Isi data proyek sekali di sini. AI menjawab harga, luas, cara bayar, dan jadwal survei dari data ini, dengan penulisan yang sama di setiap
        jawaban (misalnya "38 m2" dan "Rp642.850.000 (sekitar Rp642,85 juta)"). Setiap tipe unit masuk Katalog properti. Yang dikosongkan tidak
        dijawab AI, dan chat soal itu diserahkan ke agen.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
