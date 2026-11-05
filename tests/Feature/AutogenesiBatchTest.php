<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutogenesiBatch;
use App\Models\AutogenesiBatch;
use App\Models\AutogenesiProduct;
use App\Models\User;
use App\Services\AutogenesiExport;
use App\Services\AutogenesiImport;
use App\Services\AutogenesiList;
use App\Services\AutogenesiRunControl;
use App\Services\AutogenesiSearch;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AutogenesiBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    private function batch(string $text = "Hyundai Kona 2017 2020\nFord Focus 2011 2018"): AutogenesiBatch
    {
        $batch = app(AutogenesiList::class)->create($text, User::factory()->create(['is_admin' => true])->id);
        Queue::fake();

        return $batch;
    }

    public function test_list_is_validated_deduplicated_and_queued_without_starting_browser(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/batches', ['list' => "Hyundai Kona 2017 2020\nradio hyundai kona 2017-2020\nFord Focus 2011 2018"])->assertRedirect()->assertSessionHasNoErrors();
        $batch = AutogenesiBatch::firstOrFail();
        $this->assertSame(2, $batch->total);
        $this->assertSame('queued', $batch->status);
        $this->assertSame(['radio Hyundai Kona 2017 2020', 'radio Ford Focus 2011 2018'], $batch->items()->pluck('query')->all());
        Queue::assertPushedOn('autogenesi', ProcessAutogenesiBatch::class);
        Queue::assertPushed(ProcessAutogenesiBatch::class, fn ($job) => $job->connection === 'autogenesi' && $job->batchId === $batch->id
            && $job->timeout < config('queue.connections.autogenesi.retry_after'));
        $this->get('/autogenesi')->assertInertia(fn (Assert $page) => $page->where('batches.0.total', 2)->has('batches.0.items', 2));
        $this->assertDatabaseCount('autogenesi_products', 0);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_csv_and_multiword_brands_are_supported(): void
    {
        $batch = $this->batch("\xEF\xBB\xBFMarca;Modello;Dal;Al\nLand Rover;Discovery;2010;2016\nPeugeot;2008;2013;2019");
        $this->assertSame('Land Rover', $batch->items[0]->vehicle['brand']);
        $this->assertSame('Discovery', $batch->items[0]->vehicle['model']);
        $this->assertSame('2008', $batch->items[1]->vehicle['model']);
        $this->assertSame(2019, $batch->items[1]->vehicle['year_to']);
    }

    public function test_invalid_row_rejects_whole_list_without_partial_queueing(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/batches', ['list' => "Hyundai Kona 2017\nFord Focus 2024 2023"])->assertSessionHasErrors('list');
        $this->assertDatabaseCount('autogenesi_batches', 0);
        $this->assertDatabaseCount('autogenesi_batch_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_list_requires_local_admin_access(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/autogenesi/batches', ['list' => 'Hyundai Kona 2017'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->post('/autogenesi/batches', ['list' => 'Hyundai Kona 2017'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_over_100_vehicles_are_rejected_before_queueing(): void
    {
        $rows = array_map(fn ($i) => 'Ford Focus'.$i.' 2010', range(1, 101));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/batches', ['list' => implode("\n", $rows)])->assertSessionHasErrors('list');
        $this->assertDatabaseCount('autogenesi_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_searches_best_price_imports_draft_and_then_handles_next_vehicle(): void
    {
        $batch = $this->batch();
        $p = AutogenesiProduct::create(['source_id' => '123', 'source_url' => 'https://es.aliexpress.com/item/123.html', 'handle' => 'kona-test',
            'brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020, 'status' => 'draft',
            'title' => 'Bozza', 'body_html' => '<p>Radio</p>', 'variants' => [], 'images' => [], 'metadata' => []]);
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->with('radio Hyundai Kona 2017 2020', 'best_price')->andReturn(['selected' => ['source_url' => $p->source_url]]);
        $search->shouldReceive('search')->once()->with('radio Ford Focus 2011 2018', 'best_price')->andReturn(['selected' => null]);
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldReceive('capture')->once()->with($p->source_url, $batch->items[0]->vehicle)->andReturn($p);
        $exports = $this->mock(AutogenesiExport::class);
        $exports->shouldReceive('writeFiles')->once()->with($p)->andReturn([]);
        $job = new ProcessAutogenesiBatch($batch->id);
        $job->handle($search, $importer, $exports);
        $this->assertSame('completed', $batch->items()->first()->status);
        $this->assertSame($p->id, $batch->items()->first()->product_id);
        $this->assertSame('queued', $batch->items()->skip(1)->first()->status);
        Queue::assertPushed(ProcessAutogenesiBatch::class, 1);
        $job->handle($search, $importer, $exports);
        $this->assertSame('not_found', $batch->items()->skip(1)->first()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_captcha_pauses_list_without_starting_other_rows(): void
    {
        $batch = $this->batch();
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->andThrow(new RuntimeException('Verification required; automation stopped'));
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldNotReceive('capture');
        $exports = $this->mock(AutogenesiExport::class);
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $exports);
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertSame(['blocked', 'queued'], $batch->items()->pluck('status')->all());
        Queue::assertNothingPushed();
    }

    public function test_unusual_traffic_stops_the_list_without_treating_it_as_a_captcha(): void
    {
        $batch = $this->batch();
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->andThrow(new RuntimeException('Network traffic blocked; automation stopped'));
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldNotReceive('capture');
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $this->mock(AutogenesiExport::class));
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertSame(['blocked', 'queued'], $batch->items()->pluck('status')->all());
        $this->assertStringContainsString('traffico insolito', $batch->items()->first()->error);
        Queue::assertNothingPushed();
    }

    public function test_import_error_is_recorded_and_next_row_is_scheduled(): void
    {
        $batch = $this->batch();
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->andReturn(['selected' => ['source_url' => 'https://es.aliexpress.com/item/123.html']]);
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldReceive('capture')->once()->andThrow(new RuntimeException('Provider unavailable'));
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $this->mock(AutogenesiExport::class));
        $this->assertSame(['failed', 'queued'], $batch->items()->pluck('status')->all());
        $this->assertSame('https://es.aliexpress.com/item/123.html', $batch->items()->first()->source_url);
        Queue::assertPushed(ProcessAutogenesiBatch::class, 1);
    }

    public function test_retry_preserves_completed_rows(): void
    {
        $batch = $this->batch();
        $batch->items()->first()->update(['status' => 'completed']);
        $batch->items()->skip(1)->first()->update(['status' => 'blocked', 'error' => 'Captcha']);
        $batch->update(['status' => 'blocked']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/batches/'.$batch->id.'/retry')->assertRedirect();
        $this->assertSame(['completed', 'queued'], $batch->items()->pluck('status')->all());
        $this->assertSame('queued', $batch->fresh()->status);
        Queue::assertPushed(ProcessAutogenesiBatch::class, 1);
    }

    public function test_timeout_marks_active_row_failed_and_schedules_remaining_work(): void
    {
        $batch = $this->batch();
        $batch->update(['status' => 'running']);
        $batch->items()->first()->update(['status' => 'importing']);
        (new ProcessAutogenesiBatch($batch->id))->failed(new RuntimeException('timeout'));
        $this->assertSame(['failed', 'queued'], $batch->items()->pluck('status')->all());
        Queue::assertPushed(ProcessAutogenesiBatch::class, 1);
    }

    public function test_single_request_queues_only_selected_row_and_refuses_duplicate_launches(): void
    {
        $batch = $this->batch();
        $first = $batch->items()->first();
        $item = $batch->items()->skip(1)->first();
        $batch->update(['status' => 'blocked']);
        $item->update(['status' => 'failed', 'error' => 'Errore precedente']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = '/autogenesi/batches/'.$batch->id.'/items/'.$item->id.'/retry';
        $this->post($url)->assertRedirect();
        $this->assertSame('queued_single', $batch->fresh()->status);
        $this->assertSame('queued', $first->fresh()->status);
        $this->assertNull($item->fresh()->error);
        Queue::assertPushed(ProcessAutogenesiBatch::class, fn ($job) => $job->itemId === $item->id);
        $this->post($url)->assertStatus(409);
        Queue::assertPushed(ProcessAutogenesiBatch::class, 1);
    }

    public function test_single_request_stops_after_selected_row_and_stale_automatic_jobs_do_nothing(): void
    {
        $batch = $this->batch();
        $item = $batch->items()->skip(1)->first();
        $batch->update(['status' => 'queued_single']);
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->with($item->query, 'best_price')->andReturn(['selected' => null]);
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldNotReceive('capture');
        $exporter = $this->mock(AutogenesiExport::class);
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $exporter);
        (new ProcessAutogenesiBatch($batch->id, $item->id))->handle($search, $importer, $exporter);
        $this->assertSame(['queued', 'not_found'], $batch->items()->pluck('status')->all());
        $this->assertSame('paused', $batch->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_single_request_rejects_completed_rows_and_rows_of_another_batch(): void
    {
        $batch = $this->batch();
        $batch->update(['status' => 'paused']);
        $item = $batch->items()->first();
        $item->update(['status' => 'completed']);
        $other = $this->batch('Peugeot 2008 2013 2019');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/batches/'.$batch->id.'/items/'.$item->id.'/retry')->assertStatus(409);
        $this->post('/autogenesi/batches/'.$batch->id.'/items/'.$other->items()->first()->id.'/retry')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_single_request_timeout_does_not_schedule_other_rows(): void
    {
        $batch = $this->batch();
        $item = $batch->items()->skip(1)->first();
        $batch->update(['status' => 'running_single']);
        $item->update(['status' => 'importing']);
        (new ProcessAutogenesiBatch($batch->id, $item->id))->failed(new RuntimeException('timeout'));
        $this->assertSame(['queued', 'failed'], $batch->items()->pluck('status')->all());
        $this->assertSame('paused', $batch->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_stop_clears_only_autogenesi_queue_preserves_completed_rows_and_closes_browser(): void
    {
        $batch = $this->batch();
        $batch->update(['status' => 'running']);
        $batch->items()->first()->update(['status' => 'completed']);
        $batch->items()->skip(1)->first()->update(['status' => 'searching']);
        foreach (['default', 'autogenesi'] as $queue) {
            DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        }
        Process::fake(fn () => Process::result());
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/autogenesi/stop')->assertOk()->assertJson(['stopped' => true]);
        $this->assertSame('stopped', $batch->fresh()->status);
        $this->assertSame(['completed', 'stopped'], $batch->items()->pluck('status')->all());
        $this->assertDatabaseMissing('jobs', ['queue' => 'autogenesi']);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        $this->assertTrue(app(AutogenesiRunControl::class)->state()['stopped']);
        Process::assertRan(fn ($p) => str_ends_with($p->command[1], '/scripts/autogenesi/stop-browser.cjs'));
        Queue::assertNothingPushed();
    }

    public function test_stop_during_search_prevents_import_and_any_following_job(): void
    {
        $batch = $this->batch();
        $search = $this->mock(AutogenesiSearch::class);
        $search->shouldReceive('search')->once()->andReturnUsing(function () use ($batch) {
            $batch->update(['status' => 'stopped']);
            $batch->items()->update(['status' => 'stopped']);

            return ['selected' => ['source_url' => 'https://es.aliexpress.com/item/123.html']];
        });
        $importer = $this->mock(AutogenesiImport::class);
        $importer->shouldNotReceive('capture');
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $this->mock(AutogenesiExport::class));
        $this->assertSame(['stopped', 'stopped'], $batch->items()->pluck('status')->all());
        Queue::assertNothingPushed();
    }

    public function test_old_run_remains_cancelled_after_user_explicitly_starts_another(): void
    {
        $control = app(AutogenesiRunControl::class);
        $control->activate();
        $old = $control->state()['run_id'];
        $control->stop();
        $control->activate();
        $control->assertActive($control->state()['run_id']);
        $this->expectExceptionMessage('Ricerca interrotta');
        $control->assertActive($old);
    }

    public function test_stop_requires_local_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/autogenesi/stop')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->post('/autogenesi/stop')->assertForbidden();
        Process::assertNothingRan();
    }

    public function test_browser_verification_opens_blocked_search_without_resuming_queue(): void
    {
        $batch = $this->batch();
        $batch->items()->first()->update(['status' => 'blocked']);
        $batch->update(['status' => 'blocked']);
        Process::fake(function ($process) {
            $args = $process->command;
            $this->assertStringEndsWith('/scripts/autogenesi/open-browser.cjs', $args[1]);
            parse_str(parse_url($args[2], PHP_URL_QUERY), $query);
            $this->assertSame('price_asc', $query['sortType']);
            $this->assertSame('radio Hyundai Kona 2017 2020', $query['SearchText']);

            return Process::result('Browser aperto');
        });
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/browser')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertSame(['blocked', 'queued'], $batch->items()->pluck('status')->all());
        Queue::assertNothingPushed();
    }
    public function test_history_rows_can_be_deleted_and_completed_rows_cleared_without_removing_other_rows(): void
    {
        $batch = $this->batch();
        $batch->update(['status' => 'stopped']);
        $items = $batch->items;
        $items[0]->update(['status' => 'completed']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/batches/'.$batch->id.'/completed')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $batch->fresh()->total);
        $this->assertDatabaseHas('autogenesi_batch_items', ['id' => $items[1]->id]);
        $this->delete('/autogenesi/batches/'.$batch->id.'/items/'.$items[1]->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('autogenesi_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('autogenesi_batch_items', 0);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_history_deletion_rejects_active_lists_foreign_rows_and_non_admins(): void
    {
        $batch = $this->batch();
        $other = $this->batch('Kia Stonic 2020');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/batches/'.$batch->id.'/items/'.$batch->items->first()->id)->assertStatus(409);
        $batch->update(['status' => 'stopped']);
        $this->delete('/autogenesi/batches/'.$batch->id.'/items/'.$other->items->first()->id)->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->delete('/autogenesi/batches/'.$batch->id.'/completed')->assertForbidden();
        $this->assertDatabaseCount('autogenesi_batch_items', 3);
    }

}
