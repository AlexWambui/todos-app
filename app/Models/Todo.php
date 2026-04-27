<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Todo extends Model
{
    protected $fillable = [
        'title',
        'color',
        'icon',
        'order',
        'priority',
        'status',
        'due_date',
        'start_date',
        'completed_at'
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'start_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function todoItems(): HasMany
    {
        return $this->hasMany(TodoItem::class)->orderBy('order');
    }

    public function rootItems(): HasMany
    {
        return $this->hasMany(TodoItem::class)->whereNull('parent_id')->orderBy('order');
    }
}