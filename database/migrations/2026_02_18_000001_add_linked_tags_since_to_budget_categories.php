<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->timestamp('linked_tags_since')->nullable()->after('linked_tags');
        });
    }

    public function down(): void
    {
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->dropColumn('linked_tags_since');
        });
    }
};
