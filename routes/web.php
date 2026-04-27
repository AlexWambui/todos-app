<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GuestPagesController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TodoItemController;

Route::get('/', [GuestPagesController::class, 'index'])->name('home');

Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
Route::put('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');

Route::post('/todo-items', [TodoItemController::class, 'store'])->name('todo-items.store');
Route::put('/todo-items/{todoItem}', [TodoItemController::class, 'update'])->name('todo-items.update');
Route::delete('/todo-items/{todoItem}', [TodoItemController::class, 'destroy'])->name('todo-items.destroy');

Route::inertia('/welcome', 'Welcome')->name('welcome');
