<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SavedFilter Model
 * 
 * Stores user-specific filter configurations for quick retrieval.
 * 
 * IMPORTANT: This model does NOT have an Eloquent relationship to User.
 * The user_id field is an opaque UUID reference to the accounts service.
 * This is the correct architecture for a multi-service SaaS system where
 * identity (users) and functionality (filters) are owned by different services.
 * 
 * Query saved filters directly by user_id:
 *   SavedFilter::where('user_id', $userId)->get()
 */
class SavedFilter extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'filters',
        'entity_type',
        'is_starred',
        'tags'
    ];

    protected $casts = [
        'filters' => 'array',
        'tags' => 'array',
        'is_starred' => 'boolean',
    ];

    /**
     * Get the user_id as an external reference (no Eloquent relationship)
     * 
     * This is intentionally NOT a belongsTo relationship because:
     * 1. The API service does not own the users table
     * 2. Users are managed by the accounts service
     * 3. This maintains proper service boundaries
     */
    public function getUserIdAttribute($value): string
    {
        return (string) $value;
    }
}
