<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Todo;

class TodoController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        Todo::create([
            'title' => $validated['title'],
            'color' => $validated['color'] ?? '#7C6FF7',
        ]);

        return redirect()->back()->with('success', 'List created successfully');
    }

    public function update(Request $request, Todo $todo)
    {
        $validated = $request->validate([
            'title'    => ['sometimes', 'string', 'max:255'],
            'color'    => ['sometimes', 'string', 'max:20'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'status'   => ['sometimes', 'integer', 'min:0', 'max:3'],
        ]);

        $todo->update($validated);

        return redirect()->back()->with('success', 'List updated successfully');
    }

    public function destroy(Todo $todo)
    {
        $todo->delete();

        return redirect()->back()->with('success', 'List deleted successfully');
    }
}