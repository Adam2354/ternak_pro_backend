<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement(
            'ALTER TABLE personal_access_tokens
             MODIFY tokenable_id VARCHAR(36) NOT NULL'
        );
    }

    public function down()
    {
        // Jangan otomatis mengubah kembali ke BIGINT,
        // karena UUID tidak bisa dikonversi dengan aman.
        throw new RuntimeException(
            'Rollback manual diperlukan untuk perubahan tokenable_id.'
        );
    }
};