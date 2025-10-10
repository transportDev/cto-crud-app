<?php

return [
    // Page title and navigation
    'title' => 'Pembuat Tabel',
    'navigation_label' => 'Pembuat Tabel',
    'description' => 'Buat tabel baru dengan panduan langkah demi langkah. Setiap perubahan akan diperiksa dan ditampilkan pratinjaunya sebelum disimpan.',

    // Wizard steps
    'steps' => [
        'table_info' => 'Info Tabel',
        'columns' => 'Kolom',
        'indexes_rules' => 'Indeks & Aturan',
        'preview_confirm' => 'Pratinjau & Konfirmasi',
    ],

    // Table info step
    'table_name' => 'Nama tabel',
    'table_name_helper' => 'Contoh nama yang benar: customer_orders',
    'table_name_validation' => 'Gunakan format snake_case dan mulai dengan huruf.',
    'table_name_invalid' => 'Nama tabel tidak valid atau sudah digunakan sistem.',
    'table_name_exists' => 'Tabel dengan nama ini sudah ada.',
    'timestamps' => 'Kolom waktu (timestamps)',
    'timestamps_helper' => 'Otomatis menambah kolom created_at dan updated_at',
    'soft_deletes' => 'Soft delete',
    'soft_deletes_helper' => 'Otomatis menambah kolom deleted_at untuk penghapusan sementara',
    'table_comment' => 'Catatan tabel',

    // Columns step
    'add_column' => 'Tambah Kolom',
    'column_name' => 'Nama kolom',
    'column_name_validation' => 'Gunakan format snake_case dan mulai dengan huruf.',
    'column_name_invalid' => 'Nama kolom tidak valid atau sudah digunakan sistem.',
    'column_type' => 'Tipe',
    'length' => 'Panjang',
    'length_helper' => 'Panjang maksimal karakter',
    'precision' => 'Presisi',
    'precision_helper' => 'Jumlah digit untuk tipe angka',
    'scale' => 'Skala',
    'scale_helper' => 'Digit di belakang koma',
    'unsigned' => 'Unsigned',
    'auto_increment' => 'Auto increment',
    'primary_key' => 'Kunci utama',
    'nullable' => 'Dapat kosong',
    'unique' => 'Unik',
    'index' => 'Indeks',
    'default_value' => 'Nilai bawaan (string/number/date)',
    'default_boolean' => 'Nilai bawaan (boolean)',
    'default_placeholder_select' => 'Pilih nilai bawaan',
    'default_placeholder_text' => 'Masukkan nilai bawaan',
    'default_helper_datetime' => 'Pilih konstanta waktu yang umum digunakan atau kosongkan',
    'default_helper_uuid' => 'Pilih fungsi generator atau kosongkan',
    'default_helper_text' => 'Masukkan nilai bawaan atau kosongkan',
    'enum_options' => 'Opsi',
    'enum_options_helper' => 'Daftar nilai yang diizinkan untuk enum/set',
    'enum_options_placeholder' => 'Ketik opsi lalu tekan Enter',
    'references_table' => 'Tabel referensi',
    'references_column' => 'Kolom referensi',
    'on_update' => 'Aksi saat update',
    'on_delete' => 'Aksi saat hapus',
    'comment' => 'Catatan',

    // Foreign key actions
    'foreign_actions' => [
        'no_action' => 'tidak ada aksi',
        'cascade' => 'cascade',
        'restrict' => 'restrict',
        'set_null' => 'set null',
    ],

    // Index options
    'index_options' => [
        'none' => 'tidak ada',
        'index' => 'indeks',
        'fulltext' => 'fulltext',
    ],

    // Default value options
    'default_options' => [
        'none' => 'Tidak ada nilai bawaan',
    ],

    // Preview step
    'preview_title' => 'Pratinjau Tabel',
    'migration_code' => 'Kode Migrasi',
    'preview_helper' => 'Periksa hasil migrasi sebelum menyimpan perubahan.',
    'loading_preview' => 'Memuat pratinjau...',
    'no_columns' => 'Belum ada kolom yang ditambahkan',
    'no_columns_helper' => 'Harap tambahkan minimal satu kolom untuk melihat pratinjau.',
    'preview_error' => 'Tidak dapat menampilkan pratinjau',
    'preview_error_helper' => 'Periksa konfigurasi kolom Anda.',
    'try_again' => 'Coba Lagi',

    // Sample data for preview
    'sample_data' => [
        'text' => 'contoh teks',
        'boolean_true' => 'Ya',
        'boolean_false' => 'Tidak',
    ],

    // Column metadata badges
    'metadata' => [
        'primary_key' => 'Kunci Utama',
        'nullable' => 'Dapat Kosong',
        'default' => 'Default',
        'unique' => 'Unik',
        'auto_increment' => 'Auto Increment',
        'unsigned' => 'Unsigned',
        // From new content
        'indexed' => 'Indexed',
        'foreign_key' => 'Foreign Key',
    ],

    // Actions
    'actions' => [
        'preview' => 'Pratinjau',
        'reload_preview' => 'Muat Ulang Pratinjau',
        'create_table' => 'Buat Tabel',
        'next' => 'Lanjut',
        'back' => 'Kembali',
        'save' => 'Simpan',
        'remove' => 'Hapus',
        // From new content
        'reset_form' => 'Reset Form',
        'back_to_columns' => 'Kembali ke Kolom',
        'next_step' => 'Lanjut',
        'previous_step' => 'Sebelumnya',
    ],

    // Notifications (merged)
    'notifications' => [
        'duplicate_columns' => 'Nama kolom duplikat terdeteksi',
        'preview_generated' => 'Pratinjau berhasil dibuat',
        'preview_generated_body' => 'Tinjau pratinjau migrasi di langkah Pratinjau & Konfirmasi.',
        'table_created' => 'Tabel berhasil dibuat',
        'table_created_body' => 'Tabel :table telah berhasil dibuat.',
        // From new content
        'table_created_new' => 'Tabel Berhasil Dibuat',
        'table_created_body_new' => 'Tabel ":table" berhasil dibuat dalam database.',
        'table_creation_failed' => 'Gagal Membuat Tabel',
        'table_exists' => 'Tabel Sudah Ada',
        'table_exists_body' => 'Tabel ":table" sudah ada dalam database. Silakan gunakan nama yang berbeda.',
        'duplicate_columns_new' => 'Nama kolom tidak boleh sama',
        'preview_warning' => 'Peringatan Pratinjau',
    ],

    // Validation messages
    'validation' => [
        'enum_required' => 'Enum/Set memerlukan minimal satu opsi.',
        'foreign_table_required' => 'Foreign key harus mereferensikan tabel.',
        'duplicate_column_names' => 'Nama kolom duplikat terdeteksi.',
        'invalid_column_name' => 'Nama kolom tidak valid atau sudah digunakan sistem untuk :name.',
    ],

    // From new content
    'loading_create' => 'Membuat tabel...',
    'loading_reset' => 'Mereset form...',
    'columns_count' => 'kolom',
    'sample_data_notice' => 'Data di atas adalah contoh untuk pratinjau saja',
    'no_preview_available' => 'Pratinjau tidak tersedia. Klik tombol "Muat Ulang Pratinjau" untuk membuat pratinjau.',
    'form_reset_success' => 'Form berhasil direset',
    'wizard_reset_complete' => 'Wizard berhasil direset ke langkah pertama',
    'no_valid_columns' => 'Tidak ada kolom yang valid',
    'no_valid_columns_helper' => 'Pastikan setiap kolom memiliki nama dan tipe yang valid.',
    'table_creation_success' => 'Tabel berhasil dibuat! Form akan direset otomatis.',
    'migration_generated' => 'Kode migrasi berhasil dibuat',
    'create_table_confirm' => 'Apakah Anda yakin ingin membuat tabel ini? Tindakan ini tidak dapat dibatalkan.',
    'reset_form_confirm' => 'Apakah Anda yakin ingin mereset form ini? Semua data akan hilang.',

    // Status Messages
    'status' => [
        'ready_to_create' => 'Siap untuk membuat tabel',
        'validating' => 'Memvalidasi data...',
        'creating_table' => 'Membuat tabel di database...',
        'cleaning_up' => 'Membersihkan data...',
        'completed' => 'Selesai',
    ],

    // Help Text
    'help' => [
        'table_creation' => 'Setelah tabel dibuat, form akan otomatis direset ke langkah pertama.',
        'migration_code' => 'Kode ini menunjukkan bagaimana tabel akan dibuat dalam database.',
        'preview_data' => 'Data pratinjau hanya untuk visualisasi, bukan data sebenarnya.',
        'column_validation' => 'Pastikan semua nama kolom unik dan menggunakan format yang benar.',
    ],
];
