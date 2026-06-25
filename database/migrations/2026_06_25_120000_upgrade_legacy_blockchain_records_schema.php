<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade prototype production `blockchain_records` tables to the M2+ schema
     * without dropping or rewriting existing rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('blockchain_records')) {
            return;
        }

        $this->upgradeHashColumn();
        $this->upgradeErrorColumn();
        $this->addMissingColumns();
        $this->upgradeEnvironmentColumn();
        $this->upgradeStatusColumn();
        $this->normalizeLegacyRows();
        $this->addMissingIndexes();
    }

    /**
     * Conservative down: this migration is a production compatibility upgrade.
     * Reverting enum/column renames could destroy or invalidate live data.
     */
    public function down(): void
    {
        // Intentionally no-op. See migration header comment in deployment docs.
    }

    private function upgradeHashColumn(): void
    {
        if (Schema::hasColumn('blockchain_records', 'hash')
            && ! Schema::hasColumn('blockchain_records', 'record_hash')) {
            $this->renameColumn('blockchain_records', 'hash', 'record_hash');

            return;
        }

        if (Schema::hasColumn('blockchain_records', 'hash')
            && Schema::hasColumn('blockchain_records', 'record_hash')) {
            DB::table('blockchain_records')
                ->select(['id', 'hash', 'record_hash'])
                ->orderBy('id')
                ->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        if ($row->record_hash === null || $row->record_hash === '') {
                            DB::table('blockchain_records')
                                ->where('id', $row->id)
                                ->update(['record_hash' => $row->hash]);
                        }
                    }
                }, 'id');
        }
    }

    private function upgradeErrorColumn(): void
    {
        if (Schema::hasColumn('blockchain_records', 'error_message')
            && ! Schema::hasColumn('blockchain_records', 'last_error')) {
            $this->renameColumn('blockchain_records', 'error_message', 'last_error');

            return;
        }

        if (Schema::hasColumn('blockchain_records', 'error_message')
            && Schema::hasColumn('blockchain_records', 'last_error')) {
            DB::table('blockchain_records')
                ->select(['id', 'error_message', 'last_error'])
                ->orderBy('id')
                ->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        if (($row->last_error === null || $row->last_error === '')
                            && $row->error_message !== null
                            && $row->error_message !== '') {
                            DB::table('blockchain_records')
                                ->where('id', $row->id)
                                ->update(['last_error' => $row->error_message]);
                        }
                    }
                }, 'id');
        }
    }

    private function addMissingColumns(): void
    {
        Schema::table('blockchain_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('blockchain_records', 'proof_type')) {
                $table->string('proof_type', 100)->default('legacy');
            }

            if (! Schema::hasColumn('blockchain_records', 'canonical_version')) {
                $table->string('canonical_version', 20)->default('v1');
            }

            if (! Schema::hasColumn('blockchain_records', 'hash_algorithm')) {
                $table->string('hash_algorithm', 20)->default('sha256');
            }

            if (! Schema::hasColumn('blockchain_records', 'record_hash')) {
                $table->char('record_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('blockchain_records', 'payload_summary')) {
                $table->json('payload_summary')->nullable();
            }

            if (! Schema::hasColumn('blockchain_records', 'chain_id')) {
                $table->unsignedBigInteger('chain_id')->nullable();
            }

            if (! Schema::hasColumn('blockchain_records', 'contract_address')) {
                $table->string('contract_address', 255)->nullable();
            }

            if (! Schema::hasColumn('blockchain_records', 'confirmations')) {
                $table->unsignedInteger('confirmations')->default(0);
            }

            if (! Schema::hasColumn('blockchain_records', 'last_error')) {
                $table->text('last_error')->nullable();
            }
        });
    }

    private function upgradeEnvironmentColumn(): void
    {
        if (! Schema::hasColumn('blockchain_records', 'environment')) {
            return;
        }

        if ($this->isMySql()) {
            DB::statement(
                "ALTER TABLE blockchain_records MODIFY environment ENUM('local','staging','production','development') NOT NULL"
            );
        }
    }

    private function upgradeStatusColumn(): void
    {
        if (! Schema::hasColumn('blockchain_records', 'status')) {
            return;
        }

        if ($this->isMySql()) {
            DB::statement(
                "ALTER TABLE blockchain_records MODIFY status ENUM('pending','queued','processing','submitted','confirmed','failed') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    private function normalizeLegacyRows(): void
    {
        if (Schema::hasColumn('blockchain_records', 'environment')) {
            DB::table('blockchain_records')
                ->where('environment', 'development')
                ->update(['environment' => 'local']);
        }

        if ($this->isMySql() && Schema::hasColumn('blockchain_records', 'environment')) {
            DB::statement(
                "ALTER TABLE blockchain_records MODIFY environment ENUM('local','staging','production') NOT NULL"
            );
        }

        if (Schema::hasColumn('blockchain_records', 'proof_type')) {
            DB::table('blockchain_records')
                ->whereNull('proof_type')
                ->update(['proof_type' => 'legacy']);
        }

        if (Schema::hasColumn('blockchain_records', 'canonical_version')) {
            DB::table('blockchain_records')
                ->whereNull('canonical_version')
                ->update(['canonical_version' => 'v1']);
        }

        if (Schema::hasColumn('blockchain_records', 'hash_algorithm')) {
            DB::table('blockchain_records')
                ->whereNull('hash_algorithm')
                ->update(['hash_algorithm' => 'sha256']);
        }

        if (Schema::hasColumn('blockchain_records', 'confirmations')) {
            DB::table('blockchain_records')
                ->whereNull('confirmations')
                ->update(['confirmations' => 0]);
        }
    }

    private function addMissingIndexes(): void
    {
        Schema::table('blockchain_records', function (Blueprint $table): void {
            if (Schema::hasColumn('blockchain_records', 'entity_type')
                && Schema::hasColumn('blockchain_records', 'entity_id')
                && ! $this->hasIndexOnColumns('blockchain_records', ['entity_type', 'entity_id'])) {
                $table->index(['entity_type', 'entity_id']);
            }

            if (Schema::hasColumn('blockchain_records', 'proof_type')
                && ! $this->hasIndexOnColumns('blockchain_records', ['proof_type'])) {
                $table->index('proof_type');
            }

            if (Schema::hasColumn('blockchain_records', 'status')
                && ! $this->hasIndexOnColumns('blockchain_records', ['status'])) {
                $table->index('status');
            }

            if (Schema::hasColumn('blockchain_records', 'network')
                && Schema::hasColumn('blockchain_records', 'environment')
                && ! $this->hasIndexOnColumns('blockchain_records', ['network', 'environment'])) {
                $table->index(['network', 'environment']);
            }

            if (Schema::hasColumn('blockchain_records', 'tx_hash')
                && ! $this->hasIndexOnColumns('blockchain_records', ['tx_hash'])) {
                $table->index('tx_hash');
            }

            if (Schema::hasColumn('blockchain_records', 'record_hash')
                && ! $this->hasIndexOnColumns('blockchain_records', ['record_hash'])) {
                $table->index('record_hash');
            }
        });
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $definition = match ($to) {
                'record_hash' => 'CHAR(64) NOT NULL',
                'last_error' => 'TEXT NULL',
                default => null,
            };

            if ($definition !== null) {
                DB::statement("ALTER TABLE {$table} CHANGE `{$from}` `{$to}` {$definition}");
            }

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("ALTER TABLE {$table} RENAME COLUMN {$from} TO {$to}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($from, $to): void {
            $blueprint->renameColumn($from, $to);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
};
