<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dynamic_buttons', function (Blueprint $table) {
            $table->id();
            $table->string('value'); // button label
            $table->string('slug')->unique(); // for set and get content
            $table->foreignId('dynamic_content_id')->constrained('dynamic_contents'); // content
            $table->enum('status', ['active', 'inactive', 'pending']); // status options
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_buttons');
    }
};
