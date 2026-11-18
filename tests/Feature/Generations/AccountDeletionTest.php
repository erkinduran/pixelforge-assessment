<?php

namespace Tests\Feature\Generations;

use App\Generations\ApplyPixelforgeGeneration;
use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use PixelforgeObjects;
    use RefreshDatabase;

    public function test_deleting_an_account_keeps_the_generation_and_its_charge_with_user_id_null(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);
        (new ApplyPixelforgeGeneration)->apply($this->succeededObject($generation));
        $chargeBefore = Charge::firstOrFail();
        $this->assertSame($user->id, $chargeBefore->user_id);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password']);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));
        $this->assertNull($user->fresh());

        $generation = $generation->fresh();
        $this->assertNotNull($generation);
        $this->assertNull($generation->user_id);
        $this->assertSame('succeeded', $generation->status);

        $this->assertSame(1, Charge::count());
        $charge = Charge::firstOrFail();
        $this->assertNull($charge->user_id);
        $this->assertSame($chargeBefore->id, $charge->id);
        $this->assertSame(305_510_000_000, $charge->provider_cost_pico);
        $this->assertSame(381_887_500_000, $charge->user_price_pico);
    }

    public function test_a_generation_that_has_a_charge_cannot_be_deleted(): void
    {
        $generation = Generation::factory()->create();
        (new ApplyPixelforgeGeneration)->apply($this->succeededObject($generation));

        $this->assertThrows(
            fn () => DB::transaction(fn () => $generation->delete()),
            QueryException::class,
        );
        $this->assertNotNull($generation->fresh());
    }

    public function test_generations_are_created_through_the_user_relation(): void
    {
        $user = User::factory()->create();

        $generation = $user->generations()->create([
            'prompt' => 'A red kite',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_urls' => [],
            'estimate_min_pico' => 1,
            'estimate_max_pico' => 2,
        ]);

        $this->assertSame($user->id, $generation->fresh()->user_id);
        $this->assertSame('queued', $generation->fresh()->status);
    }

    public function test_status_and_money_columns_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $generation = $user->generations()->create([
            'prompt' => 'A red kite',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_urls' => [],
            'estimate_min_pico' => 1,
            'estimate_max_pico' => 2,
            'status' => 'succeeded',
            'rate_text' => 999,
            'pixelforge_id' => 'gen_hacked',
        ]);

        $generation = $generation->fresh();
        $this->assertSame('queued', $generation->status);
        $this->assertNull($generation->rate_text);
        $this->assertNull($generation->pixelforge_id);
    }
}
