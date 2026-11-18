<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_sends_everyone_to_the_generate_form(): void
    {
        $this->get(route('home'))->assertRedirect(route('generations.create'));

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('generations.create'));
    }

    public function test_a_guest_who_follows_the_redirect_ends_up_at_login(): void
    {
        $this->followingRedirects()
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }
}
