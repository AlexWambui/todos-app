<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TodoItem;

class TodoItemController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'todo_id' => ['required', 'exists:todos,id'],
            'parent_id' => ['nullable', 'exists:todo_items,id'],
        ]);

        $todoItem = TodoItem::create([
            'title' => $validated['title'],
            'todo_id' => $validated['todo_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'priority' => 1, // Default medium priority
            'status' => 0,   // Default pending
            'order' => TodoItem::where('todo_id', $validated['todo_id'])
                               ->where('parent_id', $validated['parent_id'] ?? null)
                               ->max('order') + 1,
        ]);

        return redirect()->back()->with('success', 'Todo item created successfully');
    }

    public function update(Request $request, TodoItem $todoItem)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'status' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
        ]);

        $todoItem->update($validated);

        if (isset($validated['status']) && $validated['status'] === 2) {
            $todoItem->update(['completed_at' => now()]);
        } elseif (isset($validated['status']) && $validated['status'] !== 2) {
            $todoItem->update(['completed_at' => null]);
        }

        return redirect()->back()->with('success', 'Todo item updated successfully');
    }

    public function destroy(TodoItem $todoItem)
    {
        $todoItem->delete();
        return redirect()->back()->with('success', 'Todo item deleted successfully');
    }
}