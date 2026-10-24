<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBookRequest;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class BookController
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexBookRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $direction = $filters['direction'] ?? 'desc';

        $query = $request->user()->books()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->nameContains($search))
                ->orderBy($filters['sort'] ?? 'created_at', $direction)
                ->orderBy('id', $direction);
        
        $perPage = $filters['per_page'] ?? 15;

        $books = ($filters['pagination'] ?? 'page') === 'cursor'
            ? $query->cursorPaginate($perPage)->withQueryString()
            : $query->paginate($perPage)->withQueryString();
        
        return BookResource::collection($books);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookRequest $request): BookResource
    {
        $book = $this->writingIsbn(fn () => $request->user()->books()->create($request->validated()));

        return new BookResource($book);
    }

    /**
     * Display the specified resource.
     */
    public function show(Book $book): BookResource
    {
        return new BookResource($book);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBookRequest $request, Book $book): BookResource
    {
        $this->writingIsbn(fn () => $book->update($request->validated()));

        return new BookResource($book);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book)
    {
        $book->delete();

        return response()->json(['message' => 'Book deleted.']);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private function writingIsbn(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['isbn' => 'You already have a book with this isbn.']);
        }
    }
}
