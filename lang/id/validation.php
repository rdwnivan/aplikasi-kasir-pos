<?php

/*
| Indonesian validation messages. Only the rules this app uses are translated; anything else
| falls back to English.
*/

return [
    'accepted' => ':Attribute harus disetujui.',
    'array' => ':Attribute harus berupa daftar.',
    'boolean' => ':Attribute harus benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_format' => ':Attribute harus berformat :format.',
    'after_or_equal' => ':Attribute harus tanggal setelah atau sama dengan :date.',
    'before_or_equal' => ':Attribute harus tanggal sebelum atau sama dengan :date.',
    'digits' => ':Attribute harus :digits digit.',
    'distinct' => ':Attribute memiliki nilai ganda.',
    'email' => ':Attribute harus alamat email yang valid.',
    'enum' => ':Attribute yang dipilih tidak valid.',
    'exists' => ':Attribute yang dipilih tidak valid.',
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus bilangan bulat.',
    'lowercase' => ':Attribute harus huruf kecil.',
    'max' => [
        'array' => ':Attribute maksimal berisi :max item.',
        'numeric' => ':Attribute maksimal :max.',
        'string' => ':Attribute maksimal :max karakter.',
    ],
    'min' => [
        'array' => ':Attribute minimal berisi :min item.',
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
    ],
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus mengandung minimal satu huruf.',
        'mixed' => ':Attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':Attribute harus mengandung minimal satu angka.',
        'symbols' => ':Attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor di internet. Pilih :attribute lain.',
    ],
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi bila :other adalah :value.',
    'same' => ':Attribute dan :other harus sama.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah dipakai.',

    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'current_password' => 'kata sandi saat ini',
        'code' => 'kode',
        'recovery_code' => 'kode pemulihan',
    ],
];
