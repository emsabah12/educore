<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table): void {
            $table->uuid('plan_id');
            $table->uuid('feature_id');

            $table->primary(['plan_id', 'feature_id']);

            // Composite PK sudah meng-index plan_id sebagai leading
            // column; feature_id butuh reverse lookup index sendiri.
            $table->index('feature_id');

            $table->foreign('plan_id', 'fk_plan_features_plan')
                ->references('id')
                ->on('subscription_plans')
                ->cascadeOnDelete();

            $table->foreign('feature_id', 'fk_plan_features_feature')
                ->references('id')
                ->on('subscription_features')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
