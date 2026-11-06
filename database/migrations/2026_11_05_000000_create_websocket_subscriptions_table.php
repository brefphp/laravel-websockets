<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channels and connection IDs are case-sensitive, MySQL's default collations are not
        $caseSensitive = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('websocket_subscriptions', function (Blueprint $table) use ($caseSensitive) {
            $channel = $table->string('channel', 191);
            $connectionId = $table->string('connection_id', 64);

            if ($caseSensitive) {
                $channel->collation('utf8mb4_bin');
                $connectionId->collation('utf8mb4_bin');
            }

            $table->text('member')->nullable();
            $table->unsignedInteger('expires_at')->index();

            $table->primary(['channel', 'connection_id']);
            $table->index('connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websocket_subscriptions');
    }
};
