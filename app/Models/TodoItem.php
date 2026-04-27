<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TodoItem extends Model
{
    protected $fillable = [
        'title',
        'priority',
        'status',
        'order',
        'due_date',
        'start_date',
        'completed_at',
        'parent_id',
        'todo_id'
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'start_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TodoItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TodoItem::class, 'parent_id')->orderBy('order');
    }
}