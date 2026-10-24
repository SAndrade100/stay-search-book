<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Book $book): Response
    {
        return $this->owns($user, $book);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Book $book): Response
    {
        return $this->owns($user, $book);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Book $book): Response
    {
        return $this->owns($user, $book);
    }

    private function owns(User $user, Book $book): Response 
    {
        return $user->id === $book->user_id 
            ? Response::allow()
            : Response::denyAsNotFound(); // 404 instead of 403, so the API does not reveal which book ids exists
    }
}
