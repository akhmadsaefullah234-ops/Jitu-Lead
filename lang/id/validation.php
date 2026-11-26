<?php

/*
 * Validation messages in Indonesian. Buyers see these on the public forms (the form controllers switch to "id");
 * the field names come from the form's own labels (App\LeadForms\FormFields::attributes()).
 */
return [
    'accepted' => ':Attribute wajib dicentang.',
    'active_url' => ':Attribute bukan alamat web yang benar.',
    'after' => ':Attribute harus tanggal setelah :date.',
    'after_or_equal' => ':Attribute harus tanggal :date atau sesudahnya.',
    'alpha' => ':Attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':Attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':Attribute hanya boleh berisi huruf dan angka.',
    'array' => ':Attribute tidak sesuai.',
    'before' => ':Attribute harus tanggal sebelum :date.',
    'before_or_equal' => ':Attribute harus tanggal :date atau sebelumnya.',
    'between' => [
        'array' => ':Attribute harus berisi :min sampai :max pilihan.',
        'file' => ':Attribute harus berukuran :min sampai :max kilobita.',
        'numeric' => ':Attribute harus antara :min dan :max.',
        'string' => ':Attribute harus berisi :min sampai :max karakter.',
    ],
    'boolean' => ':Attribute harus ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'date' => ':Attribute bukan tanggal yang benar.',
    'date_format' => ':Attribute harus berformat :format.',
    'different' => ':Attribute dan :other harus berbeda.',
    'digits' => ':Attribute harus :digits angka.',
    'digits_between' => ':Attribute harus :min sampai :max angka.',
    'email' => ':Attribute harus alamat email yang benar.',
    'exists' => ':Attribute yang dipilih tidak ditemukan.',
    'file' => ':Attribute harus berupa berkas.',
    'filled' => ':Attribute wajib diisi.',
    'image' => ':Attribute harus berupa gambar.',
    'in' => ':Attribute yang dipilih tidak tersedia.',
    'integer' => ':Attribute harus bilangan bulat.',
    'max' => [
        'array' => ':Attribute maksimal :max pilihan.',
        'file' => ':Attribute maksimal :max kilobita.',
        'numeric' => ':Attribute maksimal :max.',
        'string' => ':Attribute maksimal :max karakter.',
    ],
    'mimes' => ':Attribute harus berkas berjenis :values.',
    'mimetypes' => ':Attribute harus berkas berjenis :values.',
    'min' => [
        'array' => ':Attribute minimal :min pilihan.',
        'file' => ':Attribute minimal :min kilobita.',
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
    ],
    'not_in' => ':Attribute yang dipilih tidak tersedia.',
    'numeric' => ':Attribute harus berupa angka.',
    'present' => ':Attribute wajib ada.',
    'regex' => 'Format :attribute tidak sesuai.',
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi.',
    'required_unless' => ':Attribute wajib diisi.',
    'required_with' => ':Attribute wajib diisi bila :values diisi.',
    'required_with_all' => ':Attribute wajib diisi bila :values diisi.',
    'required_without' => ':Attribute wajib diisi bila :values tidak diisi.',
    'required_without_all' => ':Attribute wajib diisi bila :values tidak diisi.',
    'same' => ':Attribute dan :other harus sama.',
    'size' => [
        'array' => ':Attribute harus berisi :size pilihan.',
        'file' => ':Attribute harus berukuran :size kilobita.',
        'numeric' => ':Attribute harus :size.',
        'string' => ':Attribute harus :size karakter.',
    ],
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah dipakai.',
    'uploaded' => ':Attribute gagal diunggah. Coba lagi.',
    'url' => ':Attribute harus alamat web yang benar.',

    'custom' => [],

    // The capture endpoint's fixed fields (built forms name theirs from the form's labels).
    'attributes' => [
        'name' => 'nama',
        'phone' => 'nomor WhatsApp',
        'email' => 'email',
        'note' => 'catatan',
    ],
];
