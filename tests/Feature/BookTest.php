<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_only_lists_the_authenticated_users_books(): void
    {
        Book::factory(3)->for($this->user)->create();
        Book::factory(2)->create();

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_can_search_sort_and_paginate(): void
    {
        Book::factory()->for($this->user)->create(['name' => 'Clean Code', 'value' => 50]);
        Book::factory()->for($this->user)->create(['name' => 'Clean Architecture', 'value' => 30]);
        Book::factory()->for($this->user)->create(['name' => 'Refactoring', 'value' => 70]);

        $this->getJson('/api/v1/books?search=Clean&sort=value&direction=asc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Clean Architecture')
            ->assertJsonPath('data.1.name', 'Clean Code');

        $this->getJson('/api/v1/books?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_search_treats_wildcard_characters_literally(): void
    {
        Book::factory()->for($this->user)->create(['name' => '100% Python']);
        Book::factory()->for($this->user)->create(['name' => 'snake_case guide']);
        Book::factory()->for($this->user)->create(['name' => 'Hello!']);
        Book::factory()->for($this->user)->create(['name' => 'Plain title']);

        $this->getJson('/api/v1/books?search=%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Python');
        $this->getJson('/api/v1/books?search=_')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'snake_case guide');
        $this->getJson('/api/v1/books?search=!')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Hello!');
    }

    #[DataProvider('invalidListingParameters')]
    public function test_index_rejects_invalid_parameters(string $query): void
    {
        $this->getJson('/api/v1/books?'.$query)->assertUnprocessable();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidListingParameters(): array
    {
        return [
            'unknown sort column' => ['sort=password'],
            'bad direction' => ['direction=sideways'],
            'per_page above the cap' => ['per_page=101'],
            'per_page zero' => ['per_page=0'],
            'unknown pagination mode' => ['pagination=infinite'],
        ];
    }

    public function test_pagination_links_keep_the_active_filters(): void
    {
        Book::factory(3)->for($this->user)->create(['name' => 'Clean Code']);

        $next = $this->getJson('/api/v1/books?search=Clean&sort=name&per_page=1')->json('links.next');

        $this->assertStringContainsString('search=Clean', $next);
        $this->assertStringContainsString('sort=name', $next);
        $this->assertStringContainsString('per_page=1', $next);
    }

    public function test_cursor_pagination_walks_every_book_once_in_listing_order(): void
    {
        $books = collect([3, 1, 1, 2, 0])->map(
            fn (int $daysAgo) => Book::factory()->for($this->user)->create(['created_at' => now()->subDays($daysAgo)->startOfSecond()]),
        );
        $expected = $books->sortBy([['created_at', 'desc'], ['id', 'desc']])->pluck('id')->all();

        $seen = [];
        $url = '/api/v1/books?pagination=cursor&per_page=2';

        while ($url) {
            $page = $this->getJson($url)->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $url = $page->json('links.next');
        }

        $this->assertSame($expected, $seen);
    }

    public function test_cursor_pagination_only_exposes_the_authenticated_users_books(): void
    {
        Book::factory(2)->for($this->user)->create();
        Book::factory(3)->create();

        $this->getJson('/api/v1/books?pagination=cursor')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_book_can_be_created(): void
    {
        $this->postJson('/api/v1/books', [
            'name' => 'Clean Code',
            'isbn' => '9780132350884',
            'value' => 49.9,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Clean Code')
            ->assertJsonPath('data.isbn', '9780132350884')
            ->assertJsonPath('data.value', '49.90');

        $this->assertDatabaseHas('books', ['user_id' => $this->user->id, 'name' => 'Clean Code']);
    }

    public function test_only_the_name_is_required(): void
    {
        $this->postJson('/api/v1/books', ['name' => 'Untitled'])
            ->assertCreated()
            ->assertJsonPath('data.isbn', null)
            ->assertJsonPath('data.value', null);
    }

    public function test_an_isbn_with_a_leading_zero_is_preserved(): void
    {
        $this->postJson('/api/v1/books', ['name' => 'Old Book', 'isbn' => '0123456789'])
            ->assertCreated()
            ->assertJsonPath('data.isbn', '0123456789');
    }

    public function test_the_owner_cannot_be_set_through_the_payload(): void
    {
        $other = User::factory()->create();

        $this->postJson('/api/v1/books', ['name' => 'Mine', 'user_id' => $other->id])->assertCreated();

        $this->assertDatabaseHas('books', ['name' => 'Mine', 'user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidBookPayloads')]
    public function test_creation_is_validated(array $payload, string $invalidField): void
    {
        $this->postJson('/api/v1/books', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBookPayloads(): array
    {
        return [
            'missing name' => [['isbn' => '9780132350884'], 'name'],
            'empty name' => [['name' => ''], 'name'],
            'isbn with letters' => [['name' => 'X', 'isbn' => '97801323508AB'], 'isbn'],
            'isbn with hyphens' => [['name' => 'X', 'isbn' => '978-0-13-235088-4'], 'isbn'],
            'isbn with wrong length' => [['name' => 'X', 'isbn' => '12345'], 'isbn'],
            'value not numeric' => [['name' => 'X', 'value' => 'ten'], 'value'],
            'value negative' => [['name' => 'X', 'value' => -1], 'value'],
            'value with 3 decimals' => [['name' => 'X', 'value' => 1.234], 'value'],
            'value too large' => [['name' => 'X', 'value' => 100000000], 'value'],
        ];
    }

    public function test_isbn_is_unique_per_owner_not_globally(): void
    {
        Book::factory()->for($this->user)->create(['isbn' => '9780132350884']);
        Book::factory()->create(['isbn' => '9780321125217']); // someone else's book

        $this->postJson('/api/v1/books', ['name' => 'Dup', 'isbn' => '9780132350884'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isbn');

        $this->postJson('/api/v1/books', ['name' => 'Same isbn as another user', 'isbn' => '9780321125217'])
            ->assertCreated();
    }

    public function test_the_database_itself_enforces_isbn_uniqueness_per_owner(): void
    {
        Book::factory()->for($this->user)->create(['isbn' => '9780132350884']);

        $this->expectException(UniqueConstraintViolationException::class);

        Book::factory()->for($this->user)->create(['isbn' => '9780132350884']);
    }

    public function test_a_duplicate_isbn_that_slips_past_validation_is_still_a_422_on_create(): void
    {
        Book::creating(fn () => $this->insertBookBehindTheScenes('9780132350884'));

        $this->postJson('/api/v1/books', ['name' => 'Raced', 'isbn' => '9780132350884'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isbn');

        $this->assertSame(1, $this->user->books()->count());
    }

    public function test_a_duplicate_isbn_that_slips_past_validation_is_still_a_422_on_update(): void
    {
        $book = Book::factory()->for($this->user)->create(['isbn' => '9780321125217']);

        Book::updating(fn () => $this->insertBookBehindTheScenes('9780132350884'));

        $this->patchJson("/api/v1/books/{$book->id}", ['isbn' => '9780132350884'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isbn');

        $this->assertSame('9780321125217', $book->fresh()->isbn);
    }

    private function insertBookBehindTheScenes(string $isbn): void
    {
        Book::flushEventListeners();

        DB::table('books')->insert([
            'user_id' => $this->user->id,
            'name' => 'Inserted by a concurrent request',
            'isbn' => $isbn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_books_without_an_isbn_never_collide(): void
    {
        Book::factory(2)->for($this->user)->create(['isbn' => null]);

        $this->assertSame(2, $this->user->books()->count());
    }

    public function test_an_owned_book_can_be_shown(): void
    {
        $book = Book::factory()->for($this->user)->create();

        $this->getJson("/api/v1/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $book->id);
    }

    public function test_an_unknown_book_is_a_json_404(): void
    {
        $this->getJson('/api/v1/books/999999')->assertNotFound();
    }

    public function test_a_book_can_be_partially_updated(): void
    {
        $book = Book::factory()->for($this->user)->create(['name' => 'Old', 'isbn' => '9780132350884']);

        $this->patchJson("/api/v1/books/{$book->id}", ['name' => 'New', 'value' => 10.5])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.value', '10.50')
            ->assertJsonPath('data.isbn', '9780132350884');
    }

    public function test_updating_a_book_with_its_own_isbn_is_not_a_duplicate(): void
    {
        $book = Book::factory()->for($this->user)->create(['isbn' => '9780132350884']);

        $this->putJson("/api/v1/books/{$book->id}", ['name' => 'Renamed', 'isbn' => '9780132350884', 'value' => null])
            ->assertOk();
    }

    public function test_put_replaces_the_whole_book(): void
    {
        $book = Book::factory()->for($this->user)->create(['isbn' => '9780132350884', 'value' => 12.34]);

        $this->putJson("/api/v1/books/{$book->id}", ['name' => 'Replaced', 'isbn' => null, 'value' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Replaced')
            ->assertJsonPath('data.isbn', null)
            ->assertJsonPath('data.value', null);
    }

    public function test_put_requires_every_field_to_be_present(): void
    {
        $book = Book::factory()->for($this->user)->create();

        $this->putJson("/api/v1/books/{$book->id}", ['name' => 'Only a name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['isbn', 'value']);

        $this->putJson("/api/v1/books/{$book->id}", ['isbn' => null, 'value' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_an_update_cannot_blank_the_name(): void
    {
        $book = Book::factory()->for($this->user)->create();

        $this->patchJson("/api/v1/books/{$book->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_book_can_be_deleted(): void
    {
        $book = Book::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/books/{$book->id}")
            ->assertOk()
            ->assertJson(['message' => 'Book deleted.']);

        $this->assertModelMissing($book);
    }

    public function test_another_users_book_cannot_be_read_updated_or_deleted(): void
    {
        $foreign = Book::factory()->create(['name' => 'Not yours']);

        $this->getJson("/api/v1/books/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/books/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->putJson("/api/v1/books/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/books/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('books', ['id' => $foreign->id, 'name' => 'Not yours']);
    }

    public function test_updating_another_users_book_with_an_invalid_body_still_answers_404(): void
    {
        $foreign = Book::factory()->create();

        $this->patchJson("/api/v1/books/{$foreign->id}", ['name' => ''])->assertNotFound();
    }
}
