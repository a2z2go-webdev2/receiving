<?php

namespace App\Console\Commands;

use App\Features\Receiving\Services\ProductionReadinessCheck;
use Illuminate\Console\Command;

class CheckProductionReadiness extends Command
{
    protected $signature = 'receiving:check-production {--json : Emit machine-readable JSON}';

    protected $description = 'Fail when release-critical receiving production configuration is unsafe.';

    public function handle(ProductionReadinessCheck $check): int
    {
        $results = $check->evaluate();
        $failed = array_values(array_filter($results, fn (array $result): bool => ! $result['passed']));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $failed === [],
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Check', 'Status', 'Details'],
                array_map(fn (array $result): array => [
                    $result['name'],
                    $result['passed'] ? 'PASS' : 'FAIL',
                    $result['message'],
                ], $results),
            );
        }

        if ($failed !== []) {
            $this->error(count($failed).' production readiness check(s) failed.');

            return self::FAILURE;
        }

        $this->info('Production configuration checks passed. Run provider connectivity smoke tests before enabling traffic.');

        return self::SUCCESS;
    }
}
