<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

// The other tests run inside one transaction, where two requests can never
// meet. Here several processes, each with its own connection, save at the
// same moment into a throwaway database next to the test database.
class ConcurrentChangesTest extends TestCase
{
    private const CONTENDERS = 6;

    private string $database;

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $main = (string) config('database.connections.pgsql.database');
        $this->assertStringEndsWith('_test', $main, 'Concurrency tests only run next to a test database.');

        $this->database = "{$main}_concurrency";
        DB::statement("DROP DATABASE IF EXISTS \"{$this->database}\" WITH (FORCE)");
        DB::statement("CREATE DATABASE \"{$this->database}\"");

        config(['database.connections.concurrency' => [...config('database.connections.pgsql'), 'database' => $this->database]]);
        $this->previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('concurrency');
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        DB::purge('concurrency');
        DB::statement("DROP DATABASE IF EXISTS \"{$this->database}\" WITH (FORCE)");

        parent::tearDown();
    }

    public function test_only_one_of_several_simultaneous_bookings_takes_the_slot(): void
    {
        $responsible = User::factory()->create();
        $service = Service::factory()->create();
        $clients = Client::factory()->count(self::CONTENDERS)->create();

        $results = $this->race(fn (int $turn): array => ['book', $responsible->id, $clients[$turn]->id, $service->id]);

        $this->assertSame(1, $results['saved'] ?? 0, json_encode($results));
        $this->assertSame(self::CONTENDERS - 1, $results['refused:409'] ?? 0, json_encode($results));
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_the_same_cancellation_from_several_people_is_recorded_once(): void
    {
        $appointment = Appointment::factory()->create();
        $team = User::factory()->count(self::CONTENDERS)->create();

        $results = $this->race(fn (int $turn): array => ['status', $team[$turn]->id, $appointment->id, AppointmentStatus::Cancelled->value]);

        $this->assertSame(1, $results['saved'] ?? 0, json_encode($results));
        $this->assertSame(self::CONTENDERS - 1, $results['refused:422'] ?? 0, json_encode($results));
        $this->assertSame(1, $appointment->activities()->count());
        $this->assertSame(['status' => 'scheduled'], $appointment->activities()->sole()->old_values);
    }

    /**
     * @param  callable(int): list<int|string>  $arguments
     * @return array<string, int>
     */
    private function race(callable $arguments): array
    {
        $startAt = (string) (microtime(true) + 2.5);
        $environment = ['APP_ENV' => 'testing', 'DB_DATABASE' => $this->database, 'DB_URL' => ''];

        $pool = Process::pool(function (Pool $pool) use ($arguments, $startAt, $environment): void {
            foreach (range(0, self::CONTENDERS - 1) as $turn) {
                $pool->path(base_path())
                    ->env($environment)
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Concurrency/contender.php', $startAt, ...array_map('strval', $arguments($turn))]);
            }
        })->start()->wait();

        return collect($pool)->map(fn ($result): string => trim($result->output()) ?: 'error: '.$result->errorOutput())->countBy()->all();
    }
}
