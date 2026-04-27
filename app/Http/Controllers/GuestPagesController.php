<?php

namespace App\Http\Controllers;

use App\Models\Todo;

class GuestPagesController extends Controller
{
    public function index()
    {
        $todos = Todo::with(['todoItems' => function($query) {
            $query->whereNull('parent_id')
                    ->with(['children' => function($q) {
                        $q->with('children'); // sub-items
                    }]);
            }])->orderBy('order')->latest()->get();

        return inertia('guest/Home', [
            'todos' => $todos
        ]);
    }
}