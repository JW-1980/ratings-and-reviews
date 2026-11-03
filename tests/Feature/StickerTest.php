<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Tests\Fixtures\User;
use RatingsAndReviews\Tests\TestCase;

class StickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        $admin = $this->user(['name' => 'Admin']);
        Gate::define(Ability::MANAGE_SETTINGS, fn (User $user): bool => $user->id === $admin->id);

        return $admin;
    }

    public function test_an_admin_creates_lists_updates_and_deletes_stickers(): void
    {
        $admin = $this->admin();

        $uploaded = $this->actingAs($admin)->post('/rr/admin/stickers', [
            'image' => UploadedFile::fake()->image('happy.png', 128, 128),
            'name' => ['nl' => 'Blij', 'en' => 'Happy'],
            'sort_order' => 2,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name.nl', 'Blij')
            ->assertJsonPath('data.label', 'Happy')
            ->assertJsonPath('data.managed_file', true)
            ->assertJsonPath('data.is_active', true)
            ->json('data');
        $this->assertStringStartsWith('rr-stickers/', $uploaded['path']);
        $this->assertSame('/storage/'.$uploaded['path'], $uploaded['url']);
        Storage::disk('public')->assertExists($uploaded['path']);

        $hosted = $this->actingAs($admin)->postJson('/rr/admin/stickers', ['path' => 'stickers/wink.gif', 'name' => ['nl' => 'Knipoog', 'en' => 'Wink'], 'sort_order' => 1])
            ->assertCreated()->assertJsonPath('data.managed_file', false)->json('data');

        $this->actingAs($admin)->getJson('/rr/admin/stickers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $hosted['id']);

        $this->actingAs($admin)->patchJson("/rr/admin/stickers/{$hosted['id']}", ['is_active' => false, 'name' => ['nl' => 'Oogje', 'en' => 'Wink']])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name.nl', 'Oogje');

        // The editor only gets the active ones, named in the current locale.
        $this->app->setLocale('nl');
        $this->getJson('/rr/stickers')->assertOk()->assertExactJson(['data' => [['id' => $uploaded['id'], 'name' => 'Blij', 'url' => $uploaded['url']]]]);

        // Deleting keeps the file (old posts still show it) unless asked.
        $this->actingAs($admin)->deleteJson("/rr/admin/stickers/{$uploaded['id']}")->assertOk()->assertJsonPath('data.file_deleted', false);
        Storage::disk('public')->assertExists($uploaded['path']);
        $this->assertSame([$hosted['id']], Sticker::query()->pluck('id')->all());

        $this->actingAs($admin)->deleteJson("/rr/admin/stickers/{$hosted['id']}?delete_file=1")->assertOk()->assertJsonPath('data.file_deleted', false);
        $this->actingAs($admin)->deleteJson("/rr/admin/stickers/{$hosted['id']}")->assertNotFound();
    }

    public function test_deleting_a_managed_sticker_file_on_request(): void
    {
        $admin = $this->admin();
        $data = $this->actingAs($admin)->post('/rr/admin/stickers', ['image' => UploadedFile::fake()->image('a.png', 64, 64), 'name' => ['nl' => 'A', 'en' => 'A']], ['Accept' => 'application/json'])->json('data');

        $this->actingAs($admin)->deleteJson("/rr/admin/stickers/{$data['id']}", ['delete_file' => true])->assertOk()->assertJsonPath('data.file_deleted', true);
        Storage::disk('public')->assertMissing($data['path']);
    }

    public function test_validation_of_new_stickers(): void
    {
        $admin = $this->admin();
        Sticker::query()->create(['name' => ['nl' => 'X', 'en' => 'X'], 'path' => 'stickers/x.png']);
        $post = fn (array $data) => $this->actingAs($admin)->post('/rr/admin/stickers', $data, ['Accept' => 'application/json']);
        $name = ['nl' => 'Naam', 'en' => 'Name'];

        $post(['name' => $name])->assertUnprocessable()->assertJsonValidationErrors(['image', 'path']);
        $post(['name' => $name, 'path' => '../etc/passwd'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $post(['name' => $name, 'path' => 'stickers/x.png'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $post(['name' => ['nl' => 'Alleen nl'], 'path' => 'stickers/y.png'])->assertUnprocessable()->assertJsonValidationErrors('name.en');
        $post(['name' => $name, 'image' => UploadedFile::fake()->image('huge.png', 2000, 100)])->assertUnprocessable()->assertJsonValidationErrors('image');
        $post(['name' => $name, 'image' => UploadedFile::fake()->create('a.pdf', 3, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(1, Sticker::query()->count());
    }

    public function test_only_managers_of_settings_may_change_stickers(): void
    {
        $this->admin();
        $member = $this->user();
        $sticker = Sticker::query()->create(['name' => ['nl' => 'X', 'en' => 'X'], 'path' => 'stickers/x.png']);

        $this->getJson('/rr/admin/stickers')->assertUnauthorized();
        $this->actingAs($member)->getJson('/rr/admin/stickers')->assertForbidden();
        $this->actingAs($member)->postJson('/rr/admin/stickers', ['path' => 'a.png', 'name' => ['nl' => 'a', 'en' => 'a']])->assertForbidden();
        $this->actingAs($member)->patchJson("/rr/admin/stickers/{$sticker->id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($member)->deleteJson("/rr/admin/stickers/{$sticker->id}")->assertForbidden();
        $this->assertTrue($sticker->fresh()->is_active);
    }

    public function test_an_active_sticker_survives_the_sanitizer_and_an_inactive_one_does_not(): void
    {
        $this->configureSection('product', ['editor_tools' => ['sticker']]);
        $admin = $this->admin();
        $sticker = $this->actingAs($admin)->postJson('/rr/admin/stickers', ['path' => 'stickers/ok.png', 'name' => ['nl' => 'Ok', 'en' => 'Ok']])->json('data');
        $product = $this->product();
        $post = fn () => $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Top<img src="'.$sticker['url'].'" alt="Ok"></p>'])->json('data.body_html');

        $this->assertStringContainsString('src="/storage/stickers/ok.png"', $post());

        $this->actingAs($admin)->patchJson("/rr/admin/stickers/{$sticker['id']}", ['is_active' => false])->assertOk();
        $this->assertStringNotContainsString('<img', $post());
    }
}
