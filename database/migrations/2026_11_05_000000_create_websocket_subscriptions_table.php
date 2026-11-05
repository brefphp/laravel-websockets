<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bref-websockets.database.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Channels and connection IDs are case-sensitive, MySQL's default collations are not
        $caseSensitive = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        $schema->create(config('bref-websockets.database.table'), function (Blueprint $table) use ($caseSensitive) {
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
        Schema::connection($this->getConnection())->dropIfExists(config('bref-websockets.database.table'));
    }
};
